<?php

namespace Tests\Feature\Payments;

use App\Services\Payments\StripeSubscriptionService;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Stripe\Exception\InvalidRequestException;
use Stripe\Price;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

/**
 * Comando subscriptions:check-price: quem cobra é o Price do Stripe, mas os emails, a página da
 * subscrição e as faturas usam o .env. O comando compara os dois e sai com 1 quando não batem
 * certo, para o erro aparecer antes de um email errado chegar a um membro. O Stripe é mockado.
 */
class CheckSubscriptionPriceCommandTest extends TestCase
{
    private const COMMAND = 'subscriptions:check-price';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'subscription.price_id' => 'price_test',
            'subscription.price_amount' => 20.00,
            'subscription.currency' => 'eur',
            'subscription.interval' => 'year',
        ]);
    }

    /**
     * Price do Stripe igual à configuração, com as alterações indicadas.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function stripePrice(array $overrides = []): Price
    {
        return Price::constructFrom(array_replace_recursive([
            'id' => 'price_test',
            'object' => 'price',
            'active' => true,
            'currency' => 'eur',
            'unit_amount' => 2000,
            'type' => 'recurring',
            'recurring' => [
                'interval' => 'year',
                'interval_count' => 1,
            ],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function stripeReturns(array $overrides = []): void
    {
        $price = $this->stripePrice($overrides);

        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) use ($price) {
            $mock->shouldReceive('retrievePrice')->once()->with('price_test')->andReturn($price);
        });
    }

    #[Test]
    public function price_matching_the_config_succeeds(): void
    {
        $this->stripeReturns();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function monthly_price_matching_a_monthly_config_succeeds(): void
    {
        config(['subscription.interval' => 'month']);
        $this->stripeReturns(['recurring' => ['interval' => 'month']]);

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function different_interval_fails_and_shows_both_values(): void
    {
        $this->stripeReturns(['recurring' => ['interval' => 'month']]);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('SUBSCRIPTION_INTERVAL')
            ->expectsOutputToContain('month')
            ->expectsOutputToContain('year')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function different_amount_fails_and_names_the_setting_to_fix(): void
    {
        $this->stripeReturns(['unit_amount' => 6000]);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('SUBSCRIPTION_PRICE_AMOUNT')
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * 20,01 € no .env contra 20,00 € no Stripe: a comparação é em cêntimos, sem arredondar.
     */
    #[Test]
    public function one_cent_difference_fails(): void
    {
        config(['subscription.price_amount' => 20.01]);
        $this->stripeReturns();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('SUBSCRIPTION_PRICE_AMOUNT')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function different_currency_fails_and_shows_both_values(): void
    {
        $this->stripeReturns(['currency' => 'usd']);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('CASHIER_CURRENCY')
            ->expectsOutputToContain('usd')
            ->expectsOutputToContain('eur')
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * Um Price "a cada 3 anos" tem interval year mas não é uma quota anual.
     */
    #[Test]
    public function interval_count_other_than_one_fails(): void
    {
        $this->stripeReturns(['recurring' => ['interval_count' => 3]]);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('interval_count')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function inactive_price_fails(): void
    {
        $this->stripeReturns(['active' => false]);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('inativo')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function several_differences_are_all_reported(): void
    {
        $this->stripeReturns(['unit_amount' => 6000, 'currency' => 'usd', 'recurring' => ['interval' => 'month']]);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('SUBSCRIPTION_INTERVAL')
            ->expectsOutputToContain('SUBSCRIPTION_PRICE_AMOUNT')
            ->expectsOutputToContain('CASHIER_CURRENCY')
            ->assertExitCode(Command::FAILURE);
    }

    public static function emptyPriceIds(): array
    {
        return [
            'null' => [null],
            'vazio' => [''],
        ];
    }

    #[Test]
    #[DataProvider('emptyPriceIds')]
    public function empty_price_id_fails_without_calling_stripe(?string $priceId): void
    {
        config(['subscription.price_id' => $priceId]);
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('retrievePrice');
        });

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('STRIPE_PRICE_ID')
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * Price inexistente (id errado ou de outra conta/modo): mensagem em português, sem a
     * mensagem em inglês do Stripe nem stack trace.
     */
    #[Test]
    public function missing_price_fails_with_a_portuguese_message(): void
    {
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('retrievePrice')->once()->with('price_test')
                ->andThrow(InvalidRequestException::factory("No such price: 'price_test'", 404, null, null, null, 'resource_missing'));
        });

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('price_test')
            ->doesntExpectOutputToContain('No such price')
            ->assertExitCode(Command::FAILURE);
    }
}
