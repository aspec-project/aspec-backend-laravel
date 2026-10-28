<?php

namespace Tests\Unit\Enums;

use App\Enums\SubscriptionInterval;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ValueError;

/**
 * Periodicidade da quota: um só sítio traduz o SUBSCRIPTION_INTERVAL (valores iguais ao
 * recurring.interval do Price do Stripe) para os textos e para as datas.
 * Usa o TestCase do Laravel porque configured() lê a configuração.
 */
class SubscriptionIntervalTest extends TestCase
{
    #[Test]
    public function values_match_the_stripe_recurring_intervals(): void
    {
        $this->assertSame('month', SubscriptionInterval::Month->value);
        $this->assertSame('year', SubscriptionInterval::Year->value);
        $this->assertCount(2, SubscriptionInterval::cases());
    }

    public static function configuredIntervals(): array
    {
        return [
            'mensal' => ['month', SubscriptionInterval::Month],
            'anual' => ['year', SubscriptionInterval::Year],
        ];
    }

    #[Test]
    #[DataProvider('configuredIntervals')]
    public function configured_reads_the_subscription_interval_config(string $config, SubscriptionInterval $expected): void
    {
        config(['subscription.interval' => $config]);

        $this->assertSame($expected, SubscriptionInterval::configured());
    }

    public static function invalidIntervals(): array
    {
        return [
            'semanal (o Stripe aceita, nós não)' => ['week'],
            'português' => ['anual'],
            'maiúsculas' => ['Year'],
            'vazio' => [''],
        ];
    }

    /**
     * Uma configuração errada tem de falhar logo, e não cair em silêncio para o mensal.
     */
    #[Test]
    #[DataProvider('invalidIntervals')]
    public function configured_fails_loudly_with_an_invalid_value(string $config): void
    {
        config(['subscription.interval' => $config]);

        $this->expectException(ValueError::class);

        SubscriptionInterval::configured();
    }

    #[Test]
    public function adjective_is_the_portuguese_word_for_the_interval(): void
    {
        $this->assertSame('mensal', SubscriptionInterval::Month->adjective());
        $this->assertSame('anual', SubscriptionInterval::Year->adjective());
    }

    #[Test]
    public function per_period_says_per_month_or_per_year(): void
    {
        $this->assertSame('por mês', SubscriptionInterval::Month->perPeriod());
        $this->assertSame('por ano', SubscriptionInterval::Year->perPeriod());
    }

    #[Test]
    public function adding_a_month_does_not_overflow_into_the_next_month(): void
    {
        $date = CarbonImmutable::parse('2027-01-31 10:00:00');

        $this->assertSame('2027-02-28 10:00:00', SubscriptionInterval::Month->addTo($date)->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function adding_a_year_to_a_leap_day_does_not_overflow_into_march(): void
    {
        $date = CarbonImmutable::parse('2028-02-29 10:00:00');

        $this->assertSame('2029-02-28 10:00:00', SubscriptionInterval::Year->addTo($date)->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function adding_a_year_keeps_the_day_and_time(): void
    {
        $date = CarbonImmutable::parse('2026-10-28 09:15:00');

        $this->assertSame('2027-10-28 09:15:00', SubscriptionInterval::Year->addTo($date)->format('Y-m-d H:i:s'));
    }

    /**
     * O fallback do próximo pagamento soma o intervalo ao created_at da fatura: alterar a data
     * recebida mudava o atributo do modelo.
     */
    #[Test]
    public function adding_does_not_change_the_given_date(): void
    {
        $date = Carbon::parse('2026-10-28 09:15:00');

        SubscriptionInterval::Month->addTo($date);

        $this->assertSame('2026-10-28 09:15:00', $date->format('Y-m-d H:i:s'));
    }
}
