<?php

namespace App\Services\Invoicing\Data;

/**
 * Resultado da emissão de uma fatura; corresponde às colunas da tabela `invoices`.
 */
final readonly class IssuedInvoice
{
    public const ISSUED = 'issued';

    /** Fatura emitida (tem número fiscal) mas o envio por email falhou. */
    public const ISSUED_NOT_SENT = 'issued_not_sent';

    /**
     * @param  string  $provider  Driver que emitiu: log ou invoiceexpress.
     * @param  string|null  $providerInvoiceId  Id do documento no serviço (null no driver log).
     * @param  string  $number  Número da fatura.
     * @param  string|null  $pdfUrl  Link público do documento (null no driver log).
     * @param  string  $status  self::ISSUED ou self::ISSUED_NOT_SENT.
     */
    public function __construct(
        public string $provider,
        public ?string $providerInvoiceId,
        public string $number,
        public ?string $pdfUrl,
        public string $status,
    ) {}
}
