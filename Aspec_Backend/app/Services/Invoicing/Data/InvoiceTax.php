<?php

namespace App\Services\Invoicing\Data;

use App\Exceptions\InvalidInvoiceTax;

/**
 * Imposto aplicado a uma fatura: taxa de IVA e, quando a fatura é isenta (taxa 0 %), o motivo.
 *
 * A quota da ASPEC é isenta de IVA, mas o caminho com IVA continua possível. O valor cobrado pelo
 * Stripe é sempre o total: a base e o imposto calculam-se a partir dele e somam exatamente esse valor.
 */
final readonly class InvoiceTax
{
    /** Código do motivo de isenção da AT (tabela "Códigos de Motivo de Isenção"), ex. M07. */
    private const EXEMPTION_CODE_PATTERN = '/^M\d{2}\z/';

    public ?string $exemptionReason;

    /**
     * Valida a combinação taxa/motivo. Com IVA (> 0 %) o motivo de isenção é descartado.
     *
     * @param  int  $rate  Taxa de IVA em percentagem, de 0 a 100.
     * @param  string|null  $exemptionCode  Código da AT (M + 2 dígitos); só em faturas isentas.
     * @param  string|null  $exemptionReason  Texto do motivo, obrigatório quando isenta (até 255 caracteres).
     *
     * @throws InvalidInvoiceTax Se a taxa, o código ou o motivo forem inválidos.
     */
    public function __construct(
        public int $rate,
        public ?string $exemptionCode,
        ?string $exemptionReason,
    ) {
        if ($rate < 0 || $rate > 100) {
            throw new InvalidInvoiceTax('A taxa de IVA tem de estar entre 0 e 100.');
        }

        if ($exemptionCode !== null && ! preg_match(self::EXEMPTION_CODE_PATTERN, $exemptionCode)) {
            throw new InvalidInvoiceTax('Código de isenção de IVA inválido (formato M00).');
        }

        if ($rate > 0 && $exemptionCode !== null) {
            throw new InvalidInvoiceTax('O código de isenção só se aplica a faturas sem IVA.');
        }

        if ($rate === 0 && ($exemptionReason === null || trim($exemptionReason) === '')) {
            throw new InvalidInvoiceTax('Uma fatura sem IVA precisa do motivo de isenção.');
        }

        if ($rate === 0 && mb_strlen($exemptionReason) > 255) {
            throw new InvalidInvoiceTax('O motivo de isenção de IVA não pode ter mais de 255 caracteres.');
        }

        $this->exemptionReason = $rate === 0 ? $exemptionReason : null;
    }

    /**
     * Imposto configurado em services.invoicing (comum a todos os drivers). Um código vazio passa a null.
     *
     * @throws InvalidInvoiceTax Se a configuração for inválida.
     */
    public static function fromConfig(): self
    {
        $code = config('services.invoicing.vat_exemption_code');

        return new self(
            rate: (int) config('services.invoicing.vat_rate'),
            exemptionCode: filled($code) ? (string) $code : null,
            exemptionReason: config('services.invoicing.vat_exemption_reason'),
        );
    }

    /**
     * Fatura sem IVA (taxa 0 %).
     */
    public function isExempt(): bool
    {
        return $this->rate === 0;
    }

    /**
     * Base tributável em cêntimos, a partir do valor cobrado (com isenção, é o próprio valor).
     */
    public function netAmount(int $gross): int
    {
        return (int) round($gross / (1 + $this->rate / 100));
    }

    /**
     * IVA em cêntimos: valor cobrado menos a base, para base + IVA darem sempre o valor cobrado.
     */
    public function taxAmount(int $gross): int
    {
        return $gross - $this->netAmount($gross);
    }
}
