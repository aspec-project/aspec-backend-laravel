<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\ActivationLinkNotification;
use App\Notifications\ReactivationLinkNotification;
use App\Services\Payments\Data\CheckoutSessionData;
use App\Services\Payments\StripeSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Subscription;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsActivationLinks;
use Tests\TestCase;

/**
 * Os administradores pagam a subscrição como qualquer membro; só o administrador técnico criado
 * pelo aspec:create-admin (users.subscription_exempt) é isento. Promover ou despromover muda o
 * papel, nunca a subscrição. O Stripe está sempre mockado.
 */
class AdminSubscriptionTest extends TestCase
{
    use BuildsActivationLinks, RefreshDatabase;

    private const CHECKOUT_URL = 'https://checkout.stripe.com/c/pay/cs_test_admin';

    private const UNPAID_LOGIN = 'A sua conta está inativa por falta de pagamento. Use o link de reativação enviado por email.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTestFrontendUrl();
        config([
            'subscription.price_amount' => 60.00,
            'subscription.currency' => 'eur',
            'subscription.interval' => 'month',
        ]);
    }

    private function adminWithProfile(?InactiveReason $inactiveReason = null): User
    {
        $factory = User::factory()->admin()->withBilling();
        $admin = ($inactiveReason ? $factory->inactive($inactiveReason) : $factory)->create([
            'stripe_id' => 'cus_'.fake()->unique()->lexify('??????????'),
        ]);
        MemberProfile::factory()->create(['user_id' => $admin->id]);

        return $admin;
    }

    private function subscribe(User $user, string $status = 'active', array $attributes = []): Subscription
    {
        return $user->subscriptions()->create(array_merge([
            'type' => 'default',
            'stripe_id' => 'sub_'.fake()->unique()->lexify('??????????'),
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'ends_at' => $status === 'canceled' ? now()->subMonth() : null,
        ], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionSnapshot(Subscription $subscription): array
    {
        return $subscription->fresh()->only(['user_id', 'stripe_id', 'stripe_status', 'ends_at']);
    }

    #[Test]
    public function unpaid_admin_with_a_profile_gets_the_reactivation_details(): void
    {
        $admin = $this->adminWithProfile(InactiveReason::Unpaid);
        $this->subscribe($admin, 'canceled');

        $this->getJson($this->activationPath($admin))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'reactivation')
            ->assertJsonPath('data.trial_days', 0);
    }

    #[Test]
    public function unpaid_admin_with_a_profile_gets_a_checkout_url_without_a_new_trial(): void
    {
        $admin = $this->adminWithProfile(InactiveReason::Unpaid);
        $this->subscribe($admin, 'canceled');
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) use ($admin) {
            $mock->shouldReceive('createCheckout')
                ->once()
                ->withArgs(fn (User $customer, bool $withTrial) => $customer->is($admin) && $withTrial === false)
                ->andReturn(new CheckoutSessionData('cs_test_admin', self::CHECKOUT_URL, 'open'));
        });

        $this->postJson($this->activationPath($admin))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.checkout_url', self::CHECKOUT_URL);

        $this->assertSame('Inactive', $admin->fresh()->accountStatus->name);
    }

    #[Test]
    public function unpaid_admin_with_a_profile_can_ask_for_a_new_reactivation_link(): void
    {
        Notification::fake();
        $admin = $this->adminWithProfile(InactiveReason::Unpaid);

        $this->postJson('/api/account-activations/resend', ['email' => $admin->email])->assertOk();

        Notification::assertSentToTimes($admin, ReactivationLinkNotification::class, 1);
        Notification::assertNotSentTo($admin, ActivationLinkNotification::class);
    }

    #[Test]
    public function exempt_admin_activation_link_answers_already_active(): void
    {
        $admin = User::factory()->technicalAdmin()->create();

        $this->getJson($this->activationPath($admin))
            ->assertStatus(409)
            ->assertExactJson(['success' => false, 'message' => 'A conta já está ativa.']);
    }

    #[Test]
    public function exempt_admin_has_no_subscription(): void
    {
        Sanctum::actingAs(User::factory()->technicalAdmin()->create());

        $this->getJson('/api/subscription')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Não existe subscrição.']);
    }

    #[Test]
    public function admin_with_a_profile_and_a_subscription_sees_it(): void
    {
        $admin = $this->adminWithProfile();
        $this->subscribe($admin);
        Sanctum::actingAs($admin);

        $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'active');
    }

    #[Test]
    public function unpaid_admin_cannot_log_in(): void
    {
        $admin = $this->adminWithProfile(InactiveReason::Unpaid);

        $this->withHeader('Origin', self::FRONTEND_URL)
            ->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', self::UNPAID_LOGIN);

        $this->assertGuest('web');
    }

    #[Test]
    public function unpaid_admin_loses_the_backoffice(): void
    {
        Sanctum::actingAs($this->adminWithProfile(InactiveReason::Unpaid));

        $this->getJson('/api/admin/users')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function promoting_a_member_keeps_the_subscription_and_does_not_make_it_exempt(): void
    {
        $member = User::factory()->withBilling()->create(['stripe_id' => 'cus_test_promovido']);
        MemberProfile::factory()->create(['user_id' => $member->id]);
        $subscription = $this->subscribe($member, 'active', ['ends_at' => now()->addDays(20)]);
        $before = $this->subscriptionSnapshot($subscription);
        Sanctum::actingAs(User::factory()->technicalAdmin()->create());

        $this->patchJson("/api/admin/users/{$member->id}/promote")
            ->assertOk()
            ->assertJsonPath('data.role.name', 'Admin');

        $this->assertEquals($before, $this->subscriptionSnapshot($subscription));
        $this->assertSame(1, $member->subscriptions()->count());
        $fresh = $member->fresh();
        $this->assertFalse($fresh->subscription_exempt);
        $this->assertFalse($fresh->isSubscriptionExempt());
    }

    #[Test]
    public function demoting_an_admin_keeps_the_subscription(): void
    {
        $admin = $this->adminWithProfile();
        $subscription = $this->subscribe($admin);
        $before = $this->subscriptionSnapshot($subscription);
        Sanctum::actingAs(User::factory()->technicalAdmin()->create());

        $this->patchJson("/api/admin/users/{$admin->id}/demote")
            ->assertOk()
            ->assertJsonPath('data.role.name', 'Member');

        $this->assertEquals($before, $this->subscriptionSnapshot($subscription));
        $this->assertSame(1, $admin->subscriptions()->count());
        $this->assertFalse($admin->fresh()->subscription_exempt);
    }
}
