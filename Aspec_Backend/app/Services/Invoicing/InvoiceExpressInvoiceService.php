<?php

namespace App\Services\Invoicing;

use App\Contracts\InvoiceService;
use App\Exceptions\InvoiceIssuingFailed;
use App\Services\Invoicing\Data\InvoiceCustomerData;
use App\Services\Invoicing\Data\InvoiceData;
use App\Services\Invoicing\Data\IssuedInvoice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Driver de faturação da InvoiceExpress: cria a fatura-recibo em rascunho, finaliza-a
 * (é aqui que recebe o número fiscal) e pede à InvoiceExpress que a envie por email.
 *
 * Recebe a configuração no construtor (passada pelo AppServiceProvider), não lê config().
 */
class InvoiceExpressInvoiceService implements InvoiceService
{
    /** Estados de um documento já finalizado (uma fatura-recibo pode ficar logo "settled"). */
    private const FINAL_STATUSES = ['final', 'settled'];

    /**
     * @param  string  $documentType  Tipo de documento na API (por omissão invoice_receipts).
     * @param  string|null  $sequenceId  Série; null usa a série por omissão da conta.
     * @param  int  $vatRate  Taxa de IVA em percentagem (23, ou 0 se isento).
     * @param  string|null  $taxExemption  Código do motivo de isenção (ex. M07), só quando isento.
     * @param  int  $timeout  Tempo máximo de cada pedido, em segundos.
     */
    public function __construct(
        private string $accountName,
        #[\SensitiveParameter] private string $apiKey,
        private string $documentType,
        private ?string $sequenceId,
        private string $itemName,
        private string $taxName,
        private int $vatRate,
        private ?string $taxExemption,
        private int $timeout,
        private int $retryTimes,
        private int $retrySleepMs,
    ) {}

    /**
     * Emite a fatura-recibo na InvoiceExpress e envia-a por email ao cliente.
     *
     * Depois de finalizada nunca lança exceção: se o email falhar devolve ISSUED_NOT_SENT.
     *
     * @throws InvoiceIssuingFailed Se a moeda não for EUR ou se a criação ou a finalização falharem.
     *                              Nas falhas a finalizar, a exceção traz o id do rascunho
     *                              (providerInvoiceId) para a nova tentativa não criar outro.
     */
    public function issue(#[\SensitiveParameter] InvoiceCustomerData $customer, InvoiceData $invoice): IssuedInvoice
    {
        // A conta InvoiceExpress fatura em EUR: noutra moeda o valor da fatura sairia errado.
        if ($invoice->currency !== 'eur') {
            throw InvoiceIssuingFailed::unsupportedCurrency($invoice->currency);
        }

        $documentId = $this->createDraft($customer, $invoice);
        $document = $this->finalize($documentId);

        // Sem número fiscal não há fatura para enviar nem para gravar: falha antes do email.
        $number = $document['sequence_number'] ?? null;

        if (blank($number)) {
            throw InvoiceIssuingFailed::rejected('finalize', 200, $documentId);
        }

        $issued = new IssuedInvoice(
            provider: 'invoiceexpress',
            providerInvoiceId: $documentId,
            number: (string) $number,
            pdfUrl: $document['permalink'] ?? null,
            status: IssuedInvoice::ISSUED,
        );

        return $this->sendByEmail($issued, $customer);
    }

    /**
     * Cria a fatura em rascunho e devolve o id do documento.
     */
    private function createDraft(#[\SensitiveParameter] InvoiceCustomerData $customer, InvoiceData $invoice): string
    {
        $date = $invoice->date->format('d/m/Y');

        // Sem série ou sem isenção configuradas, a chave não vai: a InvoiceExpress usa a série
        // por omissão da conta e só aceita motivo de isenção em faturas sem IVA.
        $payload = array_filter([
            'date' => $date,
            'due_date' => $date,
            'reference' => $invoice->stripeInvoiceId,
            'sequence_id' => $this->sequenceId,
            'tax_exemption' => $this->taxExemption,
            'client' => [
                'name' => $customer->name,
                'code' => $customer->code,
                'fiscal_id' => $customer->nif,
                'address' => $customer->address,
                'postal_code' => $customer->postalCode,
                'city' => $customer->city,
                'country' => 'Portugal',
                'email' => $customer->email,
            ],
            'items' => [[
                'name' => $this->itemName,
                'description' => $invoice->description,
                'unit_price' => $this->netUnitPrice($invoice->amount),
                'quantity' => '1',
                'tax' => ['name' => $this->taxName],
            ]],
        ], fn ($value) => filled($value));

        $response = $this->send('create', null, fn () => $this->client()->post("/{$this->documentType}.json", ['invoice' => $payload]));

        $documentId = $this->documentFrom($response)['id'] ?? null;

        if ($response->failed() || blank($documentId)) {
            throw InvoiceIssuingFailed::rejected('create', $response->status());
        }

        return (string) $documentId;
    }

    /**
     * Finaliza o rascunho e devolve o documento (com sequence_number e permalink).
     */
    private function finalize(string $documentId): array
    {
        $response = $this->send('finalize', $documentId, fn () => $this->client()->put(
            "/{$this->documentType}/{$documentId}/change-state.json",
            ['invoice' => ['state' => 'finalized']],
        ));

        if ($response->successful()) {
            return $this->documentFrom($response);
        }

        // Um 422 pode querer dizer que o documento já está finalizado (ex.: um pedido anterior
        // deu timeout mas foi aplicado). Confirma-se o estado em vez de falhar logo.
        if ($response->status() === 422) {
            $document = $this->fetchDocument($documentId);

            if (in_array($document['status'] ?? null, self::FINAL_STATUSES, true)) {
                return $document;
            }
        }

        throw InvoiceIssuingFailed::rejected('finalize', $response->status(), $documentId);
    }

    /**
     * Lê o documento para confirmar o estado depois de uma finalização recusada.
     */
    private function fetchDocument(string $documentId): array
    {
        $response = $this->send('finalize', $documentId, fn () => $this->client()->get("/{$this->documentType}/{$documentId}.json"));

        if ($response->failed()) {
            throw InvoiceIssuingFailed::rejected('finalize', $response->status(), $documentId);
        }

        return $this->documentFrom($response);
    }

    /**
     * Pede à InvoiceExpress que envie a fatura ao email de faturação do cliente.
     */
    private function sendByEmail(IssuedInvoice $issued, #[\SensitiveParameter] InvoiceCustomerData $customer): IssuedInvoice
    {
        try {
            $response = $this->client()->put(
                "/{$this->documentType}/{$issued->providerInvoiceId}/email-document.json",
                ['message' => [
                    'client' => ['email' => $customer->email, 'save' => '0'],
                    'subject' => "Fatura {$issued->number} — ASPEC",
                    'body' => 'Segue em anexo a fatura da sua quota mensal da ASPEC.',
                ]],
            );

            if ($response->successful()) {
                return $issued;
            }

            $httpStatus = $response->status();
        } catch (Throwable) {
            $httpStatus = null;
        }

        // A fatura já tem número fiscal: qualquer exceção (não só a falta de ligação) faria o job
        // repetir e emitir uma segunda fatura. Fica registado só com ids e código HTTP (a mensagem
        // da exceção pode ter o URL com a api_key) para reenviar à mão.
        Log::warning('Fatura emitida mas não enviada por email (InvoiceExpress).', [
            'step' => 'email',
            'http_status' => $httpStatus,
            'provider_invoice_id' => $issued->providerInvoiceId,
        ]);

        return new IssuedInvoice(
            provider: $issued->provider,
            providerInvoiceId: $issued->providerInvoiceId,
            number: $issued->number,
            pdfUrl: $issued->pdfUrl,
            status: IssuedInvoice::ISSUED_NOT_SENT,
        );
    }

    /**
     * Preço unitário sem IVA, para o total da fatura (com IVA) bater certo com o valor cobrado.
     */
    private function netUnitPrice(int $amount): string
    {
        $net = (int) round($amount / (1 + $this->vatRate / 100));

        return number_format($net / 100, 2, '.', '');
    }

    /**
     * Faz o pedido e troca a ConnectionException por uma exceção de domínio sem `previous`:
     * a original tem o URL com a api_key na mensagem.
     *
     * @param  string|null  $documentId  Id do rascunho, quando já existe (passo finalize).
     * @param  callable(): Response  $request
     */
    private function send(string $step, ?string $documentId, callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw InvoiceIssuingFailed::unreachable($step, $documentId);
        }
    }

    /**
     * A raiz da resposta tem o nome do tipo de documento (ex. invoice_receipt), por isso
     * lê-se o primeiro elemento em vez de uma chave fixa.
     */
    private function documentFrom(Response $response): array
    {
        return (array) Arr::first((array) $response->json());
    }

    /**
     * Cliente HTTP da conta, com timeouts curtos para o worker da fila não ficar preso e novas
     * tentativas só para erros transitórios (sem ligação ou 5xx): um 4xx repetido dá sempre o mesmo erro.
     */
    private function client(): PendingRequest
    {
        return Http::baseUrl("https://{$this->accountName}.app.invoicexpress.com")
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout(5)
            ->withQueryParameters(['api_key' => $this->apiKey])
            ->retry(
                $this->retryTimes,
                $this->retrySleepMs,
                fn (Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()),
                throw: false,
            );
    }
}
