<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Models\User;
use App\Notifications\SubscriptionActivatedNotification;
use App\Services\Payments\StripeSubscriptionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Subscription;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * customer.subscription.updated ativa a conta quando uma subscrição incompleta passa a ativa
 * (ex.: 3-D Secure confirmado depois do Checkout), com as mesmas regras do created: só contas
 * que podem pagar, nunca duas subscrições, contas bloqueadas ficam bloqueadas.
 */
class SubscriptionUpdatedWebhookTest extends TestCase
{
    use RefreshDatabase, SignsStripeWebhooks;

    private const CUSTOMER = 'cus_test_1';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function customer(User $user): User
    {
        $user->forceFill(['stripe_id' => self::CUSTOMER])->save();

        return $user;
    }

    private function subscribe(User $user, string $status, string $stripeId = 'sub_test_1'): void
    {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => $stripeId,
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'trial_ends_at' => $status === 'trialing' ? now()->addDays(10) : null,
        ]);
    }

    private function updated(string $status = 'active', string $stripeId = 'sub_test_1', array $overrides = [], ?string $eventId = null): array
    {
        return $this->stripeEvent(
            'customer.subscription.updated',
            $this->stripeSubscription(self::CUSTOMER, $status, $stripeId, $overrides),
            $eventId,
        );
    }

    private function expectNoCancellation(): void
    {
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('cancelNow');
        });
    }

    private function expectCancellationOf(string $subscriptionId): void
    {
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) use ($subscriptionId) {
            $mock->shouldReceive('cancelNow')
                ->once()
                ->withArgs(fn (Subscription $subscription) => $subscription->stripe_id === $subscriptionId);
        });
    }

    #[Test]
    public function incomplete_subscription_that_becomes_active_activates_an_approved_account(): void
    {
        $this->expectNoCancellation();
        $user = $this->customer(User::factory()->approved()->withBilling()->create());
        $trialEnd = now()->addDays(30)->startOfSecond()->timestamp;

        $this->postStripeWebhook($this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscription(self::CUSTOMER, 'incomplete'),
        ))->assertOk();
        $this->assertSame('Approved', $user->fresh()->accountStatus->name);

        $this->postStripeWebhook($this->updated('active', overrides: ['trial_end' => $trialEnd]))->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Active', $fresh->accountStatus->name);
        $this->assertNotNull($fresh->trial_ends_at);
        $this->assertSame($trialEnd, $fresh->trial_ends_at->timestamp);
        Notification::assertSentToTimes($fresh, SubscriptionActivatedNotification::class, 1);
    }

    #[Test]
    public function reactivation_confirmed_after_3d_secure_activates_an_unpaid_account(): void
    {
        $this->expectNoCancellation();
        $user = $this->customer(User::factory()->inactive(InactiveReason::Unpaid)->withBilling()->create());
        $this->subscribe($user, 'incomplete');

        $this->postStripeWebhook($this->updated('active'))->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Active', $fresh->accountStatus->name);
        $this->assertNull($fresh->inactive_reason);
        $this->assertNull($fresh->trial_ends_at);
        Notification::assertSentToTimes($fresh, SubscriptionActivatedNotification::class, 1);
    }

    #[Test]
    public function subscription_of_a_blocked_account_that_becomes_active_is_cancelled(): void
    {
        $this->expectCancellationOf('sub_test_1');
        $user = $this->customer(User::factory()->inactive(InactiveReason::Blocked)->withBilling()->create());
        $this->subscribe($user, 'incomplete');

        $this->postStripeWebhook($this->updated('active'))->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Inactive', $fresh->accountStatus->name);
        $this->assertSame(InactiveReason::Blocked, $fresh->inactive_reason);
        Notification::assertNothingSent();
    }

    #[Test]
    public function second_subscription_that_becomes_active_is_cancelled_as_a_duplicate(): void
    {
        $this->expectCancellationOf('sub_test_2');
        $user = $this->customer(User::factory()->approved()->withBilling()->create());
        $this->subscribe($user, 'trialing', 'sub_test_1');
        $this->subscribe($user, 'incomplete', 'sub_test_2');

        $this->postStripeWebhook($this->updated('active', 'sub_test_2'))->assertOk();

        $this->assertDatabaseHas('subscriptions', ['stripe_id' => 'sub_test_1', 'stripe_status' => 'trialing']);
        Notification::assertNotSentTo($user, SubscriptionActivatedNotification::class);
    }

    #[Test]
    public function two_active_updates_with_different_event_ids_send_one_welcome_email(): void
    {
        $this->expectNoCancellation();
        $user = $this->customer(User::factory()->approved()->withBilling()->create());
        $this->subscribe($user, 'incomplete');

        $this->postStripeWebhook($this->updated('active', eventId: 'evt_test_primeiro'))->assertOk();
        $this->postStripeWebhook($this->updated('active', eventId: 'evt_test_segundo'))->assertOk();

        $this->assertSame('Active', $user->fresh()->accountStatus->name);
        Notification::assertSentToTimes($user, SubscriptionActivatedNotification::class, 1);
    }

    #[Test]
    public function expired_incomplete_subscription_is_removed_and_the_account_keeps_waiting(): void
    {
        $this->expectNoCancellation();
        $user = $this->customer(User::factory()->approved()->withBilling()->create());
        $this->subscribe($user, 'incomplete');

        $this->postStripeWebhook($this->updated('incomplete_expired'))->assertOk();

        $this->assertDatabaseMissing('subscriptions', ['stripe_id' => 'sub_test_1']);
        $this->assertSame('Approved', $user->fresh()->accountStatus->name);
        Notification::assertNothingSent();
    }

    /**
     * Como no created: o utilizador é lido com lock antes de o Cashier escrever a subscrição,
     * para serializar com um bloqueio feito ao mesmo tempo sem risco de deadlock.
     */
    #[Test]
    public function user_is_read_before_the_subscription_is_written(): void
    {
        $this->expectNoCancellation();
        $user = $this->customer(User::factory()->approved()->withBilling()->create());
        $this->subscribe($user, 'incomplete');
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $this->postStripeWebhook($this->updated('active'))->assertOk();

        $firstUserSelect = collect($queries)->search(fn (string $sql) => str_starts_with($sql, 'select') && str_contains($sql, 'from "users"'));
        $subscriptionWrite = collect($queries)->search(fn (string $sql) => str_starts_with($sql, 'insert into "subscriptions"')
            || str_starts_with($sql, 'update "subscriptions"'));
        $this->assertIsInt($firstUserSelect);
        $this->assertIsInt($subscriptionWrite);
        $this->assertLessThan($subscriptionWrite, $firstUserSelect);
    }
}
