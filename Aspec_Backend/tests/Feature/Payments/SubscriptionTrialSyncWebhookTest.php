<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Enums\SubscriptionState;
use App\Models\ProcessedWebhookEvent;
use App\Models\User;
use App\Notifications\SubscriptionActivatedNotification;
use App\Services\Payments\StripeSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Subscription;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * customer.subscription.updated acerta users.trial_ends_at numa conta Active quando o trial muda
 * no Stripe (ex.: terminado mais cedo no dashboard). A lista do admin filtra e conta por essa
 * coluna: se ficasse com a data antiga, a conta continuava "Em trial".
 */
class SubscriptionTrialSyncWebhookTest extends TestCase
{
    use RefreshDatabase, SignsStripeWebhooks;

    private const CUSTOMER = 'cus_test_1';

    private const SUBSCRIPTION = 'sub_test_1';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function customer(User $user, ?int $trialEndsAt): User
    {
        $user->forceFill([
            'stripe_id' => self::CUSTOMER,
            'trial_ends_at' => $trialEndsAt === null ? null : Carbon::createFromTimestamp($trialEndsAt),
        ])->save();

        return $user;
    }

    private function member(?int $trialEndsAt, string $subscriptionStatus = 'trialing'): User
    {
        $user = $this->customer(User::factory()->withBilling()->create(), $trialEndsAt);
        $this->subscribe($user, $subscriptionStatus);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function subscribe(User $user, string $status, string $stripeId = self::SUBSCRIPTION, array $attributes = []): void
    {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => $stripeId,
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $previous  data.previous_attributes do evento.
     * @param  array<string, mixed>  $overrides
     */
    private function updated(
        string $status,
        ?int $trialEnd,
        array $previous = [],
        string $stripeId = self::SUBSCRIPTION,
        ?string $eventId = null,
        array $overrides = [],
    ): array {
        return $this->stripeEvent(
            'customer.subscription.updated',
            $this->stripeSubscription(self::CUSTOMER, $status, $stripeId, ['trial_end' => $trialEnd, ...$overrides]),
            $eventId,
            $previous,
        );
    }

    private function deliver(array $event): void
    {
        $this->postStripeWebhook($event)->assertOk();
    }

    private function expectNoCancellation(): void
    {
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('cancelNow');
        });
    }

    private function future(int $days = 20): int
    {
        return now()->addDays($days)->timestamp;
    }

    private function past(int $days = 1): int
    {
        return now()->subDays($days)->timestamp;
    }

    private function assertTrialEnd(?int $expected, User $user): void
    {
        $this->assertSame($expected, $user->fresh()->trial_ends_at?->timestamp);
    }

    private function assertState(SubscriptionState $expected, User $user): void
    {
        $this->assertSame($expected, $user->fresh()->subscriptionState());
    }

    private function assertEveryReaderAgrees(User $user, SubscriptionState $expected, SubscriptionState $absent): void
    {
        $this->assertState($expected, $user);
        $this->assertTrue(User::inSubscriptionState($expected)->whereKey($user->id)->exists());
        $this->assertFalse(User::inSubscriptionState($absent)->whereKey($user->id)->exists());

        $counts = User::subscriptionStateCounts(User::query());
        $this->assertSame(1, $counts[$expected->value]);
        $this->assertSame(0, $counts[$absent->value]);

        $this->getJson('/api/admin/users?include_admins=1&subscription_state='.$expected->value)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $user->id)
            ->assertJsonPath('data.items.0.subscription_state.value', $expected->value)
            ->assertJsonPath('data.subscription_state_counts', $counts);

        $this->getJson('/api/admin/users?include_admins=1&subscription_state='.$absent->value)
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    #[Test]
    public function early_end_of_trial_stores_the_real_end_date_without_sending_email(): void
    {
        $user = $this->member($this->future(30));
        $ended = $this->past();

        $this->deliver($this->updated('active', $ended, ['status' => 'trialing', 'trial_end' => $this->future(30)]));

        $this->assertTrialEnd($ended, $user);
        $this->assertState(SubscriptionState::Active, $user);
        Notification::assertNothingSent();
    }

    #[Test]
    public function early_end_is_detected_when_only_the_status_is_in_the_previous_attributes(): void
    {
        $user = $this->member($this->future(30));
        $ended = $this->past();

        $this->deliver($this->updated('active', $ended, ['status' => 'trialing']));

        $this->assertTrialEnd($ended, $user);
        $this->assertState(SubscriptionState::Active, $user);
        Notification::assertNothingSent();
    }

    #[Test]
    public function trial_that_ends_in_past_due_does_not_return_to_trial_when_the_grace_period_is_cleared(): void
    {
        $user = $this->member($this->future(30));
        $ended = $this->past();

        $this->deliver($this->updated('past_due', $ended, ['status' => 'trialing', 'trial_end' => $this->future(30)]));
        $this->assertTrialEnd($ended, $user);

        $this->deliver($this->stripeEvent(
            'invoice.payment_failed',
            $this->stripeInvoice(self::CUSTOMER, status: 'open', subscription: self::SUBSCRIPTION),
        ));
        $this->assertState(SubscriptionState::Grace, $user);

        $this->deliver($this->updated('active', $ended, ['status' => 'past_due']));

        $this->assertNull($user->fresh()->grace_ends_at);
        $this->assertTrialEnd($ended, $user);
        $this->assertState(SubscriptionState::Active, $user);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function changedTrialLengths(): array
    {
        return [
            'extended' => [40],
            'shortened' => [3],
        ];
    }

    #[Test]
    #[DataProvider('changedTrialLengths')]
    public function changed_trial_date_is_stored_and_the_account_stays_in_trial(int $days): void
    {
        $original = $this->future(20);
        $user = $this->member($original);
        $newDate = $this->future($days);

        $this->deliver($this->updated('trialing', $newDate, ['trial_end' => $original]));

        $this->assertTrialEnd($newDate, $user);
        $this->assertState(SubscriptionState::Trial, $user);
    }

    #[Test]
    public function removed_trial_date_clears_the_account_column(): void
    {
        $original = $this->future(20);
        $user = $this->member($original);

        $this->deliver($this->updated('active', null, ['trial_end' => $original]));

        $this->assertTrialEnd(null, $user);
        $this->assertState(SubscriptionState::Active, $user);
    }

    #[Test]
    public function trial_added_to_a_subscription_without_one_is_stored(): void
    {
        $user = $this->member(null, 'active');
        $newDate = $this->future();

        $this->deliver($this->updated('trialing', $newDate, ['trial_end' => null]));

        $this->assertTrialEnd($newDate, $user);
        $this->assertState(SubscriptionState::Trial, $user);
    }

    /**
     * Com um test clock, o trial termina no relógio simulado mas o trial_end continua no futuro em
     * relação ao relógio real: uma data futura só pode existir se o Stripe disser trialing.
     */
    #[Test]
    public function future_trial_end_outside_trialing_is_capped_to_now(): void
    {
        $this->freezeTime();
        $user = $this->member($this->future(30));

        $this->deliver($this->updated('active', $this->future(10), ['status' => 'trialing']));

        $this->assertTrialEnd(now()->timestamp, $user);
        $this->assertState(SubscriptionState::Active, $user);
    }

    #[Test]
    public function subscription_state_readers_agree_before_and_after_the_sync(): void
    {
        Sanctum::actingAs(User::factory()->technicalAdmin()->create());
        $user = $this->member($this->future(30));

        $this->assertEveryReaderAgrees($user, SubscriptionState::Trial, SubscriptionState::Active);

        $this->deliver($this->updated('active', $this->past(), ['status' => 'trialing', 'trial_end' => $this->future(30)]));

        $this->assertEveryReaderAgrees($user, SubscriptionState::Active, SubscriptionState::Trial);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function previousAttributesWithoutTrialChange(): array
    {
        return [
            'metadata' => [['metadata' => ['origem' => 'dashboard']]],
            'cancel at period end' => [['cancel_at_period_end' => true]],
            'price' => [['items' => ['data' => []]]],
            'running status' => [['status' => 'incomplete']],
            'no previous attributes' => [[]],
        ];
    }

    /**
     * @param  array<string, mixed>  $previous
     */
    #[Test]
    #[DataProvider('previousAttributesWithoutTrialChange')]
    public function update_without_a_trial_transition_does_not_write_the_date(array $previous): void
    {
        $stored = $this->future(30);
        $user = $this->member($stored, 'active');

        $this->deliver($this->updated('active', $this->past(), $previous));

        $this->assertTrialEnd($stored, $user);
    }

    /**
     * O cold start do Render faz o Stripe repetir entregas mais tarde: um evento antigo que não
     * alterou o trial não pode repor a data de antes do fim antecipado.
     */
    #[Test]
    public function late_event_without_a_trial_change_keeps_the_synced_date(): void
    {
        $original = $this->future(30);
        $user = $this->member($original);
        $ended = $this->past();

        $this->deliver($this->updated('active', $ended, ['status' => 'trialing', 'trial_end' => $original]));
        $this->deliver($this->updated('trialing', $original, ['metadata' => ['origem' => 'dashboard']]));

        $this->assertTrialEnd($ended, $user);
        $this->assertState(SubscriptionState::Active, $user);
    }

    #[Test]
    public function duplicated_event_writes_the_date_only_once(): void
    {
        $user = $this->member($this->future(30));
        $ended = $this->past();
        $event = $this->updated('active', $ended, ['status' => 'trialing'], eventId: 'evt_test_duplicado');

        $this->deliver($event);
        $this->assertTrialEnd($ended, $user);

        $changedMeanwhile = $this->past(5);
        DB::table('users')->where('id', $user->id)->update([
            'trial_ends_at' => Carbon::createFromTimestamp($changedMeanwhile),
        ]);

        $this->deliver($event);

        $this->assertTrialEnd($changedMeanwhile, $user);
        $this->assertSame(1, ProcessedWebhookEvent::where('stripe_event_id', 'evt_test_duplicado')->count());
    }

    #[Test]
    public function reactivated_account_ignores_an_event_from_its_ended_old_subscription(): void
    {
        $user = $this->customer(User::factory()->withBilling()->create(), null);
        $this->subscribe($user, 'canceled', 'sub_test_antiga', ['ends_at' => now()->subDays(2)]);
        $this->subscribe($user, 'active', 'sub_test_nova');
        $original = $this->future(30);

        $this->deliver($this->updated('trialing', $this->future(10), ['trial_end' => $original], 'sub_test_antiga'));

        $this->assertTrialEnd(null, $user);
        $this->assertState(SubscriptionState::Active, $user);
    }

    #[Test]
    public function reactivated_account_ignores_an_event_from_an_old_subscription_not_yet_ended(): void
    {
        $user = $this->customer(User::factory()->withBilling()->create(), null);
        $this->subscribe($user, 'active', 'sub_test_antiga');
        $this->subscribe($user, 'active', 'sub_test_nova');

        $this->deliver($this->updated('trialing', $this->future(10), ['trial_end' => $this->future(30)], 'sub_test_antiga'));

        $this->assertTrialEnd(null, $user);
        $this->assertState(SubscriptionState::Active, $user);
    }

    #[Test]
    public function technical_admin_exempt_from_the_subscription_is_not_synced(): void
    {
        $stored = $this->future(30);
        $user = $this->customer(User::factory()->technicalAdmin()->create(), $stored);
        $this->subscribe($user, 'trialing');

        $this->deliver($this->updated('active', $this->past(), ['status' => 'trialing', 'trial_end' => $stored]));

        $this->assertTrialEnd($stored, $user);
        $this->assertState(SubscriptionState::Exempt, $user);
    }

    #[Test]
    public function admin_who_pays_the_subscription_is_synced(): void
    {
        $original = $this->future(30);
        $user = $this->customer(User::factory()->admin()->withBilling()->create(), $original);
        $this->subscribe($user, 'trialing');
        $ended = $this->past();

        $this->deliver($this->updated('active', $ended, ['status' => 'trialing', 'trial_end' => $original]));

        $this->assertTrialEnd($ended, $user);
        $this->assertState(SubscriptionState::Active, $user);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function accountsThatAreNotActive(): array
    {
        return [
            'approved' => ['approved'],
            'inactive unpaid' => ['unpaid'],
            'inactive cancelled' => ['cancelled'],
            'blocked' => ['blocked'],
        ];
    }

    private function accountThatIsNotActive(string $kind, ?int $trialEndsAt): User
    {
        $factory = match ($kind) {
            'approved' => User::factory()->approved(),
            'unpaid' => User::factory()->inactive(InactiveReason::Unpaid),
            'cancelled' => User::factory()->inactive(InactiveReason::Cancelled),
            'blocked' => User::factory()->inactive(InactiveReason::Blocked),
        };

        return $this->customer($factory->withBilling()->create(), $trialEndsAt);
    }

    /**
     * Em past_due o handler sai antes da regra de ativação, por isso qualquer escrita na coluna só
     * podia vir da sincronização do trial.
     */
    #[Test]
    #[DataProvider('accountsThatAreNotActive')]
    public function account_that_is_not_active_is_not_synced(string $kind): void
    {
        $this->expectNoCancellation();
        $stored = $this->future(30);
        $user = $this->accountThatIsNotActive($kind, $stored);
        $this->subscribe($user, 'trialing');
        $status = $user->accountStatus->name;

        $this->deliver($this->updated('past_due', $this->past(), ['status' => 'trialing', 'trial_end' => $stored]));

        $this->assertTrialEnd($stored, $user);
        $this->assertSame($status, $user->fresh()->accountStatus->name);
        Notification::assertNothingSent();
    }

    #[Test]
    public function approved_account_is_still_activated_with_the_trial_end_sent_by_stripe(): void
    {
        $this->expectNoCancellation();
        $user = $this->accountThatIsNotActive('approved', null);
        $this->subscribe($user, 'incomplete');
        $trialEnd = $this->future(30);

        $this->deliver($this->updated('active', $trialEnd, ['status' => 'incomplete']));

        $fresh = $user->fresh();
        $this->assertSame('Active', $fresh->accountStatus->name);
        $this->assertTrialEnd($trialEnd, $user);
        Notification::assertSentToTimes($fresh, SubscriptionActivatedNotification::class, 1);
    }

    #[Test]
    public function unpaid_account_is_still_reactivated_without_a_new_trial(): void
    {
        $this->expectNoCancellation();
        $user = $this->accountThatIsNotActive('unpaid', null);
        $this->subscribe($user, 'incomplete');

        $this->deliver($this->updated('active', null, ['status' => 'incomplete']));

        $fresh = $user->fresh();
        $this->assertSame('Active', $fresh->accountStatus->name);
        $this->assertTrialEnd(null, $user);
        Notification::assertSentToTimes($fresh, SubscriptionActivatedNotification::class, 1);
    }

    #[Test]
    public function blocked_account_keeps_its_date_and_its_subscription_is_still_cancelled(): void
    {
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelNow')
                ->once()
                ->withArgs(fn (Subscription $subscription) => $subscription->stripe_id === self::SUBSCRIPTION);
        });
        $stored = $this->future(30);
        $user = $this->accountThatIsNotActive('blocked', $stored);
        $this->subscribe($user, 'trialing');

        $this->deliver($this->updated('active', $this->past(), ['status' => 'trialing', 'trial_end' => $stored]));

        $fresh = $user->fresh();
        $this->assertSame('Inactive', $fresh->accountStatus->name);
        $this->assertSame(InactiveReason::Blocked, $fresh->inactive_reason);
        $this->assertTrialEnd($stored, $user);
    }

    #[Test]
    public function subscription_of_another_type_is_ignored_when_the_default_one_is_ongoing(): void
    {
        $stored = $this->future(30);
        $user = $this->member($stored);
        $this->subscribe($user, 'trialing', 'sub_test_outra', ['type' => 'outra']);

        $this->deliver($this->updated('active', $this->past(), ['status' => 'trialing', 'trial_end' => $stored], 'sub_test_outra'));

        $this->assertTrialEnd($stored, $user);
    }

    #[Test]
    public function subscription_of_another_type_is_ignored_when_there_is_no_default_one(): void
    {
        $stored = $this->future(30);
        $user = $this->customer(User::factory()->withBilling()->create(), $stored);
        $this->subscribe($user, 'trialing', 'sub_test_outra', ['type' => 'outra']);

        $this->deliver($this->updated('active', $this->past(), ['status' => 'trialing', 'trial_end' => $stored], 'sub_test_outra'));

        $this->assertTrialEnd($stored, $user);
    }

    #[Test]
    public function older_subscription_is_ignored_when_a_newer_one_is_ongoing(): void
    {
        $stored = $this->future(30);
        $user = $this->customer(User::factory()->withBilling()->create(), $stored);
        $this->subscribe($user, 'trialing', 'sub_test_antiga');
        $this->subscribe($user, 'trialing', 'sub_test_nova');

        $this->deliver($this->updated('active', $this->past(), ['status' => 'trialing', 'trial_end' => $stored], 'sub_test_antiga'));

        $this->assertTrialEnd($stored, $user);
    }

    #[Test]
    public function locally_ended_subscription_is_ignored_without_reaching_cashier(): void
    {
        $stored = $this->future(30);
        $user = $this->customer(User::factory()->withBilling()->create(), $stored);
        $endsAt = now()->subDay()->startOfSecond();
        $this->subscribe($user, 'canceled', attributes: ['ends_at' => $endsAt]);

        $this->deliver($this->updated('active', $this->past(), ['status' => 'trialing', 'trial_end' => $stored]));

        $this->assertTrialEnd($stored, $user);
        $subscription = $user->subscriptions()->where('stripe_id', self::SUBSCRIPTION)->first();
        $this->assertSame('canceled', $subscription->stripe_status);
        $this->assertSame($endsAt->timestamp, $subscription->ends_at->timestamp);
        $this->assertNull($subscription->trial_ends_at);
    }

    #[Test]
    public function event_of_a_cancelled_subscription_is_not_synced(): void
    {
        $stored = $this->future(30);
        $user = $this->member($stored);

        $this->deliver($this->updated(
            'canceled',
            $this->past(),
            ['status' => 'trialing', 'trial_end' => $stored],
            overrides: ['canceled_at' => $this->past()],
        ));

        $this->assertTrialEnd($stored, $user);
    }

    #[Test]
    public function real_passage_from_past_due_to_active_clears_the_grace_period(): void
    {
        $ended = $this->past(10);
        $user = $this->member($ended, 'past_due');
        $user->forceFill(['grace_ends_at' => now()->addDays(3)])->save();

        $this->deliver($this->updated('active', $ended, ['status' => 'past_due']));

        $this->assertNull($user->fresh()->grace_ends_at);
        $this->assertTrialEnd($ended, $user);
    }

    #[Test]
    public function end_of_trial_does_not_clear_the_grace_period(): void
    {
        $original = $this->future(30);
        $user = $this->member($original);
        $graceEndsAt = now()->addDays(3)->startOfSecond();
        $user->forceFill(['grace_ends_at' => $graceEndsAt])->save();

        $this->deliver($this->updated('active', $this->past(), ['status' => 'trialing', 'trial_end' => $original]));

        $this->assertSame($graceEndsAt->timestamp, $user->fresh()->grace_ends_at?->timestamp);
        $this->assertState(SubscriptionState::Grace, $user);
    }

    #[Test]
    public function natural_end_of_trial_does_not_write_on_the_account(): void
    {
        $ended = $this->past();
        $user = $this->member($ended);
        $updatedAt = '2026-01-15 10:00:00';
        DB::table('users')->where('id', $user->id)->update(['updated_at' => $updatedAt]);

        $this->deliver($this->updated('active', $ended, ['status' => 'trialing']));

        $this->assertTrialEnd($ended, $user);
        $this->assertState(SubscriptionState::Active, $user);
        $this->assertSame($updatedAt, $user->fresh()->updated_at->format('Y-m-d H:i:s'));
    }
}
