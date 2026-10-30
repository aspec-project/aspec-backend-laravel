<?php

namespace Tests\Unit\Invoicing;

use App\Services\Invoicing\Data\InvoiceTax;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Imposto da fatura: a quota é isenta de IVA (taxa 0 %, com motivo), mas o caminho com IVA
 * continua possível. Base + IVA somam sempre o valor cobrado pelo Stripe.
 * Usa o TestCase da aplicação só por causa do fromConfig().
 */
class InvoiceTaxTest extends TestCase
{
    private const REASON = 'Isento de IVA — motivo de teste';

    #[Test]
    public function exempt_tax_keeps_the_full_amount_as_the_base(): void
    {
        $tax = new InvoiceTax(rate: 0, exemptionCode: null, exemptionReason: self::REASON);

        $this->assertTrue($tax->isExempt());
        $this->assertSame(3000, $tax->netAmount(3000));
        $this->assertSame(0, $tax->taxAmount(3000));
        $this->assertSame(self::REASON, $tax->exemptionReason);
        $this->assertNull($tax->exemptionCode);
    }

    #[Test]
    public function exempt_tax_keeps_a_valid_exemption_code(): void
    {
        $tax = new InvoiceTax(rate: 0, exemptionCode: 'M07', exemptionReason: self::REASON);

        $this->assertSame('M07', $tax->exemptionCode);
        $this->assertSame(0, $tax->rate);
    }

    public static function taxedAmounts(): array
    {
        return [
            '30 €' => [3000, 2439, 561],
            '60 €' => [6000, 4878, 1122],
        ];
    }

    #[Test]
    #[DataProvider('taxedAmounts')]
    public function vat_23_splits_the_amount_into_base_and_tax_that_add_up_to_the_charge(int $gross, int $net, int $vat): void
    {
        $tax = new InvoiceTax(rate: 23, exemptionCode: null, exemptionReason: null);

        $this->assertFalse($tax->isExempt());
        $this->assertSame($net, $tax->netAmount($gross));
        $this->assertSame($vat, $tax->taxAmount($gross));
        $this->assertSame($gross, $tax->netAmount($gross) + $tax->taxAmount($gross));
    }

    #[Test]
    public function rate_100_is_accepted(): void
    {
        $tax = new InvoiceTax(rate: 100, exemptionCode: null, exemptionReason: null);

        $this->assertSame(1500, $tax->netAmount(3000));
        $this->assertSame(1500, $tax->taxAmount(3000));
    }

    #[Test]
    public function taxed_invoice_drops_the_exemption_reason(): void
    {
        $tax = new InvoiceTax(rate: 23, exemptionCode: null, exemptionReason: self::REASON);

        $this->assertNull($tax->exemptionReason);
    }

    #[Test]
    public function reason_with_255_characters_is_accepted(): void
    {
        $reason = str_repeat('a', 255);

        $this->assertSame($reason, (new InvoiceTax(rate: 0, exemptionCode: null, exemptionReason: $reason))->exemptionReason);
    }

    public static function invalidTaxes(): array
    {
        return [
            'taxa negativa' => [-1, null, self::REASON],
            'taxa acima de 100' => [101, null, null],
            'isento sem motivo' => [0, null, null],
            'isento com motivo vazio' => [0, null, ''],
            'isento com motivo só com espaços' => [0, null, '   '],
            'isento com motivo de 256 caracteres' => [0, null, str_repeat('a', 256)],
            'código com um dígito' => [0, 'M7', self::REASON],
            'código em minúsculas' => [0, 'm07', self::REASON],
            'código sem M' => [0, 'X07', self::REASON],
            'código com três dígitos' => [0, 'M070', self::REASON],
            'código com quebra de linha' => [0, "M07\n", self::REASON],
            'IVA 23 % com código de isenção' => [23, 'M07', null],
        ];
    }

    #[Test]
    #[DataProvider('invalidTaxes')]
    public function invalid_tax_is_rejected(int $rate, ?string $code, ?string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InvoiceTax(rate: $rate, exemptionCode: $code, exemptionReason: $reason);
    }

    #[Test]
    public function taxed_invoice_with_an_exemption_code_has_a_clear_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('O código de isenção só se aplica a faturas sem IVA.');

        new InvoiceTax(rate: 23, exemptionCode: 'M07', exemptionReason: null);
    }

    // --- fromConfig ---

    #[Test]
    public function from_config_reads_the_neutral_invoicing_keys(): void
    {
        config([
            'services.invoicing.vat_rate' => 0,
            'services.invoicing.vat_exemption_code' => 'M07',
            'services.invoicing.vat_exemption_reason' => self::REASON,
        ]);

        $tax = InvoiceTax::fromConfig();

        $this->assertSame(0, $tax->rate);
        $this->assertSame('M07', $tax->exemptionCode);
        $this->assertSame(self::REASON, $tax->exemptionReason);
    }

    public static function emptyCodes(): array
    {
        return [
            'vazio' => [''],
            'null' => [null],
        ];
    }

    #[Test]
    #[DataProvider('emptyCodes')]
    public function from_config_turns_an_empty_code_into_null(?string $code): void
    {
        config([
            'services.invoicing.vat_rate' => 0,
            'services.invoicing.vat_exemption_code' => $code,
            'services.invoicing.vat_exemption_reason' => self::REASON,
        ]);

        $this->assertNull(InvoiceTax::fromConfig()->exemptionCode);
    }

    #[Test]
    public function from_config_with_vat_ignores_the_default_reason(): void
    {
        // O motivo por omissão existe sempre na config: com IVA não pode impedir a emissão.
        config([
            'services.invoicing.vat_rate' => 23,
            'services.invoicing.vat_exemption_code' => '',
            'services.invoicing.vat_exemption_reason' => self::REASON,
        ]);

        $tax = InvoiceTax::fromConfig();

        $this->assertSame(23, $tax->rate);
        $this->assertNull($tax->exemptionCode);
        $this->assertNull($tax->exemptionReason);
    }

    #[Test]
    public function from_config_exempt_without_reason_is_rejected(): void
    {
        config([
            'services.invoicing.vat_rate' => 0,
            'services.invoicing.vat_exemption_code' => '',
            'services.invoicing.vat_exemption_reason' => '',
        ]);

        $this->expectException(InvalidArgumentException::class);

        InvoiceTax::fromConfig();
    }
}
