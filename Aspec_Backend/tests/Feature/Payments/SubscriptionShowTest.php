<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Subscription;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET /api/subscription: o membro ativo consulta a própria subscrição e os dados de faturação.
 * Responde só com dados locais (mantidos pelos webhooks), nunca chama o Stripe.
 */
class SubscriptionShowTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/subscription';

    private const NOT_FOUND = 'Não existe subscrição.';

    private const DATA_KEYS = ['status', 'trial_ends_at', 'grace_ends_at', 'ends_at', 'price', 'billing'];

    private const BILLING_KEYS = ['billing_name', 'nif', 'billing_address', 'billing_postal_code', 'billing_city'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'subscription.price_amount' => 60.00,
            'subscription.currency' => 'eur',
            'subscription.interval' => 'month',
        ]);
    }

    private function member(): User
    {
        return User::factory()->withBilling()->create(['stripe_id' => 'cus_'.fake()->unique()->lexify('??????????')]);
    }

    private function subscriptionFor(User $user, string $status, array $attributes = []): Subscription
    {
        return $user->subscriptions()->create(array_merge([
            'type' => 'default',
            'stripe_id' => 'sub_'.fake()->unique()->lexify('??????????'),
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ], $attributes));
    }

    private function iso(CarbonInterface $date): string
    {
        return $date->toIso8601String();
    }

    #[Test]
    public function member_on_trial_sees_the_subscription_with_price_and_billing(): void
    {
        $this->freezeSecond();
        $member = $this->member();
        $trialEndsAt = now()->addDays(30);
        $this->subscriptionFor($member, 'trialing', ['trial_ends_at' => $trialEndsAt]);
        Sanctum::actingAs($member);

        $response = $this->getJson(self::URL);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Subscrição obtida com sucesso.')
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.trial_ends_at', $this->iso($trialEndsAt))
            ->assertJsonPath('data.grace_ends_at', null)
            ->assertJsonPath('data.ends_at', null)
            ->assertJsonPath('data.price.currency', 'eur')
            ->assertJsonPath('data.price.interval', 'month')
            ->assertJsonPath('data.billing', [
                'billing_name' => $member->billing_name,
                'nif' => $member->nif,
                'billing_address' => $member->billing_address,
                'billing_postal_code' => $member->billing_postal_code,
                'billing_city' => $member->billing_city,
            ]);
        $this->assertEquals(60, $response->json('data.price.amount'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $response->json('data.trial_ends_at'));
        $this->assertEqualsCanonicalizing(self::DATA_KEYS, array_keys($response->json('data')));
        $this->assertEqualsCanonicalizing(['amount', 'currency', 'interval'], array_keys($response->json('data.price')));
        $this->assertEqualsCanonicalizing(self::BILLING_KEYS, array_keys($response->json('data.billing')));
    }

    #[Test]
    public function member_in_grace_period_sees_past_due_and_the_grace_end(): void
    {
        $this->freezeSecond();
        $graceEndsAt = now()->addDays(7);
        $member = $this->member();
        $member->forceFill(['grace_ends_at' => $graceEndsAt])->save();
        $this->subscriptionFor($member, 'past_due');
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.status', 'past_due')
            ->assertJsonPath('data.grace_ends_at', $this->iso($graceEndsAt));
    }

    #[Test]
    public function reactivated_member_sees_the_new_subscription_not_the_canceled_one(): void
    {
        $member = $this->member();
        $this->subscriptionFor($member, 'canceled', ['ends_at' => now()->subMonth(), 'trial_ends_at' => now()->subMonths(2)]);
        $this->travel(1)->minutes();
        $this->subscriptionFor($member, 'active');
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.trial_ends_at', null)
            ->assertJsonPath('data.ends_at', null);
    }

    /**
     * Um duplicado cancelado pelo webhook é mais recente do que a subscrição verdadeira:
     * mostrar só "a mais recente" escondia a que está em curso.
     */
    #[Test]
    public function ongoing_subscription_wins_over_a_newer_canceled_duplicate(): void
    {
        $this->freezeSecond();
        $member = $this->member();
        $trialEndsAt = now()->addDays(30);
        $this->subscriptionFor($member, 'trialing', ['trial_ends_at' => $trialEndsAt]);
        $this->travel(1)->minutes();
        $this->subscriptionFor($member, 'canceled', ['ends_at' => now()]);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.trial_ends_at', $this->iso($trialEndsAt))
            ->assertJsonPath('data.ends_at', null);
    }

    #[Test]
    public function member_with_only_a_canceled_subscription_sees_it_with_its_end_date(): void
    {
        $this->freezeSecond();
        $endsAt = now()->subDay();
        $member = $this->member();
        $this->subscriptionFor($member, 'canceled', ['ends_at' => $endsAt]);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled')
            ->assertJsonPath('data.ends_at', $this->iso($endsAt));
    }

    /**
     * Duas subscrições criadas no mesmo segundo empatam no created_at; o id desempata.
     */
    #[Test]
    public function subscriptions_created_in_the_same_second_are_ordered_by_id(): void
    {
        $this->freezeSecond();
        $member = $this->member();
        $first = $this->subscriptionFor($member, 'active');
        $second = $this->subscriptionFor($member, 'trialing', ['trial_ends_at' => now()->addDays(30)]);
        $this->assertTrue($second->id > $first->id);
        $this->assertEquals($first->created_at, $second->created_at);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.status', 'trialing');
    }

    #[Test]
    public function admin_without_subscription_gets_not_found(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson(self::URL)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => self::NOT_FOUND]);
    }

    #[Test]
    public function active_member_without_any_subscription_gets_not_found(): void
    {
        Sanctum::actingAs(User::factory()->withBilling()->create());

        $this->getJson(self::URL)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => self::NOT_FOUND]);
    }

    #[Test]
    public function guest_is_unauthenticated(): void
    {
        $this->getJson(self::URL)
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Não autenticado.']);
    }

    public static function accountsWithoutAccess(): array
    {
        return [
            'approved' => ['approved'],
            'pending' => ['pending'],
            'inactive unpaid' => ['unpaid'],
        ];
    }

    #[Test]
    #[DataProvider('accountsWithoutAccess')]
    public function accounts_that_are_not_active_are_forbidden(string $state): void
    {
        $user = match ($state) {
            'approved' => User::factory()->approved()->withBilling()->create(),
            'pending' => User::factory()->pending()->create(),
            'unpaid' => User::factory()->inactive(InactiveReason::Unpaid)->withBilling()->create(),
        };
        $this->subscriptionFor($user, 'canceled', ['ends_at' => now()]);
        Sanctum::actingAs($user);

        $this->getJson(self::URL)
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'A conta não está ativa e não pode editar dados.']);
    }

    #[Test]
    public function response_never_exposes_stripe_ids_card_data_or_internal_ids(): void
    {
        $member = $this->member();
        $member->forceFill([
            'stripe_id' => 'cus_segredo_1',
            'pm_type' => 'visa',
            'pm_last_four' => '4242',
            'stripe_checkout_session_id' => 'cs_segredo_1',
            'billing_name' => 'Empresa A Lda',
            'billing_address' => 'Rua A 1',
        ])->save();
        $this->subscriptionFor($member, 'active', ['stripe_id' => 'sub_segredo_1', 'stripe_price' => 'price_segredo_1']);
        Sanctum::actingAs($member);

        $response = $this->getJson(self::URL)->assertOk();

        $this->assertEqualsCanonicalizing(self::DATA_KEYS, array_keys($response->json('data')));
        $this->assertEqualsCanonicalizing(self::BILLING_KEYS, array_keys($response->json('data.billing')));
        $content = $response->getContent();
        foreach (['cus_segredo_1', 'sub_segredo_1', 'price_segredo_1', 'cs_segredo_1', '4242', 'visa', $member->id] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
        foreach (['stripe_id', 'stripe_price', 'pm_type', 'pm_last_four', 'stripe_checkout_session_id', 'user_id', '"id"'] as $key) {
            $this->assertStringNotContainsString($key, $content);
        }
    }

    #[Test]
    public function member_only_sees_their_own_subscription_and_billing(): void
    {
        $memberA = $this->member();
        $memberB = User::factory()->create([
            'stripe_id' => 'cus_membro_b',
            'billing_name' => 'Empresa B Lda',
            'nif' => '999999990',
            'billing_address' => 'Rua do Membro B 1',
            'billing_postal_code' => '4000-001',
            'billing_city' => 'Porto',
        ]);
        $this->subscriptionFor($memberA, 'active');
        $this->subscriptionFor($memberB, 'past_due');
        Sanctum::actingAs($memberA);

        $response = $this->getJson(self::URL)->assertOk();

        $response->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.billing.billing_name', $memberA->billing_name)
            ->assertJsonPath('data.billing.nif', $memberA->nif);
        foreach (['Empresa B Lda', '999999990', 'Rua do Membro B 1', '4000-001', 'Porto'] as $otherData) {
            $this->assertStringNotContainsString($otherData, $response->getContent());
        }
    }
}
