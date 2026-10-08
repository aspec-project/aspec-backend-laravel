<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Cashier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CashierSetupTest extends TestCase
{
    use RefreshDatabase;

    private function subscriptionFor(User $user, string $status, array $extra = []): void
    {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_'.$status,
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
            ...$extra,
        ]);
    }

    private function userWithBilling(): User
    {
        $user = User::factory()->create(['phone' => '912345678']);
        $user->forceFill([
            'billing_name' => 'Silva Contabilidade Lda',
            'nif' => '245678901',
            'billing_address' => 'Rua da Faturação 10',
            'billing_postal_code' => '1000-001',
            'billing_city' => 'Lisboa',
        ])->save();

        return $user->fresh();
    }

    #[Test]
    public function users_table_has_the_cashier_customer_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at']));
    }

    #[Test]
    public function subscriptions_and_subscription_items_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('subscriptions'));
        $this->assertTrue(Schema::hasTable('subscription_items'));
        $this->assertTrue(Schema::hasColumns('subscription_items', ['meter_id', 'meter_event_name']));
    }

    #[Test]
    public function subscription_belongs_to_a_user_with_uuid_key(): void
    {
        $user = User::factory()->create();

        $subscription = $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_1',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        $this->assertSame($user->id, $subscription->fresh()->user_id);
        $this->assertTrue($subscription->fresh()->owner->is($user));
    }

    #[Test]
    public function user_with_subscription_cannot_be_hard_deleted(): void
    {
        // Os pagamentos são histórico: apagar de vez um membro com subscrição tem de falhar.
        $user = User::factory()->create();
        $this->subscriptionFor($user, 'active');

        $this->expectException(QueryException::class);

        $user->forceDelete();
    }

    #[Test]
    public function past_due_subscription_keeps_the_member_subscribed_during_grace(): void
    {
        $user = User::factory()->create();
        $this->subscriptionFor($user, 'past_due');

        $this->assertTrue($user->fresh()->subscribed());
    }

    #[Test]
    public function incomplete_subscription_is_not_subscribed(): void
    {
        $user = User::factory()->create();
        $this->subscriptionFor($user, 'incomplete');

        $this->assertFalse($user->fresh()->subscribed());
    }

    #[Test]
    public function canceled_subscription_that_already_ended_is_not_subscribed(): void
    {
        $user = User::factory()->create();
        $this->subscriptionFor($user, 'canceled', ['ends_at' => now()->subDay()]);

        $this->assertFalse($user->fresh()->subscribed());
    }

    #[Test]
    public function cashier_webhook_route_is_not_registered(): void
    {
        $this->postJson('/stripe/webhook')->assertNotFound();

        $this->assertFalse(Route::has('cashier.webhook'));
    }

    #[Test]
    public function cashier_payment_page_route_is_not_registered(): void
    {
        $this->getJson('/stripe/payment/pi_test_123')->assertNotFound();

        $this->assertFalse(Route::has('cashier.payment'));
    }

    #[Test]
    public function cashier_uses_the_fake_test_keys(): void
    {
        $this->assertSame('sk_test_fake', config('cashier.secret'));
        $this->assertSame('whsec_test', config('cashier.webhook.secret'));
        $this->assertSame('eur', config('cashier.currency'));
    }

    #[Test]
    public function stripe_api_url_points_away_from_stripe_in_tests(): void
    {
        $this->assertStringNotContainsString('stripe.com', Cashier::$apiBaseUrl);
    }

    #[Test]
    public function subscription_config_has_the_business_values(): void
    {
        $this->assertSame('price_test', config('subscription.price_id'));
        $this->assertSame(60.0, config('subscription.price_amount'));
        $this->assertSame('eur', config('subscription.currency'));
        $this->assertSame('month', config('subscription.interval'));
        $this->assertSame(30, config('subscription.trial_days'));
        $this->assertSame(7, config('subscription.grace_days'));
        $this->assertSame(7, config('subscription.activation_link_days'));
        $this->assertSame(7, config('subscription.reactivation_link_days'));
    }

    #[Test]
    public function stripe_name_is_the_billing_name(): void
    {
        $this->assertSame('Silva Contabilidade Lda', $this->userWithBilling()->stripeName());
    }

    #[Test]
    public function stripe_address_is_the_portuguese_billing_address(): void
    {
        $this->assertSame([
            'line1' => 'Rua da Faturação 10',
            'postal_code' => '1000-001',
            'city' => 'Lisboa',
            'country' => 'PT',
        ], $this->userWithBilling()->stripeAddress());
    }

    #[Test]
    public function stripe_address_is_empty_without_billing_data(): void
    {
        $this->assertSame([], User::factory()->create()->fresh()->stripeAddress());
    }

    #[Test]
    public function stripe_phone_is_never_sent_even_when_the_user_has_a_phone(): void
    {
        $user = $this->userWithBilling();

        $this->assertSame('912345678', $user->phone);
        $this->assertNull($user->stripePhone());
    }

    #[Test]
    public function stripe_email_is_the_account_email(): void
    {
        $user = User::factory()->create();

        $this->assertSame($user->email, $user->stripeEmail());
    }
}
