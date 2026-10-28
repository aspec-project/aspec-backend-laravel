<?php

namespace Tests\Feature\Payments;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Configuração da periodicidade da quota: o mesmo código serve a quota mensal e a anual,
 * mudando só o .env e o Price no Stripe. Os nomes por omissão da quota não dizem "mensal",
 * porque a fatura de uma quota anual não pode dizer que é mensal.
 */
class SubscriptionIntervalConfigTest extends TestCase
{
    private function envExample(): string
    {
        return (string) file_get_contents(base_path('.env.example'));
    }

    #[Test]
    public function interval_defaults_to_month(): void
    {
        $this->assertSame('month', config('subscription.interval'));
    }

    #[Test]
    public function invoice_description_defaults_to_a_name_without_the_interval(): void
    {
        $this->assertSame('Quota ASPEC', config('subscription.invoice_description'));
    }

    #[Test]
    public function invoiceexpress_item_name_defaults_to_a_name_without_the_interval(): void
    {
        $this->assertSame('Quota ASPEC', config('services.invoiceexpress.item_name'));
    }

    #[Test]
    public function env_example_documents_the_interval_and_the_new_fee(): void
    {
        $env = $this->envExample();

        $this->assertMatchesRegularExpression('/^SUBSCRIPTION_INTERVAL=month\s*$/m', $env);
        $this->assertMatchesRegularExpression('/^SUBSCRIPTION_PRICE_AMOUNT=20\.00\s*$/m', $env);
        $this->assertMatchesRegularExpression('/^SUBSCRIPTION_INVOICE_DESCRIPTION="Quota ASPEC"\s*$/m', $env);
        $this->assertMatchesRegularExpression('/^INVOICEEXPRESS_ITEM_NAME="Quota ASPEC"\s*$/m', $env);
    }

    #[Test]
    public function env_example_never_calls_the_fee_monthly(): void
    {
        $this->assertStringNotContainsStringIgnoringCase('quota mensal', $this->envExample());
    }
}
