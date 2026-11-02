<?php

namespace App\Services\Invoicing\Data;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Pagamento a faturar, tal como foi cobrado pelo Stripe.
 */
final readonly class InvoiceData
{
    /**
     * Rejeita valores que nunca deviam chegar a uma fatura.
     *
     * @param  int  $amount  Valor total cobrado pelo Stripe, em cêntimos (base + IVA; com isenção, igual à base).
     * @param  string  $currency  Código da moeda em minúsculas, como o Stripe o envia (ex. "eur").
     * @param  DateTimeImmutable  $date  Data do pagamento.
     * @param  InvoiceTax  $tax  Imposto aplicado (taxa de IVA e, se isenta, o motivo).
     *
     * @throws InvalidArgumentException Se o valor não for positivo ou a moeda não tiver 3 letras.
     */
    public function __construct(
        public string $stripeInvoiceId,
        public int $amount,
        public string $currency,
        public string $description,
        public DateTimeImmutable $date,
        public InvoiceTax $tax,
    ) {
        if ($amount <= 0) {
            throw new InvalidArgumentException('O valor da fatura tem de ser positivo.');
        }

        if (! preg_match('/^[a-z]{3}$/', $currency)) {
            throw new InvalidArgumentException('A moeda tem de ser um código de 3 letras minúsculas.');
        }
    }
}
