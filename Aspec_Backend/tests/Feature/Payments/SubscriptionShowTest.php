<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Models\Invoice;
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

    private const DATA_KEYS = ['status', 'trial_starts_at', 'trial_ends_at', 'trial_days', 'next_payment_at', 'grace_ends_at', 'ends_at', 'cancel_at_period_end', 'price', 'billing'];

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
            ->assertJsonPath('data.cancel_at_period_end', false)
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

    /**
     * O frontend decide entre "Cancelar" e "Retomar" por esta flag, sem interpretar o ends_at.
     */
    #[Test]
    public function scheduled_cancellation_is_flagged_while_the_period_has_not_ended(): void
    {
        $this->freezeSecond();
        $endsAt = now()->addDays(20);
        $member = $this->member();
        $this->subscriptionFor($member, 'active', ['ends_at' => $endsAt]);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.ends_at', $this->iso($endsAt))
            ->assertJsonPath('data.cancel_at_period_end', true);
    }

    #[Test]
    public function scheduled_cancellation_during_the_trial_is_flagged(): void
    {
        $this->freezeSecond();
        $trialEndsAt = now()->addDays(10);
        $member = $this->member();
        $this->subscriptionFor($member, 'trialing', ['trial_ends_at' => $trialEndsAt, 'ends_at' => $trialEndsAt]);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.cancel_at_period_end', true);
    }

    #[Test]
    public function running_subscription_without_end_date_is_not_flagged(): void
    {
        $member = $this->member();
        $this->subscriptionFor($member, 'active');
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.cancel_at_period_end', false);
    }

    #[Test]
    public function ended_subscription_is_not_flagged_as_a_scheduled_cancellation(): void
    {
        $member = $this->member();
        $this->subscriptionFor($member, 'canceled', ['ends_at' => now()->subDay()]);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.cancel_at_period_end', false);
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

    // --- Início e duração do período experimental ---

    #[Test]
    public function trial_start_is_the_subscription_creation_and_the_duration_is_returned(): void
    {
        $this->travelTo('2026-10-01 14:03:11');
        $member = $this->member();
        $startedAt = now()->toImmutable();
        $this->subscriptionFor($member, 'trialing', ['trial_ends_at' => $startedAt->addDays(30)]);
        $this->travel(3)->days();
        Sanctum::actingAs($member);

        $response = $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.trial_starts_at', $this->iso($startedAt))
            ->assertJsonPath('data.trial_ends_at', $this->iso($startedAt->addDays(30)))
            ->assertJsonPath('data.trial_days', 30);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', (string) $response->json('data.trial_starts_at'));
    }

    /**
     * Quem já está em trial mantém a duração com que começou, mesmo que a configuração mude.
     */
    #[Test]
    public function trial_duration_comes_from_the_subscription_dates_not_from_the_config(): void
    {
        $this->travelTo('2026-10-01 14:03:11');
        config(['subscription.trial_days' => 30]);
        $member = $this->member();
        $this->subscriptionFor($member, 'trialing', ['trial_ends_at' => now()->addDays(60)]);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.trial_days', 60);
    }

    /**
     * A linha local é criada pelo webhook segundos depois de o Stripe começar a contar o trial.
     */
    #[Test]
    public function webhook_delay_is_rounded_away_in_the_trial_duration(): void
    {
        $this->travelTo('2026-10-01 14:03:11');
        $member = $this->member();
        $this->subscriptionFor($member, 'trialing', ['trial_ends_at' => now()->addDays(30)->subSeconds(40)]);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.trial_days', 30);
    }

    #[Test]
    public function reactivated_subscription_without_trial_has_no_trial_fields(): void
    {
        $this->travelTo('2026-10-01 14:03:11');
        $member = $this->member();
        $this->subscriptionFor($member, 'active');
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.trial_starts_at', null)
            ->assertJsonPath('data.trial_ends_at', null)
            ->assertJsonPath('data.trial_days', null);
    }

    #[Test]
    public function finished_trial_dates_are_still_returned(): void
    {
        $this->travelTo('2026-08-01 09:00:00');
        $member = $this->member();
        $startedAt = now()->toImmutable();
        $this->subscriptionFor($member, 'active', ['trial_ends_at' => $startedAt->addDays(30)]);
        $this->travelTo('2026-10-01 09:00:00');
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.trial_starts_at', $this->iso($startedAt))
            ->assertJsonPath('data.trial_ends_at', $this->iso($startedAt->addDays(30)))
            ->assertJsonPath('data.trial_days', 30);
    }

    #[Test]
    public function grace_period_right_after_the_trial_keeps_the_trial_fields(): void
    {
        $this->travelTo('2026-09-01 09:00:00');
        $member = $this->member();
        $startedAt = now()->toImmutable();
        $this->subscriptionFor($member, 'past_due', ['trial_ends_at' => $startedAt->addDays(30)]);
        $this->travelTo('2026-10-02 09:00:00');
        $graceEndsAt = now()->addDays(7);
        $member->forceFill(['grace_ends_at' => $graceEndsAt])->save();
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.status', 'past_due')
            ->assertJsonPath('data.grace_ends_at', $this->iso($graceEndsAt))
            ->assertJsonPath('data.trial_starts_at', $this->iso($startedAt))
            ->assertJsonPath('data.trial_days', 30);
    }

    #[Test]
    public function data_has_exactly_the_contract_keys_including_the_trial_fields(): void
    {
        $this->travelTo('2026-10-01 14:03:11');
        $member = $this->member();
        $this->subscriptionFor($member, 'trialing', ['trial_ends_at' => now()->addDays(30)]);
        Sanctum::actingAs($member);

        $response = $this->getJson(self::URL)->assertOk();

        $this->assertEqualsCanonicalizing(self::DATA_KEYS, array_keys($response->json('data')));
    }

    // --- Próximo pagamento ---

    #[Test]
    public function next_payment_during_the_trial_is_the_trial_end(): void
    {
        $this->travelTo('2026-10-01 14:03:11');
        $member = $this->member();
        $trialEndsAt = now()->addDays(30);
        $this->subscriptionFor($member, 'trialing', ['trial_ends_at' => $trialEndsAt]);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.next_payment_at', $this->iso($trialEndsAt));
    }

    #[Test]
    public function next_payment_of_an_active_subscription_is_one_month_after_the_last_invoice(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $member = $this->member();
        $this->subscriptionFor($member, 'active');
        Invoice::factory()->for($member)->demo()->issued()->create(['created_at' => '2026-09-15 08:30:00']);
        Invoice::factory()->for($member)->demo()->issued()->create(['created_at' => '2026-10-15 08:30:00']);
        Invoice::factory()->for($member)->demo()->issued()->create(['created_at' => '2026-08-15 08:30:00']);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.next_payment_at', '2026-11-15T08:30:00+00:00');
    }

    /**
     * A linha da fatura nasce quando o Stripe confirma o pagamento; a emissão vem depois.
     */
    #[Test]
    public function invoice_still_being_issued_already_counts_as_the_last_payment(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $member = $this->member();
        $this->subscriptionFor($member, 'active');
        Invoice::factory()->for($member)->demo()->issued()->create(['created_at' => '2026-09-15 08:30:00']);
        Invoice::factory()->for($member)->create(['created_at' => '2026-10-15 08:30:00']);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.next_payment_at', '2026-11-15T08:30:00+00:00');
    }

    #[Test]
    public function next_payment_follows_the_configured_interval(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        config(['subscription.interval' => 'year']);
        $member = $this->member();
        $this->subscriptionFor($member, 'active');
        Invoice::factory()->for($member)->demo()->issued()->create(['created_at' => '2026-10-15 08:30:00']);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.next_payment_at', '2027-10-15T08:30:00+00:00');
    }

    #[Test]
    public function other_members_invoices_never_set_the_next_payment(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $member = $this->member();
        $this->subscriptionFor($member, 'active');
        Invoice::factory()->for($member)->demo()->issued()->create(['created_at' => '2026-09-15 08:30:00']);
        Invoice::factory()->for($this->member())->demo()->issued()->create(['created_at' => '2026-10-18 12:00:00']);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.next_payment_at', '2026-10-15T08:30:00+00:00');
    }

    #[Test]
    public function active_subscription_without_invoices_has_no_next_payment(): void
    {
        $member = $this->member();
        $this->subscriptionFor($member, 'active');
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.next_payment_at', null);
    }

    #[Test]
    public function scheduled_cancellation_has_no_next_payment(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $member = $this->member();
        $this->subscriptionFor($member, 'active', ['ends_at' => now()->addDays(25)]);
        Invoice::factory()->for($member)->demo()->issued()->create(['created_at' => '2026-10-15 08:30:00']);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.cancel_at_period_end', true)
            ->assertJsonPath('data.next_payment_at', null);
    }

    /**
     * Cancelar durante o trial termina a subscrição no fim do trial, sem nenhuma cobrança.
     */
    #[Test]
    public function scheduled_cancellation_during_the_trial_has_no_next_payment(): void
    {
        $this->travelTo('2026-10-01 14:03:11');
        $member = $this->member();
        $trialEndsAt = now()->addDays(30);
        $this->subscriptionFor($member, 'trialing', ['trial_ends_at' => $trialEndsAt, 'ends_at' => $trialEndsAt]);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.cancel_at_period_end', true)
            ->assertJsonPath('data.trial_ends_at', $this->iso($trialEndsAt))
            ->assertJsonPath('data.trial_days', 30)
            ->assertJsonPath('data.next_payment_at', null);
    }

    public static function statusesWithoutNextPayment(): array
    {
        return [
            'past_due' => ['past_due', null],
            'unpaid' => ['unpaid', null],
            'incomplete' => ['incomplete', null],
            'canceled' => ['canceled', '2026-10-19 10:00:00'],
        ];
    }

    #[Test]
    #[DataProvider('statusesWithoutNextPayment')]
    public function subscription_that_is_not_trialing_or_active_has_no_next_payment(string $status, ?string $endsAt): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $member = $this->member();
        $this->subscriptionFor($member, $status, ['ends_at' => $endsAt]);
        Invoice::factory()->for($member)->demo()->issued()->create(['created_at' => '2026-10-15 08:30:00']);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.status', $status)
            ->assertJsonPath('data.next_payment_at', null);
    }
}
