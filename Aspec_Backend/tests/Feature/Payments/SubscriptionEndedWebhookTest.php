<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\ReactivationLinkNotification;
use App\Notifications\SubscriptionActivatedNotification;
use App\Services\Payments\StripeSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * Fim de uma subscrição que não é falta de pagamento (cancelamento pedido pelo membro ou feito
 * no dashboard): a conta fica Inactive com o motivo "cancelled" e recebe o email "a subscrição
 * terminou" com o link de reativação (sem novo trial). Falta de pagamento ganha sempre.
 */
class SubscriptionEndedWebhookTest extends TestCase
{
    use RefreshDatabase, SignsStripeWebhooks;

    private const CUSTOMER = 'cus_test_1';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['app.frontend_url' => 'http://localhost:5173']);
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('cancelNow');
        });
    }

    private function customer(?User $user = null): User
    {
        $user ??= User::factory()->withBilling()->create();
        $user->forceFill(['stripe_id' => self::CUSTOMER])->save();

        return $user;
    }

    private function subscribe(User $user, string $status = 'active'): void
    {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_1',
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'ends_at' => $status === 'canceled' ? now()->subMonth() : now()->addDays(3),
        ]);
    }

    private function deleted(array $overrides = []): array
    {
        return $this->stripeEvent(
            'customer.subscription.deleted',
            $this->stripeSubscription(self::CUSTOMER, 'canceled', 'sub_test_1', $overrides),
        );
    }

    private function cancellationReason(string $reason): array
    {
        return ['cancellation_details' => ['reason' => $reason, 'comment' => null, 'feedback' => null]];
    }

    #[Test]
    public function end_of_a_subscription_cancelled_by_the_member_deactivates_with_reason_cancelled(): void
    {
        $user = $this->customer();
        $this->subscribe($user);

        $this->postStripeWebhook($this->deleted($this->cancellationReason('cancellation_requested')))->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Inactive', $fresh->accountStatus->name);
        $this->assertSame(InactiveReason::Cancelled, $fresh->inactive_reason);
        Notification::assertSentToTimes($fresh, ReactivationLinkNotification::class, 1);
        Notification::assertSentTo($fresh, ReactivationLinkNotification::class, function ($notification) use ($fresh) {
            return $notification->toMail($fresh)->subject === 'A sua subscrição ASPEC terminou';
        });
    }

    public static function dashboardCancellations(): array
    {
        return [
            'sem cancellation_details' => [null],
            'motivo null' => ['null'],
            'cancellation_requested' => ['cancellation_requested'],
        ];
    }

    /**
     * Cancelar no dashboard do Stripe não é falta de pagamento: não se acusa o membro de não pagar.
     */
    #[Test]
    #[DataProvider('dashboardCancellations')]
    public function subscription_cancelled_in_the_stripe_dashboard_deactivates_with_reason_cancelled(?string $reason): void
    {
        $user = $this->customer();
        $this->subscribe($user, 'active');
        DB::table('subscriptions')->update(['ends_at' => null]);
        $overrides = match ($reason) {
            null => [],
            'null' => ['cancellation_details' => ['reason' => null, 'comment' => null, 'feedback' => null]],
            default => $this->cancellationReason($reason),
        };

        $this->postStripeWebhook($this->deleted($overrides))->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Inactive', $fresh->accountStatus->name);
        $this->assertSame(InactiveReason::Cancelled, $fresh->inactive_reason);
        Notification::assertSentToTimes($fresh, ReactivationLinkNotification::class, 1);
    }

    /**
     * A carência é nossa e fiável: se o membro estava em carência, a subscrição terminou por
     * falta de pagamento, diga o payload o que disser.
     */
    #[Test]
    public function member_in_grace_is_deactivated_as_unpaid_even_if_stripe_says_cancellation_requested(): void
    {
        $user = $this->customer();
        $user->forceFill(['grace_ends_at' => now()->addDays(2)])->save();
        $this->subscribe($user, 'past_due');

        $this->postStripeWebhook($this->deleted($this->cancellationReason('cancellation_requested')))->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(InactiveReason::Unpaid, $fresh->inactive_reason);
        Notification::assertSentTo($fresh, ReactivationLinkNotification::class, function ($notification) use ($fresh) {
            return $notification->toMail($fresh)->subject !== 'A sua subscrição ASPEC terminou';
        });
    }

    /**
     * Cancelamento pedido durante o trial: o fim fica no fim do trial, lido do payload, sem o
     * Cashier ter de perguntar ao Stripe o fim do período (que daria erro nos testes).
     */
    #[Test]
    public function update_with_cancel_at_period_end_during_trial_ends_at_the_trial_end(): void
    {
        $this->freezeSecond();
        $user = $this->customer();
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_1',
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'trial_ends_at' => now()->addDays(20),
        ]);
        $trialEnd = now()->addDays(20)->startOfSecond()->timestamp;

        $this->postStripeWebhook($this->stripeEvent(
            'customer.subscription.updated',
            $this->stripeSubscription(self::CUSTOMER, 'trialing', 'sub_test_1', [
                'trial_end' => $trialEnd,
                'cancel_at_period_end' => true,
            ]),
        ))->assertOk();

        $subscription = $user->subscriptions()->where('stripe_id', 'sub_test_1')->firstOrFail();
        $this->assertNotNull($subscription->ends_at);
        $this->assertSame($trialEnd, $subscription->ends_at->timestamp);
        $this->assertSame('Active', $user->fresh()->accountStatus->name);
        Notification::assertNothingSent();
    }

    #[Test]
    public function failed_payment_of_a_cancelled_account_starts_no_grace_period(): void
    {
        $user = $this->customer(User::factory()->inactive(InactiveReason::Cancelled)->withBilling()->create());
        $this->subscribe($user, 'past_due');

        $this->postStripeWebhook($this->stripeEvent(
            'invoice.payment_failed',
            $this->stripeInvoice(self::CUSTOMER, 6000, null, 'open'),
        ))->assertOk();

        $fresh = $user->fresh();
        $this->assertNull($fresh->grace_ends_at);
        $this->assertSame(InactiveReason::Cancelled, $fresh->inactive_reason);
        Notification::assertNothingSent();
    }

    #[Test]
    public function new_subscription_reactivates_a_cancelled_account_without_trial(): void
    {
        $user = $this->customer(User::factory()->inactive(InactiveReason::Cancelled)->withBilling()->create());
        $this->subscribe($user, 'canceled');

        $this->postStripeWebhook($this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscription(self::CUSTOMER, 'active', 'sub_test_2'),
        ))->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Active', $fresh->accountStatus->name);
        $this->assertNull($fresh->inactive_reason);
        $this->assertNull($fresh->trial_ends_at);
        Notification::assertSentToTimes($fresh, SubscriptionActivatedNotification::class, 1);
    }

    /**
     * Um administrador que paga e cancela a subscrição perde o acesso como qualquer membro.
     */
    #[Test]
    public function end_of_a_subscription_cancelled_by_an_admin_with_a_profile_deactivates_with_reason_cancelled(): void
    {
        $admin = $this->customer(User::factory()->admin()->withBilling()->create());
        MemberProfile::factory()->create(['user_id' => $admin->id]);
        $this->subscribe($admin);

        $this->postStripeWebhook($this->deleted($this->cancellationReason('cancellation_requested')))->assertOk();

        $fresh = $admin->fresh();
        $this->assertSame('Inactive', $fresh->accountStatus->name);
        $this->assertSame(InactiveReason::Cancelled, $fresh->inactive_reason);
        $this->assertSame('Admin', $fresh->role->name);
        Notification::assertSentToTimes($fresh, ReactivationLinkNotification::class, 1);
    }

    #[Test]
    public function end_of_a_subscription_of_the_exempt_admin_changes_nothing(): void
    {
        $admin = $this->customer(User::factory()->technicalAdmin()->create());
        $this->subscribe($admin);

        $this->postStripeWebhook($this->deleted($this->cancellationReason('cancellation_requested')))->assertOk();

        $fresh = $admin->fresh();
        $this->assertSame('Active', $fresh->accountStatus->name);
        $this->assertNull($fresh->inactive_reason);
        Notification::assertNothingSent();
    }
}
