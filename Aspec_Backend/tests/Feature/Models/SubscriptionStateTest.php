<?php

namespace Tests\Feature\Models;

use App\Enums\InactiveReason;
use App\Enums\SubscriptionState;
use App\Models\AccountStatus;
use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use UnhandledMatchError;

/**
 * Estado da subscrição derivado das colunas de users + papel + perfil (lista do backoffice).
 * Tempo congelado ao segundo: as colunas datetime guardam segundos, por isso a fronteira do trial é exata.
 */
class SubscriptionStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
    }

    /**
     * Um utilizador por situação da tabela de estados, com o valor esperado.
     *
     * @return array<string, array{user: User, expected: string, trashed: bool}>
     */
    private function createFixtures(): array
    {
        $fixtures = [];
        $add = function (string $key, User $user, string $expected, bool $trashed = false) use (&$fixtures) {
            $fixtures[$key] = ['user' => $user, 'expected' => $expected, 'trashed' => $trashed];
        };

        $add('pending', User::factory()->pending()->create(), 'pending');
        $add('approved', User::factory()->approved()->create(), 'awaiting_activation');
        $add('approved_with_leftover_reason', User::factory()->approved()->create(['inactive_reason' => InactiveReason::Blocked]), 'awaiting_activation');

        $add('active_trial_future', User::factory()->create(['trial_ends_at' => now()->addDays(10)]), 'trial');
        $add('active_trial_ends_now', User::factory()->create(['trial_ends_at' => now()]), 'active');
        $add('active_trial_ended', User::factory()->create(['trial_ends_at' => now()->subDay()]), 'active');
        $add('active_without_trial', User::factory()->create(), 'active');
        $add('active_with_leftover_reason', User::factory()->create(['inactive_reason' => InactiveReason::Blocked]), 'active');

        $add('active_grace', User::factory()->create(['grace_ends_at' => now()->addDays(3)]), 'grace');
        $add('active_grace_and_trial', User::factory()->create([
            'grace_ends_at' => now()->addDays(3),
            'trial_ends_at' => now()->addDays(5),
        ]), 'grace');
        $add('active_grace_expired_not_processed', User::factory()->create(['grace_ends_at' => now()->subHour()]), 'grace');

        $scheduledCancellation = User::factory()->create();
        $scheduledCancellation->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_scheduled',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'ends_at' => now()->addDays(20),
        ]);
        $add('active_scheduled_cancellation', $scheduledCancellation, 'active');

        $add('inactive_unpaid', User::factory()->inactive(InactiveReason::Unpaid)->create(), 'lapsed');
        $add('inactive_cancelled', User::factory()->inactive(InactiveReason::Cancelled)->create(), 'lapsed');
        $add('inactive_blocked', User::factory()->inactive(InactiveReason::Blocked)->create(), 'blocked');
        $add('inactive_rejected', User::factory()->inactive(InactiveReason::Rejected)->create(), 'inactive');
        $add('inactive_without_reason', User::factory()->inactive()->create(), 'inactive');

        $anonymised = User::factory()->inactive(InactiveReason::Deleted)->create();
        $anonymised->delete();
        $add('inactive_deleted_trashed', $anonymised, 'inactive', trashed: true);

        $add('admin_without_profile', User::factory()->admin()->create(), 'exempt');
        $add('admin_without_profile_in_grace', User::factory()->admin()->create(['grace_ends_at' => now()->addDays(3)]), 'exempt');

        $adminWithProfile = User::factory()->admin()->create(['trial_ends_at' => now()->addDays(10)]);
        MemberProfile::factory()->create(['user_id' => $adminWithProfile->id]);
        $add('admin_with_profile_in_trial', $adminWithProfile, 'trial');

        $adminWithDeletedProfile = User::factory()->admin()->create();
        MemberProfile::factory()->create(['user_id' => $adminWithDeletedProfile->id])->delete();
        $add('admin_with_soft_deleted_profile', $adminWithDeletedProfile, 'exempt');

        return $fixtures;
    }

    private function loadFresh(User $user): User
    {
        return User::withTrashed()
            ->with(['role', 'accountStatus', 'memberProfile'])
            ->findOrFail($user->id);
    }

    #[Test]
    public function enum_has_the_nine_states_in_precedence_order_with_json_values(): void
    {
        $this->assertSame(
            ['pending', 'awaiting_activation', 'blocked', 'lapsed', 'inactive', 'exempt', 'grace', 'trial', 'active'],
            array_map(fn (SubscriptionState $state) => $state->value, SubscriptionState::cases()),
        );
    }

    #[Test]
    public function every_state_has_its_exact_portuguese_label(): void
    {
        $expected = [
            'pending' => 'Pendente',
            'awaiting_activation' => 'Por ativar',
            'blocked' => 'Bloqueado',
            'lapsed' => 'Subscrição caída',
            'inactive' => 'Inativo',
            'exempt' => 'Isento',
            'grace' => 'Em carência',
            'trial' => 'Em trial',
            'active' => 'Ativo',
        ];

        foreach (SubscriptionState::cases() as $state) {
            $this->assertSame($expected[$state->value], $state->label(), "Label de {$state->value}");
        }
    }

    #[Test]
    public function php_state_matches_the_expected_state_for_every_fixture(): void
    {
        foreach ($this->createFixtures() as $key => $fixture) {
            $this->assertSame(
                $fixture['expected'],
                $this->loadFresh($fixture['user'])->subscriptionState()->value,
                "Fixture {$key}",
            );
        }
    }

    #[Test]
    public function sql_scope_returns_exactly_the_users_expected_in_each_state(): void
    {
        $fixtures = $this->createFixtures();

        foreach (SubscriptionState::cases() as $state) {
            $expectedIds = collect($fixtures)
                ->filter(fn (array $fixture) => $fixture['expected'] === $state->value && ! $fixture['trashed'])
                ->map(fn (array $fixture) => $fixture['user']->id)
                ->values()
                ->all();

            $this->assertEqualsCanonicalizing(
                $expectedIds,
                User::query()->inSubscriptionState($state)->pluck('id')->all(),
                "Scope {$state->value}",
            );
        }
    }

    #[Test]
    public function anonymised_user_appears_in_the_inactive_scope_only_with_trashed(): void
    {
        $anonymised = User::factory()->inactive(InactiveReason::Deleted)->create();
        $anonymised->delete();

        $this->assertFalse(User::query()->inSubscriptionState(SubscriptionState::Inactive)->whereKey($anonymised->id)->exists());
        $this->assertTrue(User::withTrashed()->inSubscriptionState(SubscriptionState::Inactive)->whereKey($anonymised->id)->exists());
    }

    #[Test]
    public function php_and_sql_agree_on_exactly_one_state_for_every_user(): void
    {
        $this->createFixtures();

        $users = User::withTrashed()->with(['role', 'accountStatus', 'memberProfile'])->get();
        $this->assertNotEmpty($users);

        foreach ($users as $user) {
            $matchingStates = array_values(array_filter(
                SubscriptionState::cases(),
                fn (SubscriptionState $state) => User::withTrashed()->inSubscriptionState($state)->whereKey($user->id)->exists(),
            ));

            $this->assertCount(1, $matchingStates, "Utilizador {$user->email} tem de estar em exatamente um scope");
            $this->assertSame($user->subscriptionState(), $matchingStates[0], "PHP e SQL divergem para {$user->email}");
        }
    }

    #[Test]
    public function every_state_is_covered_by_at_least_one_fixture(): void
    {
        $covered = collect($this->createFixtures())->pluck('expected')->unique()->all();

        foreach (SubscriptionState::cases() as $state) {
            $this->assertContains($state->value, $covered, "Falta uma fixture para {$state->value}");
        }
    }

    #[Test]
    public function counts_have_all_nine_keys_and_partition_every_user(): void
    {
        $fixtures = $this->createFixtures();

        $counts = User::subscriptionStateCounts(User::query());

        $this->assertSame(
            array_map(fn (SubscriptionState $state) => $state->value, SubscriptionState::cases()),
            array_keys($counts),
        );
        $this->assertSame(User::count(), array_sum($counts));

        $expected = collect($fixtures)
            ->reject(fn (array $fixture) => $fixture['trashed'])
            ->countBy('expected')
            ->all();
        foreach (SubscriptionState::cases() as $state) {
            $this->assertSame($expected[$state->value] ?? 0, $counts[$state->value], "Contagem de {$state->value}");
        }
    }

    #[Test]
    public function counts_include_zeros_for_empty_states(): void
    {
        User::factory()->pending()->create();

        $counts = User::subscriptionStateCounts(User::query());

        $this->assertCount(9, $counts);
        $this->assertSame(1, $counts['pending']);
        foreach (SubscriptionState::cases() as $state) {
            if ($state !== SubscriptionState::Pending) {
                $this->assertSame(0, $counts[$state->value], "Contagem de {$state->value}");
            }
        }
    }

    #[Test]
    public function counts_respect_a_base_query_without_admins(): void
    {
        $this->createFixtures();

        $membersOnly = User::query()->whereHas('role', fn (Builder $query) => $query->where('name', 'Member'));

        $counts = User::subscriptionStateCounts($membersOnly);

        $this->assertSame(0, $counts['exempt']);
        $this->assertSame((clone $membersOnly)->count(), array_sum($counts));
    }

    #[Test]
    public function counts_respect_a_base_query_searching_by_email(): void
    {
        $this->createFixtures();
        $target = User::factory()->create([
            'email' => 'alvo.carencia@example.test',
            'grace_ends_at' => now()->addDays(2),
        ]);

        $counts = User::subscriptionStateCounts(User::query()->where('email', $target->email));

        $this->assertSame(1, array_sum($counts));
        $this->assertSame(1, $counts['grace']);
    }

    #[Test]
    public function trial_becomes_active_one_second_after_it_ends_in_php_and_sql(): void
    {
        $user = User::factory()->create(['trial_ends_at' => now()->addDays(10)]);
        $this->assertSame(SubscriptionState::Trial, $this->loadFresh($user)->subscriptionState());

        $this->travelTo($user->trial_ends_at->copy()->addSecond());

        $this->assertSame(SubscriptionState::Active, $this->loadFresh($user)->subscriptionState());
        $this->assertTrue(User::query()->inSubscriptionState(SubscriptionState::Active)->whereKey($user->id)->exists());
        $this->assertFalse(User::query()->inSubscriptionState(SubscriptionState::Trial)->whereKey($user->id)->exists());
    }

    #[Test]
    public function subscription_exempt_rule_is_the_same_in_php_and_sql(): void
    {
        $fixtures = $this->createFixtures();

        $exemptIds = User::query()->subscriptionExempt()->pluck('id')->all();

        foreach ($fixtures as $key => $fixture) {
            if ($fixture['trashed']) {
                continue;
            }
            $user = $this->loadFresh($fixture['user']);
            $this->assertSame(
                in_array($user->id, $exemptIds, true),
                $user->isSubscriptionExempt(),
                "Regra de isenção diverge em {$key}",
            );
        }
        $this->assertTrue($this->loadFresh($fixtures['admin_without_profile']['user'])->isSubscriptionExempt());
        $this->assertFalse($this->loadFresh($fixtures['admin_with_profile_in_trial']['user'])->isSubscriptionExempt());
        $this->assertFalse($this->loadFresh($fixtures['active_without_trial']['user'])->isSubscriptionExempt());
    }

    #[Test]
    public function unknown_account_status_fails_loudly(): void
    {
        $status = AccountStatus::create(['name' => 'Corrupted']);
        $user = User::factory()->create(['account_status_id' => $status->id]);

        $this->expectException(UnhandledMatchError::class);

        $this->loadFresh($user)->subscriptionState();
    }

    #[Test]
    public function state_with_relations_loaded_runs_no_queries(): void
    {
        User::factory()->count(4)->create(['trial_ends_at' => now()->addDays(5)]);
        User::factory()->count(3)->inactive(InactiveReason::Unpaid)->create();
        User::factory()->count(2)->admin()->create();
        MemberProfile::factory()->create();

        $users = User::with(['role', 'accountStatus', 'memberProfile'])->get();
        $this->assertCount(10, $users);

        DB::flushQueryLog();
        DB::enableQueryLog();
        foreach ($users as $user) {
            $user->subscriptionState();
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries);
    }
}
