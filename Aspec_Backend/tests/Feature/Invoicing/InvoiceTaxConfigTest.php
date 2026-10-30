<?php

namespace Tests\Feature\Invoicing;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O IVA da fatura é configurado num só sítio, comum a todos os drivers (a isenção é da ASPEC,
 * não do fornecedor de faturação). Por omissão a quota é isenta, com um motivo que diz ser
 * provisório e sem código: nenhum código legal é adivinhado.
 */
class InvoiceTaxConfigTest extends TestCase
{
    private function envExample(): string
    {
        return (string) file_get_contents(base_path('.env.example'));
    }

    #[Test]
    public function vat_rate_is_zero_in_the_neutral_invoicing_config(): void
    {
        $this->assertSame(0, config('services.invoicing.vat_rate'));
    }

    #[Test]
    public function exemption_code_is_empty_by_default(): void
    {
        $this->assertEmpty(config('services.invoicing.vat_exemption_code'));
    }

    #[Test]
    public function exemption_reason_says_it_is_provisional(): void
    {
        $this->assertStringContainsString('provisório', (string) config('services.invoicing.vat_exemption_reason'));
    }

    #[Test]
    public function invoiceexpress_tax_name_defaults_to_the_zero_rate(): void
    {
        $this->assertSame('IVA0', config('services.invoiceexpress.tax_name'));
    }

    #[Test]
    public function invoiceexpress_no_longer_has_its_own_vat_keys(): void
    {
        $config = config('services.invoiceexpress');

        $this->assertArrayNotHasKey('vat_rate', $config);
        $this->assertArrayNotHasKey('tax_exemption', $config);
    }

    #[Test]
    public function env_example_documents_the_exempt_invoice_settings(): void
    {
        $env = $this->envExample();

        $this->assertMatchesRegularExpression('/^INVOICE_VAT_RATE=0\s*$/m', $env);
        $this->assertMatchesRegularExpression('/^INVOICE_VAT_EXEMPTION_CODE=\s*$/m', $env);
        $this->assertMatchesRegularExpression('/^INVOICE_VAT_EXEMPTION_REASON=.*provisório/mu', $env);
        $this->assertMatchesRegularExpression('/^INVOICEEXPRESS_TAX_NAME=IVA0\s*$/m', $env);
    }

    #[Test]
    public function env_example_drops_the_old_invoiceexpress_vat_variables(): void
    {
        $env = $this->envExample();

        $this->assertStringNotContainsString('INVOICEEXPRESS_VAT_RATE', $env);
        $this->assertStringNotContainsString('INVOICEEXPRESS_TAX_EXEMPTION', $env);
    }
}
