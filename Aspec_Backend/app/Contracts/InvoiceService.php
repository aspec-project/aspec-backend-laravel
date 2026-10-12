<?php

namespace App\Contracts;

use App\Exceptions\InvoiceIssuingFailed;
use App\Exceptions\InvoicePdfUnavailable;
use App\Services\Invoicing\Data\InvoiceCustomerData;
use App\Services\Invoicing\Data\InvoiceData;
use App\Services\Invoicing\Data\IssuedInvoice;

/**
 * Emissão de faturas de pagamentos já cobrados.
 *
 * O driver (log, demo ou invoiceexpress) é escolhido em `config('services.invoicing.driver')`:
 * sem conta InvoiceExpress o fluxo funciona com o driver log ou demo, e trocar para a conta real
 * é só mudar o `.env`.
 */
interface InvoiceService
{
    /**
     * Emite a fatura do pagamento e envia-a ao cliente.
     *
     * @param  string|null  $draftId  Id do rascunho criado numa tentativa anterior: retoma a partir
     *                                dele em vez de criar outro (dois rascunhos seriam duas faturas).
     *
     * @throws InvoiceIssuingFailed Se a fatura não chegar a ser emitida.
     */
    public function issue(#[\SensitiveParameter] InvoiceCustomerData $customer, InvoiceData $invoice, ?string $draftId = null): IssuedInvoice;

    /**
     * Devolve o PDF (bytes) de uma fatura emitida por este driver.
     *
     * @param  string  $providerInvoiceId  Id do documento no serviço (invoices.provider_invoice_id).
     * @return string|null null se este driver não tiver PDF para o id (ex. driver log, ficheiro em falta).
     *
     * @throws InvoicePdfUnavailable Se o serviço de faturação falhar ao entregar o PDF.
     */
    public function pdf(string $providerInvoiceId): ?string;
}
