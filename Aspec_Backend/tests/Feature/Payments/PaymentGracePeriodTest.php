<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\PaymentFailedNotification;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * Período de carência: a 1.ª falha de cobrança de uma subscrição dá ao membro uma semana
 * (configurável) com acesso total e um aviso por email; um pagamento recebido termina-a.
 * Retries do Stripe não prolongam a carência, senão nunca terminaria.
 */
class PaymentGracePeriodTest extends TestCase
{
    use RefreshDatabase, SignsStripeWebhooks;

    private const CUSTOMER = 'cus_test_1';

    private const FRONTEND_URL = 'http://localhost:5173';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => self::FRONTEND_URL,
            'subscription.grace_days' => 7,
            'subscription.price_amount' => 60.00,
        ]);
    }

    private function customer(?User $user = null): User
    {
        $user ??= User::factory()->withBilling()->create();
        $user->forceFill(['stripe_id' => self::CUSTOMER])->save();

        return $user;
    }

    private function subscribe(User $user, string $status = 'active', string $stripeId = 'sub_test_1'): void
    {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => $stripeId,
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'ends_at' => $status === 'canceled' ? now()->subDay() : null,
            'trial_ends_at' => $status === 'trialing' ? now()->addMinute() : null,
        ]);
    }

    private function activeSubscriber(string $status = 'active'): User
    {
        $user = $this->customer();
        $this->subscribe($user, $status);

        return $user;
    }

    private function paymentFailed(?string $invoiceId = null, ?string $eventId = null, string $status = 'open', ?string $subscription = 'sub_test_1'): array
    {
        return $this->stripeEvent(
            'invoice.payment_failed',
            $this->stripeInvoice(self::CUSTOMER, 6000, $invoiceId, $status, $subscription),
            $eventId,
        );
    }

    private function mailText(MailMessage $mail): string
    {
        return implode("\n", array_merge(
            [$mail->subject, $mail->greeting, $mail->actionText],
            $mail->introLines,
            $mail->outroLines,
        ));
    }

    private function assertGraceEndsAt(CarbonInterface $expected, User $user): void
    {
        $graceEndsAt = $user->fresh()->grace_ends_at;

        $this->assertNotNull($graceEndsAt);
        $this->assertTrue($expected->equalTo($graceEndsAt), "Carência esperada até {$expected}, obtida {$graceEndsAt}.");
    }

    #[Test]
    public function first_failed_payment_starts_a_seven_day_grace_period_and_warns_the_member(): void
    {
        $this->freezeSecond();
        Notification::fake();
        $user = $this->activeSubscriber();
        $expected = now()->addDays(7);

        $this->postStripeWebhook($this->paymentFailed())->assertOk();

        $this->assertGraceEndsAt($expected, $user);
        $this->assertSame('Active', $user->fresh()->accountStatus->name);
        Notification::assertSentToTimes($user, PaymentFailedNotification::class, 1);
        Notification::assertSentTo($user, PaymentFailedNotification::class, function ($notification) use ($user, $expected) {
            return str_contains($this->mailText($notification->toMail($user)), $expected->format('d/m/Y'));
        });
    }

    #[Test]
    public function grace_period_length_comes_from_the_configuration(): void
    {
        $this->freezeSecond();
        Notification::fake();
        config(['subscription.grace_days' => 3]);
        $user = $this->activeSubscriber();

        $this->postStripeWebhook($this->paymentFailed())->assertOk();

        $this->assertGraceEndsAt(now()->addDays(3), $user);
    }

    #[Test]
    public function retry_failures_do_not_extend_the_grace_period_nor_send_another_email(): void
    {
        $this->freezeSecond();
        Notification::fake();
        $user = $this->activeSubscriber();
        $expected = now()->addDays(7);

        $this->postStripeWebhook($this->paymentFailed('in_test_1', 'evt_test_falha_1'))->assertOk();
        $this->travel(2)->days();
        $this->postStripeWebhook($this->paymentFailed('in_test_1', 'evt_test_falha_2'))->assertOk();

        $this->assertGraceEndsAt($expected, $user);
        Notification::assertSentToTimes($user, PaymentFailedNotification::class, 1);
    }

    #[Test]
    public function failed_event_whose_invoice_is_already_paid_changes_nothing(): void
    {
        Notification::fake();
        $user = $this->activeSubscriber();

        $this->postStripeWebhook($this->paymentFailed(status: 'paid'))->assertOk();

        $this->assertNull($user->fresh()->grace_ends_at);
        Notification::assertNothingSent();
    }

    /**
     * O payment_failed chega atrasado, depois de o pagamento já estar registado: a fatura local
     * é a prova de que a quota foi paga.
     */
    #[Test]
    public function failed_event_for_an_invoice_already_recorded_as_paid_changes_nothing(): void
    {
        Notification::fake();
        $user = $this->activeSubscriber();
        Invoice::factory()->create(['user_id' => $user->id, 'stripe_invoice_id' => 'in_test_pago']);

        $this->postStripeWebhook($this->paymentFailed('in_test_pago'))->assertOk();

        $this->assertNull($user->fresh()->grace_ends_at);
        Notification::assertNothingSent();
    }

    #[Test]
    public function failed_one_off_invoice_without_subscription_changes_nothing(): void
    {
        Notification::fake();
        $user = $this->activeSubscriber();

        $this->postStripeWebhook($this->paymentFailed(subscription: null))->assertOk();

        $this->assertNull($user->fresh()->grace_ends_at);
        Notification::assertNothingSent();
    }

    public static function accountsThatAreNotActive(): array
    {
        return [
            'aprovada' => ['approved', null],
            'pendente' => ['pending', null],
            'bloqueada' => ['inactive', 'blocked'],
            'sem pagamento' => ['inactive', 'unpaid'],
        ];
    }

    #[Test]
    #[DataProvider('accountsThatAreNotActive')]
    public function failed_payment_of_an_account_that_is_not_active_changes_nothing(string $state, ?string $reason): void
    {
        Notification::fake();
        $factory = User::factory()->withBilling();
        $factory = match ($state) {
            'approved' => $factory->approved(),
            'pending' => $factory->pending(),
            'inactive' => $factory->inactive(InactiveReason::from($reason)),
        };
        $user = $this->customer($factory->create());
        $this->subscribe($user);

        $this->postStripeWebhook($this->paymentFailed())->assertOk();

        $fresh = $user->fresh();
        $this->assertNull($fresh->grace_ends_at);
        $this->assertSame($reason, $fresh->inactive_reason?->value);
        Notification::assertNothingSent();
    }

    #[Test]
    public function failed_payment_of_an_admin_changes_nothing(): void
    {
        Notification::fake();
        $admin = $this->customer(User::factory()->admin()->create());
        $this->subscribe($admin);

        $this->postStripeWebhook($this->paymentFailed())->assertOk();

        $this->assertNull($admin->fresh()->grace_ends_at);
        Notification::assertNothingSent();
    }

    #[Test]
    public function failed_payment_of_an_unknown_customer_is_accepted_and_ignored(): void
    {
        Notification::fake();
        $user = $this->activeSubscriber();

        $this->postStripeWebhook($this->stripeEvent(
            'invoice.payment_failed',
            $this->stripeInvoice('cus_desconhecido', 6000, null, 'open'),
        ))->assertOk();

        $this->assertNull($user->fresh()->grace_ends_at);
        Notification::assertNothingSent();
    }

    #[Test]
    public function same_failed_event_delivered_twice_sends_one_email(): void
    {
        Notification::fake();
        $user = $this->activeSubscriber();
        $event = $this->paymentFailed();

        $this->postStripeWebhook($event)->assertOk();
        $this->postStripeWebhook($event)->assertOk();

        Notification::assertSentToTimes($user, PaymentFailedNotification::class, 1);
        $this->assertDatabaseCount('processed_webhook_events', 1);
    }

    #[Test]
    public function failed_charge_at_the_end_of_the_trial_starts_the_grace_period(): void
    {
        $this->freezeSecond();
        Notification::fake();
        $user = $this->activeSubscriber('trialing');

        $this->postStripeWebhook($this->paymentFailed())->assertOk();

        $this->assertGraceEndsAt(now()->addDays(7), $user);
        Notification::assertSentToTimes($user, PaymentFailedNotification::class, 1);
    }

    #[Test]
    public function rollback_after_the_grace_period_is_recorded_leaves_no_grace_and_sends_no_email(): void
    {
        $user = $this->activeSubscriber();
        Event::listen(WebhookHandled::class, fn () => throw new RuntimeException('falha depois do handler'));

        $this->postStripeWebhook($this->paymentFailed())->assertStatus(500);

        $this->assertNull($user->fresh()->grace_ends_at);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->assertDatabaseCount('processed_webhook_events', 0);
    }

    #[Test]
    public function member_in_grace_period_keeps_full_access(): void
    {
        $this->freezeSecond();
        Notification::fake();
        $user = $this->activeSubscriber();
        DB::table('subscriptions')->where('stripe_id', 'sub_test_1')->update(['stripe_status' => 'past_due']);

        $this->postStripeWebhook($this->paymentFailed())->assertOk();

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('data.status', 'past_due')
            ->assertJsonPath('data.grace_ends_at', now()->addDays(7)->toIso8601String());

        $this->postJson('/api/auth/token', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function payment_failed_notification_is_queued_after_commit_without_personal_data_or_links(): void
    {
        $notification = new PaymentFailedNotification(now()->addDays(7));

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertTrue($notification->afterCommit);

        $serialized = serialize($notification);
        $this->assertStringNotContainsString('@', $serialized);
        $this->assertStringNotContainsString('/conta/subscricao', $serialized);
        $this->assertStringNotContainsString(self::FRONTEND_URL, $serialized);
    }

    #[Test]
    public function successful_payment_ends_the_grace_period_and_is_invoiced(): void
    {
        Queue::fake();
        $user = $this->activeSubscriber('past_due');
        $user->forceFill(['grace_ends_at' => now()->addDays(5)])->save();

        $this->postStripeWebhook($this->stripeEvent(
            'invoice.payment_succeeded',
            $this->stripeInvoice(self::CUSTOMER, 6000, 'in_test_pago'),
        ))->assertOk();

        $this->assertNull($user->fresh()->grace_ends_at);
        $this->assertDatabaseHas('invoices', ['user_id' => $user->id, 'stripe_invoice_id' => 'in_test_pago']);
    }

    #[Test]
    public function zero_amount_payment_keeps_the_grace_period(): void
    {
        $this->freezeSecond();
        Queue::fake();
        $user = $this->activeSubscriber('past_due');
        $graceEndsAt = now()->addDays(5);
        $user->forceFill(['grace_ends_at' => $graceEndsAt])->save();

        $this->postStripeWebhook($this->stripeEvent(
            'invoice.payment_succeeded',
            $this->stripeInvoice(self::CUSTOMER, 0),
        ))->assertOk();

        $this->assertGraceEndsAt($graceEndsAt, $user);
    }

    public static function overdueStatusesBeforeActive(): array
    {
        return [
            'past_due' => ['past_due'],
            'unpaid' => ['unpaid'],
        ];
    }

    /**
     * Só a passagem de past_due/unpaid para active prova que a dívida foi paga.
     */
    #[Test]
    #[DataProvider('overdueStatusesBeforeActive')]
    public function subscription_back_to_active_ends_the_grace_period(string $previousStatus): void
    {
        Notification::fake();
        $user = $this->activeSubscriber($previousStatus);
        $user->forceFill(['grace_ends_at' => now()->addDays(5)])->save();

        $this->postStripeWebhook($this->stripeEvent(
            'customer.subscription.updated',
            $this->stripeSubscription(self::CUSTOMER, 'active'),
            previousAttributes: ['status' => $previousStatus],
        ))->assertOk();

        $fresh = $user->fresh();
        $this->assertNull($fresh->grace_ends_at);
        $this->assertSame('Active', $fresh->accountStatus->name);
        $this->assertDatabaseHas('subscriptions', ['stripe_id' => 'sub_test_1', 'stripe_status' => 'active']);
    }

    #[Test]
    public function subscription_updated_to_past_due_keeps_the_grace_period(): void
    {
        $this->freezeSecond();
        Notification::fake();
        $user = $this->activeSubscriber();
        $graceEndsAt = now()->addDays(5);
        $user->forceFill(['grace_ends_at' => $graceEndsAt])->save();

        $this->postStripeWebhook($this->stripeEvent(
            'customer.subscription.updated',
            $this->stripeSubscription(self::CUSTOMER, 'past_due'),
        ))->assertOk();

        $this->assertGraceEndsAt($graceEndsAt, $user);
        $this->assertDatabaseHas('subscriptions', ['stripe_id' => 'sub_test_1', 'stripe_status' => 'past_due']);
    }

    /**
     * Um updated(active) antigo que chegue depois do cancelamento não pode ressuscitar a
     * subscrição: "canceled" é terminal no Stripe e daria acesso a quem não pagou.
     */
    #[Test]
    public function late_update_for_a_canceled_subscription_is_ignored(): void
    {
        $this->freezeSecond();
        Notification::fake();
        $user = $this->customer(User::factory()->inactive(InactiveReason::Unpaid)->withBilling()->create());
        $this->subscribe($user, 'canceled');
        $endsAt = now()->subDay();

        $this->postStripeWebhook($this->stripeEvent(
            'customer.subscription.updated',
            $this->stripeSubscription(self::CUSTOMER, 'active'),
        ))->assertOk();

        $subscription = DB::table('subscriptions')->where('stripe_id', 'sub_test_1')->first();
        $this->assertSame('canceled', $subscription->stripe_status);
        $this->assertNotNull($subscription->ends_at);
        $this->assertSame($endsAt->toDateTimeString(), $subscription->ends_at);
        $fresh = $user->fresh();
        $this->assertSame('Inactive', $fresh->accountStatus->name);
        $this->assertSame(InactiveReason::Unpaid, $fresh->inactive_reason);
        Notification::assertNothingSent();
    }

    #[Test]
    public function payment_failed_email_shows_the_price_the_deadline_and_a_link_to_update_the_card(): void
    {
        $user = User::factory()->create();
        $graceEndsAt = now()->addDays(7);

        $mail = (new PaymentFailedNotification($graceEndsAt))->toMail($user);
        $text = $this->mailText($mail);

        $this->assertSame('Não foi possível cobrar a quota ASPEC', $mail->subject);
        $this->assertStringContainsString($graceEndsAt->format('d/m/Y'), $text);
        $this->assertStringContainsString('60,00', $text);
        $this->assertSame('Atualizar cartão', $mail->actionText);
        $this->assertSame(self::FRONTEND_URL.'/conta/subscricao', $mail->actionUrl);
    }

    public static function updatesWithoutPayment(): array
    {
        return [
            'só metadata' => [['metadata' => ['origem' => 'dashboard']]],
            'fim do trial' => [['status' => 'trialing']],
        ];
    }

    /**
     * Um updated(active) que não vem de past_due/unpaid (ex.: metadata, trial → active, ou um
     * evento antigo entregue fora de ordem) não prova pagamento: limpar a carência dava acesso
     * além dos dias de carência sem pagar.
     */
    #[Test]
    #[DataProvider('updatesWithoutPayment')]
    public function active_update_without_a_transition_from_overdue_keeps_the_grace_period(array $previousAttributes): void
    {
        $this->freezeSecond();
        Notification::fake();
        $user = $this->activeSubscriber();
        $graceEndsAt = now()->addDays(5);
        $user->forceFill(['grace_ends_at' => $graceEndsAt])->save();

        $this->postStripeWebhook($this->stripeEvent(
            'customer.subscription.updated',
            $this->stripeSubscription(self::CUSTOMER, 'active'),
            previousAttributes: $previousAttributes,
        ))->assertOk();

        $this->assertGraceEndsAt($graceEndsAt, $user);
    }

    /**
     * Falha atrasada da subscrição antiga (já cancelada) depois de uma reativação: não pode
     * abrir carência nem enviar um falso "pagamento falhou" na subscrição nova.
     */
    #[Test]
    public function failed_payment_of_the_old_canceled_subscription_after_reactivation_changes_nothing(): void
    {
        Notification::fake();
        $user = $this->customer();
        $this->subscribe($user, 'canceled', 'sub_antiga');
        $this->subscribe($user, 'active', 'sub_nova');

        $this->postStripeWebhook($this->paymentFailed(subscription: 'sub_antiga'))->assertOk();

        $this->assertNull($user->fresh()->grace_ends_at);
        Notification::assertNothingSent();
    }

    #[Test]
    public function failed_payment_of_a_subscription_without_a_local_row_changes_nothing(): void
    {
        Notification::fake();
        $user = $this->activeSubscriber();

        $this->postStripeWebhook($this->paymentFailed(subscription: 'sub_desconhecida'))->assertOk();

        $this->assertNull($user->fresh()->grace_ends_at);
        Notification::assertNothingSent();
    }

    #[Test]
    public function failed_payment_naming_another_members_subscription_changes_nothing(): void
    {
        Notification::fake();
        $user = $this->activeSubscriber();
        $other = User::factory()->withBilling()->create(['stripe_id' => 'cus_outro_membro']);
        $this->subscribe($other, 'active', 'sub_do_outro');

        $this->postStripeWebhook($this->paymentFailed(subscription: 'sub_do_outro'))->assertOk();

        $this->assertNull($user->fresh()->grace_ends_at);
        $this->assertNull($other->fresh()->grace_ends_at);
        Notification::assertNothingSent();
    }

    /**
     * Endpoint configurado numa versão da API anterior à basil: a subscrição vem no topo da
     * fatura. A carência não abre, mas o erro de configuração tem de ficar visível no log.
     */
    #[Test]
    public function failed_payment_in_the_old_api_format_is_logged_and_opens_no_grace(): void
    {
        Notification::fake();
        Log::spy();
        $user = $this->activeSubscriber();
        $invoice = array_merge(
            $this->stripeInvoice(self::CUSTOMER, 6000, 'in_test_antigo', 'open', null),
            ['subscription' => 'sub_test_1'],
        );
        $event = $this->stripeEvent('invoice.payment_failed', $invoice);
        $event['api_version'] = '2024-06-20';

        $this->postStripeWebhook($event)->assertOk();

        $this->assertNull($user->fresh()->grace_ends_at);
        Notification::assertNothingSent();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => $message === 'Falha de pagamento num formato antigo da API do Stripe; carência não aberta.'
                && ($context['stripe_invoice_id'] ?? null) === 'in_test_antigo'
                && ($context['api_version'] ?? null) === '2024-06-20'
                && ! str_contains(json_encode($context), self::CUSTOMER)
                && ! str_contains(json_encode($context), $user->email))
            ->once();
    }

    #[Test]
    public function failed_one_off_invoice_is_ignored_without_a_warning(): void
    {
        Notification::fake();
        Log::spy();
        $user = $this->activeSubscriber();

        $this->postStripeWebhook($this->paymentFailed(subscription: null))->assertOk();

        $this->assertNull($user->fresh()->grace_ends_at);
        Log::shouldNotHaveReceived('warning');
    }
}
