<?php

namespace App\Services\Invoicing;

use App\Contracts\InvoiceService;
use App\Exceptions\InvoiceIssuingFailed;
use App\Mail\DemoInvoiceMail;
use App\Services\Invoicing\Data\InvoiceCustomerData;
use App\Services\Invoicing\Data\InvoiceData;
use App\Services\Invoicing\Data\IssuedInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Driver de faturação de demonstração: gera um PDF "sem validade fiscal", guarda-o no disco privado
 * (`local`) e envia-o por email ao cliente. Mostra o fluxo completo da fatura sem conta InvoiceExpress.
 *
 * Nunca usa o disco `public`: esse é servido em /storage sem autenticação e o PDF tem NIF e morada.
 * No demo, o "id do documento no serviço" é o caminho do PDF no disco (invoices/{user_id}/{número}.pdf).
 */
class DemoInvoiceService implements InvoiceService
{
    private const DISK = 'local';

    private const FOLDER = 'invoices/';

    /**
     * Gera o PDF da fatura, grava-o e envia-o por email ao cliente.
     *
     * Só EUR (como na InvoiceExpress). O número deriva do id da fatura do Stripe, por isso o job pode
     * repetir sem criar outro documento (reescreve o mesmo ficheiro). Ignora o $draftId: não há rascunhos.
     * Uma falha no email não lança exceção (o PDF já existe): devolve ISSUED_NOT_SENT.
     *
     * @throws InvoiceIssuingFailed Se a moeda não for EUR ou o PDF não puder ser gravado.
     * @throws InvalidArgumentException Se o código do cliente não for um UUID (entra no caminho do ficheiro).
     */
    public function issue(#[\SensitiveParameter] InvoiceCustomerData $customer, InvoiceData $invoice, ?string $draftId = null): IssuedInvoice
    {
        if ($invoice->currency !== 'eur') {
            throw InvoiceIssuingFailed::unsupportedCurrency($invoice->currency);
        }

        if (! Str::isUuid($customer->code)) {
            throw new InvalidArgumentException('Código de cliente inválido para a fatura demo.');
        }

        $number = $this->numberFor($invoice);
        $path = self::FOLDER."{$customer->code}/{$number}.pdf";

        if (! Storage::disk(self::DISK)->put($path, $this->renderPdf($customer, $invoice, $number))) {
            throw InvoiceIssuingFailed::storageFailed();
        }

        return new IssuedInvoice(
            provider: 'demo',
            providerInvoiceId: $path,
            number: $number,
            pdfUrl: null,
            status: $this->sendByEmail($customer, $number, $path),
        );
    }

    /**
     * Devolve o PDF guardado no disco privado.
     *
     * Só aceita caminhos dentro de invoices/ e sem "..", verificados antes de tocar no disco: mesmo
     * sendo um valor gravado por nós, nunca serve outro ficheiro do disco (ex. logos/ ou .env).
     *
     * @return string|null null se o caminho for inválido ou o ficheiro não existir.
     */
    public function pdf(string $providerInvoiceId): ?string
    {
        if (! str_starts_with($providerInvoiceId, self::FOLDER) || str_contains($providerInvoiceId, '..')) {
            return null;
        }

        $contents = Storage::disk(self::DISK)->get($providerInvoiceId);

        if ($contents === null) {
            Log::warning('PDF da fatura demo em falta no disco.', ['path' => $providerInvoiceId]);
        }

        return $contents;
    }

    /**
     * Número derivado do id da fatura do Stripe (DEMO-{ano}-{8 hex}), como no driver log:
     * o mesmo pagamento dá sempre o mesmo número, sem contador.
     */
    private function numberFor(InvoiceData $invoice): string
    {
        $hash = strtoupper(substr(sha1($invoice->stripeInvoiceId), 0, 8));

        return "DEMO-{$invoice->date->format('Y')}-{$hash}";
    }

    /**
     * PDF a partir da vista invoices.demo. Recebe só os dados que vão no documento: nunca o email
     * nem o telefone do cliente (minimização de dados). Só os caracteres usados da fonte são embutidos,
     * para o PDF (guardado e enviado por email) ter dezenas de KB em vez de quase 1 MB.
     */
    private function renderPdf(#[\SensitiveParameter] InvoiceCustomerData $customer, InvoiceData $invoice, string $number): string
    {
        return Pdf::loadView('invoices.demo', [
            'number' => $number,
            'date' => $invoice->date->format('d/m/Y'),
            'description' => $invoice->description,
            'total' => number_format($invoice->amount / 100, 2, ',', '.').' €',
            'reference' => $invoice->stripeInvoiceId,
            'customer' => [
                'name' => $customer->name,
                'nif' => $customer->nif,
                'address' => $customer->address,
                'postal_code' => $customer->postalCode,
                'city' => $customer->city,
            ],
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->output();
    }

    /**
     * Envia o PDF por email, de forma síncrona. Nunca lança exceção: a fatura já foi gerada e uma
     * exceção faria o job repetir. A falha fica no log só com o número, para reenviar à mão.
     *
     * @return string IssuedInvoice::ISSUED ou IssuedInvoice::ISSUED_NOT_SENT.
     */
    private function sendByEmail(#[\SensitiveParameter] InvoiceCustomerData $customer, string $number, string $path): string
    {
        try {
            Mail::to($customer->email)->send(new DemoInvoiceMail($number, $path));

            return IssuedInvoice::ISSUED;
        } catch (Throwable) {
            Log::warning('Fatura demo emitida mas não enviada por email.', ['number' => $number]);

            return IssuedInvoice::ISSUED_NOT_SENT;
        }
    }
}
