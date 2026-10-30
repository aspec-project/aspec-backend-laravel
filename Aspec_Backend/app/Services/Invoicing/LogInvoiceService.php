<?php

namespace App\Services\Invoicing;

use App\Contracts\InvoiceService;
use App\Services\Invoicing\Data\InvoiceCustomerData;
use App\Services\Invoicing\Data\InvoiceData;
use App\Services\Invoicing\Data\IssuedInvoice;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Driver de faturação sem serviço externo: só regista a emissão no log.
 * Serve enquanto a ASPEC não tiver conta InvoiceExpress (desenvolvimento, testes e demonstração).
 */
class LogInvoiceService implements InvoiceService
{
    /**
     * Regista a fatura no log e devolve um número fictício.
     *
     * O log tem só o id da fatura do Stripe, o valor, a moeda, a taxa de IVA, o código de isenção e
     * o número: nunca nome, NIF, morada nem email, porque os logs não são sítio para dados pessoais.
     * Ignora o $draftId e o $onDraftCreated: sem serviço externo não há rascunhos, e o número já é sempre o mesmo.
     */
    public function issue(#[\SensitiveParameter] InvoiceCustomerData $customer, InvoiceData $invoice, ?string $draftId = null, ?Closure $onDraftCreated = null): IssuedInvoice
    {
        $number = $this->numberFor($invoice);

        Log::info('Fatura emitida (driver log).', [
            'stripe_invoice_id' => $invoice->stripeInvoiceId,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'vat_rate' => $invoice->tax->rate,
            'vat_exemption_code' => $invoice->tax->exemptionCode,
            'number' => $number,
        ]);

        return new IssuedInvoice(
            provider: 'log',
            providerInvoiceId: null,
            number: $number,
            pdfUrl: null,
            status: IssuedInvoice::ISSUED,
        );
    }

    /**
     * Número derivado do id da fatura do Stripe (LOG-{ano}-{8 hex}). Sem tabela não há sequência;
     * assim o mesmo pagamento dá sempre o mesmo número, mesmo que o job repita.
     */
    private function numberFor(InvoiceData $invoice): string
    {
        $hash = strtoupper(substr(sha1($invoice->stripeInvoiceId), 0, 8));

        return "LOG-{$invoice->date->format('Y')}-{$hash}";
    }

    /**
     * O driver log não gera documentos: nunca há PDF.
     */
    public function pdf(string $providerInvoiceId): ?string
    {
        return null;
    }
}
