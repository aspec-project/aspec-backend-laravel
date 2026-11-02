<?php

namespace Tests\Feature\Payments;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * invoice.payment_succeeded grava o período pago (invoices.period_start/period_end) a partir da
 * linha da subscrição na fatura do Stripe (lines.data[].period, fim exclusivo). Nunca usa
 * invoice.period_start/period_end: numa renovação referem-se ao período anterior. Um payload sem
 * período válido não impede a fatura.
 */
class InvoicePaidPeriodWebhookTest extends TestCase
{
    use RefreshDatabase, SignsStripeWebhooks;

    private const CUSTOMER = 'cus_test_1';

    /** 28/10/2026 10:00 UTC. */
    private const PERIOD_START = 1793181600;

    /** 28/10/2027 10:00 UTC (fim exclusivo de uma quota anual). */
    private const PERIOD_END = 1824717600;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $user = User::factory()->withBilling()->create();
        $user->forceFill(['stripe_id' => self::CUSTOMER])->save();
    }

    /**
     * Linha da fatura no formato basil.
     *
     * @return array<string, mixed>
     */
    private function line(string $parentType, mixed $start, mixed $end, string $id = 'il_test_1'): array
    {
        return [
            'id' => $id,
            'object' => 'line_item',
            'amount' => 2000,
            'currency' => 'eur',
            'parent' => [
                'type' => $parentType,
                $parentType => ['subscription' => 'sub_test_1', 'subscription_item' => 'si_test_1'],
            ],
            'period' => ['start' => $start, 'end' => $end],
        ];
    }

    private function subscriptionLine(mixed $start = self::PERIOD_START, mixed $end = self::PERIOD_END): array
    {
        return $this->line('subscription_item_details', $start, $end);
    }

    /**
     * Pagamento de 20 € com as linhas indicadas (null = sem a chave lines). O período do topo da
     * fatura é sempre o anterior, como numa renovação, para provar que nunca é usado.
     *
     * @param  array<int, array<string, mixed>>|null  $lines
     */
    private function postPayment(?array $lines): void
    {
        $invoice = $this->stripeInvoice(self::CUSTOMER, 2000, 'in_test_periodo');
        $invoice['period_start'] = self::PERIOD_START - 365 * 86400;
        $invoice['period_end'] = self::PERIOD_START;

        if ($lines !== null) {
            $invoice['lines'] = ['object' => 'list', 'data' => $lines, 'has_more' => false];
        }

        $this->postStripeWebhook($this->stripeEvent('invoice.payment_succeeded', $invoice))->assertOk();
    }

    private function storedInvoice(): Invoice
    {
        return Invoice::where('stripe_invoice_id', 'in_test_periodo')->firstOrFail();
    }

    #[Test]
    public function paid_period_comes_from_the_subscription_line(): void
    {
        $this->postPayment([$this->subscriptionLine()]);

        $invoice = $this->storedInvoice();
        $this->assertSame(self::PERIOD_START, $invoice->period_start->getTimestamp());
        $this->assertSame(self::PERIOD_END, $invoice->period_end->getTimestamp());
    }

    #[Test]
    public function paid_period_is_cast_to_dates(): void
    {
        $this->postPayment([$this->subscriptionLine()]);

        $invoice = $this->storedInvoice();
        $this->assertInstanceOf(Carbon::class, $invoice->period_start);
        $this->assertInstanceOf(Carbon::class, $invoice->period_end);
    }

    #[Test]
    public function line_of_another_type_before_the_subscription_line_is_skipped(): void
    {
        $this->postPayment([
            $this->line('invoice_item_details', self::PERIOD_START - 86400, self::PERIOD_START, 'il_test_avulso'),
            $this->subscriptionLine(),
        ]);

        $invoice = $this->storedInvoice();
        $this->assertSame(self::PERIOD_START, $invoice->period_start->getTimestamp());
        $this->assertSame(self::PERIOD_END, $invoice->period_end->getTimestamp());
    }

    #[Test]
    public function without_a_subscription_line_the_first_line_is_used(): void
    {
        $this->postPayment([
            $this->line('invoice_item_details', self::PERIOD_START, self::PERIOD_END),
        ]);

        $invoice = $this->storedInvoice();
        $this->assertSame(self::PERIOD_START, $invoice->period_start->getTimestamp());
        $this->assertSame(self::PERIOD_END, $invoice->period_end->getTimestamp());
    }

    public static function payloadsWithoutAValidPeriod(): array
    {
        return [
            'sem lines' => [null],
            'lines vazio' => [[]],
            'início em texto' => [[['period' => ['start' => '1793181600', 'end' => self::PERIOD_END]]]],
            'fim em texto' => [[['period' => ['start' => self::PERIOD_START, 'end' => '1824717600']]]],
            'início null' => [[['period' => ['start' => null, 'end' => self::PERIOD_END]]]],
            'decimal' => [[['period' => ['start' => 1793181600.5, 'end' => self::PERIOD_END]]]],
            'fim igual ao início' => [[['period' => ['start' => self::PERIOD_START, 'end' => self::PERIOD_START]]]],
            'fim antes do início' => [[['period' => ['start' => self::PERIOD_END, 'end' => self::PERIOD_START]]]],
            'sem period' => [[['period' => null]]],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $lines  Linhas parciais: cada uma substitui
     *                                                        as chaves da linha da subscrição.
     */
    #[Test]
    #[DataProvider('payloadsWithoutAValidPeriod')]
    public function invalid_or_missing_period_is_stored_as_null_and_the_invoice_is_still_created(?array $lines): void
    {
        $this->postPayment($lines === null ? null : array_map(
            fn (array $line) => array_replace($this->subscriptionLine(), $line),
            $lines,
        ));

        $invoice = $this->storedInvoice();
        $this->assertNull($invoice->period_start);
        $this->assertNull($invoice->period_end);
        $this->assertSame(2000, $invoice->amount);
    }

    #[Test]
    public function repeated_payment_event_keeps_the_period_of_the_first_one(): void
    {
        $this->postPayment([$this->subscriptionLine()]);
        $this->postPayment([$this->subscriptionLine(self::PERIOD_START + 86400, self::PERIOD_END + 86400)]);

        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(self::PERIOD_START, $this->storedInvoice()->period_start->getTimestamp());
    }
}
