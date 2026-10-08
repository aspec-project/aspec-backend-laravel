<?php

namespace App\Contracts;

use App\Exceptions\InvoiceIssuingFailed;
use App\Services\Invoicing\Data\InvoiceCustomerData;
use App\Services\Invoicing\Data\InvoiceData;
use App\Services\Invoicing\Data\IssuedInvoice;

/**
 * Emissão de faturas de pagamentos já cobrados.
 *
 * O driver (InvoiceExpress ou log) é escolhido em `config('services.invoicing.driver')`:
 * sem conta InvoiceExpress o fluxo funciona com o driver log, e trocar para a conta real
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
}
