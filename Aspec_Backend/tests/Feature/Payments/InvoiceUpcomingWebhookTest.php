<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\SubscriptionRenewalReminderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * invoice.upcoming: aviso de renovação da quota anual, alguns dias antes da cobrança (antecedência
 * configurada no dashboard do Stripe). Só com quota anual: uma cobrança grande uma vez por ano
 * não deve ser surpresa, mas no mensal seriam 12 emails por ano de ruído. Só para membros Active,
 * não isentos, com a subscrição do evento em curso e sem cancelamento agendado.
 */
class InvoiceUpcomingWebhookTest extends TestCase
{
    use RefreshDatabase, SignsStripeWebhooks;

    private const CUSTOMER = 'cus_test_1';

    private const FRONTEND_URL = 'http://localhost:5173';

    /** 28/10/2027 10:00 UTC: data da renovação. */
    private const RENEWS_AT = 1824717600;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo('2027-10-21 10:00:00');
        config([
            'app.frontend_url' => self::FRONTEND_URL,
            'subscription.interval' => 'year',
            'subscription.price_amount' => 20.00,
        ]);
    }

    private function subscriber(?User $user = null, string $status = 'active', ?string $endsAt = null, string $subscriptionId = 'sub_test_1'): User
    {
        $user ??= User::factory()->withBilling()->create();
        $user->forceFill(['stripe_id' => self::CUSTOMER])->save();
        MemberProfile::factory()->create(['user_id' => $user->id, 'name' => 'Ana Ferreira']);
        $this->subscribe($user, $subscriptionId, $status, $endsAt);

        return $user->fresh();
    }

    private function subscribe(User $user, string $stripeId, string $status = 'active', ?string $endsAt = null): Subscription
    {
        return $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => $stripeId,
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'ends_at' => $endsAt,
        ]);
    }

    /**
     * Fatura futura no formato basil: não tem id; a subscrição vem em parent e o período a cobrar
     * na linha da subscrição.
     *
     * @return array<string, mixed>
     */
    private function upcomingInvoice(
        string $customer = self::CUSTOMER,
        string $subscription = 'sub_test_1',
        int $amountDue = 2000,
        ?int $nextPaymentAttempt = self::RENEWS_AT,
    ): array {
        return [
            'object' => 'invoice',
            'customer' => $customer,
            'amount_due' => $amountDue,
            'amount_paid' => 0,
            'currency' => 'eur',
            'status' => 'draft',
            'billing_reason' => 'upcoming',
            'next_payment_attempt' => $nextPaymentAttempt,
            'parent' => [
                'type' => 'subscription_details',
                'subscription_details' => ['subscription' => $subscription, 'metadata' => []],
            ],
            'lines' => [
                'object' => 'list',
                'has_more' => false,
                'data' => [[
                    'object' => 'line_item',
                    'amount' => $amountDue,
                    'currency' => 'eur',
                    'parent' => [
                        'type' => 'subscription_item_details',
                        'subscription_item_details' => ['subscription' => $subscription, 'subscription_item' => 'si_test_1'],
                    ],
                    'period' => ['start' => self::RENEWS_AT, 'end' => self::RENEWS_AT + 365 * 86400],
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $invoice
     */
    private function postUpcoming(?array $invoice = null, ?string $eventId = null): void
    {
        $this->postStripeWebhook($this->stripeEvent('invoice.upcoming', $invoice ?? $this->upcomingInvoice(), $eventId))
            ->assertOk();
    }

    private function mailText(MailMessage $mail): string
    {
        return implode("\n", array_merge(
            [$mail->subject, $mail->greeting, $mail->actionText, $mail->actionUrl],
            $mail->introLines,
            $mail->outroLines,
        ));
    }

    /**
     * Notificação enviada ao utilizador (falha se não houver exatamente uma).
     */
    private function sentReminder(User $user): SubscriptionRenewalReminderNotification
    {
        Notification::assertSentToTimes($user, SubscriptionRenewalReminderNotification::class, 1);

        return Notification::sent($user, SubscriptionRenewalReminderNotification::class)->first();
    }

    #[Test]
    public function annual_active_member_gets_one_renewal_reminder_with_the_date_and_the_amount(): void
    {
        $user = $this->subscriber();

        $this->postUpcoming();

        $text = $this->mailText($this->sentReminder($user)->toMail($user));
        $this->assertStringContainsString('A sua quota anual de 20,00 € renova a 28/10/2027.', $text);
        $this->assertStringContainsString(self::FRONTEND_URL.'/conta/subscricao', $text);
    }

    #[Test]
    public function reminder_is_a_queued_mail_sent_only_after_the_webhook_commits(): void
    {
        $user = $this->subscriber();

        $this->postUpcoming();

        $notification = $this->sentReminder($user);
        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertTrue($notification->afterCommit);
        $this->assertSame(['mail'], $notification->via($user));
    }

    #[Test]
    public function reminder_greets_the_member_by_the_professional_name(): void
    {
        $user = $this->subscriber();

        $this->postUpcoming();

        $this->assertSame('Olá, Ana Ferreira!', $this->sentReminder($user)->toMail($user)->greeting);
    }

    /**
     * O valor é o da fatura que o Stripe vai cobrar, não o do .env.
     */
    #[Test]
    public function amount_comes_from_the_upcoming_invoice(): void
    {
        $user = $this->subscriber();

        $this->postUpcoming($this->upcomingInvoice(amountDue: 2500));

        $this->assertStringContainsString('25,00 €', $this->mailText($this->sentReminder($user)->toMail($user)));
    }

    #[Test]
    public function without_next_payment_attempt_the_date_is_the_start_of_the_period_to_charge(): void
    {
        $user = $this->subscriber();

        $this->postUpcoming($this->upcomingInvoice(nextPaymentAttempt: null));

        $this->assertStringContainsString('renova a 28/10/2027', $this->mailText($this->sentReminder($user)->toMail($user)));
    }

    #[Test]
    public function monthly_fee_sends_no_reminder(): void
    {
        config(['subscription.interval' => 'month']);
        $this->subscriber();

        $this->postUpcoming();

        Notification::assertNothingSent();
    }

    #[Test]
    public function subscription_exempt_admin_gets_no_reminder(): void
    {
        $this->subscriber(User::factory()->technicalAdmin()->withBilling()->create());

        $this->postUpcoming();

        Notification::assertNothingSent();
    }

    public static function accountsThatAreNotActive(): array
    {
        return [
            'inativa por falta de pagamento' => ['inactive', InactiveReason::Unpaid],
            'bloqueada' => ['inactive', InactiveReason::Blocked],
            'aprovada à espera de ativação' => ['approved', null],
            'pendente' => ['pending', null],
        ];
    }

    #[Test]
    #[DataProvider('accountsThatAreNotActive')]
    public function account_that_is_not_active_gets_no_reminder(string $state, ?InactiveReason $reason): void
    {
        $factory = User::factory()->withBilling();
        $user = $state === 'inactive' ? $factory->inactive($reason)->create() : $factory->{$state}()->create();
        $this->subscriber($user);

        $this->postUpcoming();

        Notification::assertNothingSent();
    }

    #[Test]
    public function unknown_customer_is_accepted_and_ignored(): void
    {
        $this->subscriber();

        $this->postUpcoming($this->upcomingInvoice(customer: 'cus_desconhecido'));

        Notification::assertNothingSent();
    }

    #[Test]
    public function subscription_of_another_member_sends_no_reminder(): void
    {
        $this->subscriber();
        $other = User::factory()->withBilling()->create(['stripe_id' => 'cus_test_outro']);
        $this->subscribe($other, 'sub_test_outro');

        $this->postUpcoming($this->upcomingInvoice(subscription: 'sub_test_outro'));

        Notification::assertNothingSent();
    }

    #[Test]
    public function unknown_subscription_sends_no_reminder(): void
    {
        $this->subscriber();

        $this->postUpcoming($this->upcomingInvoice(subscription: 'sub_test_inexistente'));

        Notification::assertNothingSent();
    }

    #[Test]
    public function ended_subscription_sends_no_reminder(): void
    {
        $this->subscriber(status: 'canceled', endsAt: '2027-10-01 10:00:00');

        $this->postUpcoming();

        Notification::assertNothingSent();
    }

    /**
     * Quem pediu o cancelamento não vai ser cobrado: um "renova a …" seria falso.
     */
    #[Test]
    public function scheduled_cancellation_sends_no_reminder(): void
    {
        $this->subscriber(status: 'active', endsAt: '2027-10-28 10:00:00');

        $this->postUpcoming();

        Notification::assertNothingSent();
    }

    #[Test]
    public function same_event_delivered_twice_sends_one_reminder(): void
    {
        $user = $this->subscriber();
        $event = $this->stripeEvent('invoice.upcoming', $this->upcomingInvoice());

        $this->postStripeWebhook($event)->assertOk();
        $this->postStripeWebhook($event)->assertOk();

        Notification::assertSentToTimes($user, SubscriptionRenewalReminderNotification::class, 1);
        $this->assertDatabaseCount('processed_webhook_events', 1);
    }

    #[Test]
    public function upcoming_invoice_creates_no_invoice_row(): void
    {
        $this->subscriber();

        $this->postUpcoming();

        $this->assertDatabaseCount('invoices', 0);
    }

    #[Test]
    public function queued_reminder_is_still_sent_while_the_account_is_active(): void
    {
        $user = $this->subscriber();

        $this->postUpcoming();

        $this->assertTrue($this->sentReminder($user)->shouldSend($user->fresh(), 'mail'));
    }

    /**
     * O email sai da fila depois: se a conta ficou Inactive entretanto, já não renova.
     */
    #[Test]
    public function queued_reminder_is_not_sent_if_the_account_became_inactive(): void
    {
        $user = $this->subscriber();
        $this->postUpcoming();
        $notification = $this->sentReminder($user);

        $user->deactivate(InactiveReason::Blocked->value);

        $this->assertFalse($notification->shouldSend($user->fresh(), 'mail'));
    }

    /**
     * Se o membro agendou o cancelamento depois do invoice.upcoming, o "renova a …" seria falso.
     */
    #[Test]
    public function queued_reminder_is_not_sent_if_the_cancellation_was_scheduled_meanwhile(): void
    {
        $user = $this->subscriber();
        $this->postUpcoming();
        $notification = $this->sentReminder($user);

        $user->subscriptions()->update(['ends_at' => now()->addMonths(6)]);

        $this->assertFalse($notification->shouldSend($user->fresh(), 'mail'));
    }

    #[Test]
    public function queued_reminder_is_not_sent_if_the_subscription_ended_meanwhile(): void
    {
        $user = $this->subscriber();
        $this->postUpcoming();
        $notification = $this->sentReminder($user);

        $user->subscriptions()->update(['stripe_status' => 'canceled']);

        $this->assertFalse($notification->shouldSend($user->fresh(), 'mail'));
    }

    #[Test]
    public function queued_reminder_is_not_sent_if_the_account_became_exempt_meanwhile(): void
    {
        $user = $this->subscriber();
        $this->postUpcoming();
        $notification = $this->sentReminder($user);

        $user->forceFill(['subscription_exempt' => true])->save();

        $this->assertFalse($notification->shouldSend($user->fresh(), 'mail'));
    }
}
