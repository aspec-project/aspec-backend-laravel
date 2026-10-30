<?php

namespace App\Services\Invoicing;

use App\Contracts\InvoiceService;
use App\Exceptions\InvoiceIssuingFailed;
use App\Exceptions\InvoicePdfUnavailable;
use App\Services\Invoicing\Data\InvoiceCustomerData;
use App\Services\Invoicing\Data\InvoiceData;
use App\Services\Invoicing\Data\IssuedInvoice;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
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

    /** Pedidos ao /api/pdf enquanto a InvoiceExpress responde 202 (PDF ainda a ser gerado). */
    private const PDF_ATTEMPTS = 3;

    /** Tempo máximo, em segundos, de cada pedido do PDF (corre dentro de um pedido do membro). */
    private const PDF_TIMEOUT = 5;

    /** Tamanho máximo aceite para o PDF de uma fatura (10 MB). */
    private const PDF_MAX_BYTES = 10 * 1024 * 1024;

    /**
     * @param  string  $documentType  Tipo de documento na API (por omissão invoice_receipts).
     * @param  string|null  $sequenceId  Série; null usa a série por omissão da conta.
     * @param  string  $taxName  Nome da taxa de IVA na conta InvoiceExpress (ex. IVA0); tem de existir lá.
     * @param  int  $timeout  Tempo máximo de cada pedido, em segundos.
     */
    public function __construct(
        private string $accountName,
        #[\SensitiveParameter] private string $apiKey,
        private string $documentType,
        private ?string $sequenceId,
        private string $itemName,
        private string $taxName,
        private int $timeout,
        private int $retryTimes,
        private int $retrySleepMs,
    ) {}

    /**
     * Emite a fatura-recibo na InvoiceExpress e envia-a por email ao cliente.
     *
     * Só EUR: a conta InvoiceExpress fatura em euros e noutra moeda o valor sairia errado.
     * Uma fatura isenta precisa do código de isenção (a menção legal sai dele): sem código, falha antes
     * de qualquer pedido. O rascunho só é finalizado se o total calculado pela InvoiceExpress for o valor
     * cobrado (até 1 cêntimo de diferença com IVA, por arredondamento; exato quando isenta).
     * Sem número fiscal depois de finalizar falha antes do email (não há fatura para enviar nem gravar).
     * Depois de finalizada nunca lança exceção: se o email falhar devolve ISSUED_NOT_SENT.
     * Com $draftId não cria rascunho: lê o da tentativa anterior, confirma o total e finaliza-o (se já
     * estiver finalizado, segue para o email).
     *
     * @param  string|null  $draftId  Id do rascunho de uma tentativa anterior que falhou a finalizar.
     * @param  (Closure(string): void)|null  $onDraftCreated  Recebe o id do rascunho logo que é criado, antes
     *                                                        de confirmar o total e de finalizar, para o job o
     *                                                        guardar: uma nova tentativa retoma sempre este
     *                                                        rascunho e nunca emite uma segunda fatura fiscal.
     *
     * @throws InvoiceIssuingFailed Se a moeda não for EUR, faltar o código de isenção, o total não
     *                              corresponder ao valor cobrado ou a criação ou a finalização falharem.
     *                              Nas falhas a finalizar, a exceção traz o id do rascunho
     *                              (providerInvoiceId) para a nova tentativa não criar outro.
     */
    public function issue(#[\SensitiveParameter] InvoiceCustomerData $customer, InvoiceData $invoice, ?string $draftId = null, ?Closure $onDraftCreated = null): IssuedInvoice
    {
        if ($invoice->currency !== 'eur') {
            throw InvoiceIssuingFailed::unsupportedCurrency($invoice->currency);
        }

        if ($invoice->tax->isExempt() && $invoice->tax->exemptionCode === null) {
            throw InvoiceIssuingFailed::missingExemptionCode();
        }

        $documentId = $draftId ?? $this->createDraft($customer, $invoice, $onDraftCreated);
        $document = $draftId === null ? $this->finalize($documentId) : $this->resumeDraft($documentId, $invoice);

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
     * Pede o PDF do documento à InvoiceExpress e descarrega-o do link que ela devolve.
     *
     * 202 quer dizer que o PDF ainda está a ser gerado: repete até 3 vezes, com 1 s de espera.
     * O link devolvido tem de ser https e o ficheiro tem de ser um PDF até 10 MB. O download é feito
     * sem a api_key: o link é externo e já vem assinado pela InvoiceExpress.
     * Corre dentro de um pedido do membro: sem novas tentativas automáticas, timeouts de 5 s e sem
     * seguir redirecionamentos, o pior caso (3 × 5 s + 2 × 1 s + 5 s) fica abaixo do max_execution_time.
     *
     * @param  string  $providerInvoiceId  Id numérico do documento na InvoiceExpress.
     * @return string Bytes do PDF (este driver nunca devolve null).
     *
     * @throws InvalidArgumentException Se o id não for numérico (entra no caminho do URL), antes de qualquer pedido.
     * @throws InvoicePdfUnavailable Se a InvoiceExpress falhar, continuar a gerar o PDF ou devolver algo que não serve.
     */
    public function pdf(string $providerInvoiceId): ?string
    {
        if (! ctype_digit($providerInvoiceId)) {
            throw new InvalidArgumentException('Id de documento InvoiceExpress inválido.');
        }

        return $this->download($this->pdfUrl($providerInvoiceId));
    }

    /**
     * Link temporário do PDF devolvido pelo /api/pdf.
     * Qualquer exceção do cliente HTTP é trocada por uma sem `previous`: a original tem o URL com a api_key.
     */
    private function pdfUrl(string $documentId): string
    {
        for ($attempt = 1; $attempt <= self::PDF_ATTEMPTS; $attempt++) {
            try {
                $response = $this->pdfClient()
                    ->baseUrl("https://{$this->accountName}.app.invoicexpress.com")
                    ->acceptJson()
                    ->withQueryParameters(['api_key' => $this->apiKey])
                    ->get("/api/pdf/{$documentId}.json");
            } catch (Throwable) {
                throw InvoicePdfUnavailable::unreachable('request');
            }

            if ($response->status() !== 202) {
                break;
            }

            if ($attempt < self::PDF_ATTEMPTS) {
                Sleep::for(1)->second();
            }
        }

        if ($response->status() === 202) {
            throw InvoicePdfUnavailable::stillGenerating();
        }

        $url = $response->json('output.pdfUrl');

        if ($response->status() !== 200 || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw InvoicePdfUnavailable::rejected('request', $response->status());
        }

        return $url;
    }

    /**
     * Descarrega o PDF do link da InvoiceExpress, sem a api_key. Um redirecionamento (3xx) é recusado,
     * tal como um ficheiro acima de 10 MB (pelo Content-Length, quando existe, e pelo corpo recebido).
     * Nunca regista o URL: é um link público e assinado para o documento fiscal.
     */
    private function download(string $url): string
    {
        try {
            $response = $this->pdfClient()->get($url);
        } catch (Throwable) {
            throw InvoicePdfUnavailable::unreachable('download');
        }

        $declaredLength = $response->header('Content-Length');
        $body = $response->body();

        if (! $response->successful()
            || ($declaredLength !== '' && (int) $declaredLength > self::PDF_MAX_BYTES)
            || strlen($body) > self::PDF_MAX_BYTES
            || ! str_starts_with($body, '%PDF-')) {
            throw InvoicePdfUnavailable::rejected('download', $response->status());
        }

        return $body;
    }

    /**
     * Cliente HTTP dos pedidos do PDF: timeout curto, sem novas tentativas automáticas e sem seguir
     * redirecionamentos (um 3xx levaria o pedido, e no /api/pdf a api_key, para outro endereço).
     */
    private function pdfClient(): PendingRequest
    {
        return Http::timeout(self::PDF_TIMEOUT)
            ->connectTimeout(self::PDF_TIMEOUT)
            ->withOptions(['allow_redirects' => false]);
    }

    /**
     * Cria a fatura em rascunho e devolve o id do documento.
     * Sem série ou sem isenção a chave não é enviada: a InvoiceExpress usa a série por omissão da
     * conta e só aceita motivo de isenção em faturas sem IVA. O texto livre do motivo nunca é enviado:
     * na fatura fiscal a menção legal sai do código e um texto ao lado podia contradizê-la.
     * O id é entregue ao $onDraftCreated antes de confirmar o total: com um total errado, as novas
     * tentativas voltam a este rascunho e falham da mesma forma (nunca o finalizam), sem criar outros.
     */
    private function createDraft(#[\SensitiveParameter] InvoiceCustomerData $customer, InvoiceData $invoice, ?Closure $onDraftCreated): string
    {
        $date = $invoice->date->format('d/m/Y');

        $payload = array_filter([
            'date' => $date,
            'due_date' => $date,
            'reference' => $invoice->stripeInvoiceId,
            'sequence_id' => $this->sequenceId,
            'tax_exemption' => $invoice->tax->exemptionCode,
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
                'unit_price' => $this->netUnitPrice($invoice),
                'quantity' => '1',
                'tax' => ['name' => $this->taxName],
            ]],
        ], fn ($value) => filled($value));

        $response = $this->send('create', null, fn () => $this->client()->post("/{$this->documentType}.json", ['invoice' => $payload]));

        $document = $this->documentFrom($response);
        $documentId = $document['id'] ?? null;

        if ($response->failed() || blank($documentId)) {
            throw InvoiceIssuingFailed::rejected('create', $response->status());
        }

        if ($onDraftCreated) {
            $onDraftCreated((string) $documentId);
        }

        $this->ensureTotalMatches($document, $invoice);

        return (string) $documentId;
    }

    /**
     * Retoma o rascunho de uma tentativa anterior: lê-o, confirma o total e finaliza-o.
     * Um documento já finalizado é devolvido como está: já tem número fiscal e falhar aqui levava a
     * nova tentativa a emitir uma segunda fatura.
     */
    private function resumeDraft(string $documentId, InvoiceData $invoice): array
    {
        $document = $this->fetchDocument($documentId);

        if (in_array($document['status'] ?? null, self::FINAL_STATUSES, true)) {
            return $document;
        }

        $this->ensureTotalMatches($document, $invoice);

        return $this->finalize($documentId);
    }

    /**
     * Confirma que o total do documento (base + IVA, calculado pela InvoiceExpress) é o valor cobrado.
     * Com IVA aceita 1 cêntimo de diferença (a InvoiceExpress arredonda base × taxa); isenta, tem de ser
     * igual. Protege contra uma taxa errada na conta (ex. IVA23 numa quota isenta: 36,90 € por 30 €).
     *
     * @throws InvoiceIssuingFailed Se o total for diferente ou não vier na resposta.
     */
    private function ensureTotalMatches(array $document, InvoiceData $invoice): void
    {
        $total = $document['total'] ?? null;

        if (! is_numeric($total)) {
            throw InvoiceIssuingFailed::totalMismatch();
        }

        $tolerance = $invoice->tax->isExempt() ? 0 : 1;

        if (abs((int) round((float) $total * 100) - $invoice->amount) > $tolerance) {
            throw InvoiceIssuingFailed::totalMismatch();
        }
    }

    /**
     * Finaliza o rascunho e devolve o documento (com sequence_number e permalink).
     * Um 422 pode querer dizer que o documento já está finalizado (ex.: um pedido anterior deu
     * timeout mas foi aplicado): confirma-se o estado em vez de falhar logo.
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

        if ($response->status() === 422) {
            $document = $this->fetchDocument($documentId);

            if (in_array($document['status'] ?? null, self::FINAL_STATUSES, true)) {
                return $document;
            }
        }

        throw InvoiceIssuingFailed::rejected('finalize', $response->status(), $documentId);
    }

    /**
     * Lê o documento: para confirmar o estado depois de uma finalização recusada ou para retomar um
     * rascunho. Uma falha mantém o id do rascunho, como as falhas a finalizar.
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
     * Nunca lança exceção: a fatura já tem número fiscal e qualquer exceção faria o job repetir e
     * emitir uma segunda. Uma falha fica no log só com ids e código HTTP (a mensagem da exceção pode
     * ter o URL com a api_key), para reenviar à mão.
     */
    private function sendByEmail(IssuedInvoice $issued, #[\SensitiveParameter] InvoiceCustomerData $customer): IssuedInvoice
    {
        try {
            $response = $this->client()->put(
                "/{$this->documentType}/{$issued->providerInvoiceId}/email-document.json",
                ['message' => [
                    'client' => ['email' => $customer->email, 'save' => '0'],
                    'subject' => "Fatura {$issued->number} — ASPEC",
                    'body' => 'Segue em anexo a fatura da sua quota da ASPEC.',
                ]],
            );

            if ($response->successful()) {
                return $issued;
            }

            $httpStatus = $response->status();
        } catch (Throwable) {
            $httpStatus = null;
        }

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
    private function netUnitPrice(InvoiceData $invoice): string
    {
        return number_format($invoice->tax->netAmount($invoice->amount) / 100, 2, '.', '');
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
