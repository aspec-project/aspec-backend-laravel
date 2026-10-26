<?php

namespace Tests\Feature\Admin;

use App\Enums\InactiveReason;
use App\Models\AccountStatus;
use App\Models\Location;
use App\Models\MemberProfile;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminUserIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['email' => 'zz-admin@example.com']);
        Sanctum::actingAs($this->admin);
    }

    #[Test]
    public function admins_are_hidden_from_the_list_by_default(): void
    {
        $members = User::factory()->count(2)->create();

        $response = $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Lista de utilizadores obtida com sucesso.')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.total', 2);

        $this->assertEqualsCanonicalizing($members->pluck('id')->all(), $this->listedIds($response));
        $this->assertNotContains('Admin', collect($response->json('data.items'))->pluck('role.name')->all());
    }

    #[Test]
    public function include_admins_lists_admins_first_and_then_members_by_name(): void
    {
        User::factory()->admin()->create(['email' => 'aa-admin@example.com']);
        $this->member(['email' => 'a@example.com'], ['name' => 'Bruno']);
        $this->member(['email' => 'z@example.com'], ['name' => 'Ana']);

        $response = $this->getJson('/api/admin/users?include_admins=1')
            ->assertOk()
            ->assertJsonCount(4, 'data.items');

        $this->assertSame(
            ['aa-admin@example.com', 'zz-admin@example.com', 'z@example.com', 'a@example.com'],
            $this->listedEmails($response)
        );
        $this->assertSame(['Admin', 'Admin', 'Member', 'Member'], collect($response->json('data.items'))->pluck('role.name')->all());
    }

    #[Test]
    public function include_admins_false_keeps_admins_hidden(): void
    {
        $members = User::factory()->count(2)->create();

        $response = $this->getJson('/api/admin/users?include_admins=0')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.total', 2);

        $this->assertEqualsCanonicalizing($members->pluck('id')->all(), $this->listedIds($response));
    }

    #[Test]
    public function include_admins_must_be_a_boolean(): void
    {
        $this->getJson('/api/admin/users?include_admins=talvez')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.include_admins.0', 'O campo mostrar administradores tem de ser verdadeiro ou falso.');
    }

    #[Test]
    public function list_item_exposes_only_the_admin_summary_fields(): void
    {
        $sector = Sector::query()->firstOrFail();
        $location = Location::query()->firstOrFail();
        $user = User::factory()->withBilling()->create([
            'email' => 'ana@techbless.pt',
            'stripe_id' => 'cus_test_123',
            'pm_last_four' => '4242',
        ]);
        MemberProfile::factory()->for($user)->create([
            'name' => 'Ana Costa',
            'business_name' => 'TechBless Solutions',
            'sector_id' => $sector->id,
            'location_id' => $location->id,
        ]);

        $item = $this->itemFor($this->getJson('/api/admin/users')->assertOk(), $user->id);

        foreach (['id', 'email', 'created_at', 'role', 'account_status', 'inactive_reason', 'member_profile', 'subscription_state'] as $key) {
            $this->assertArrayHasKey($key, $item);
        }
        $this->assertSame($user->id, data_get($item, 'id'));
        $this->assertSame('ana@techbless.pt', data_get($item, 'email'));
        $this->assertSame($user->created_at->toIso8601String(), data_get($item, 'created_at'));
        $this->assertSame($user->role_id, data_get($item, 'role.id'));
        $this->assertSame('Member', data_get($item, 'role.name'));
        $this->assertSame($user->account_status_id, data_get($item, 'account_status.id'));
        $this->assertSame('Active', data_get($item, 'account_status.name'));
        $this->assertSame('active', data_get($item, 'subscription_state.value'));
        $this->assertSame('Ativo', data_get($item, 'subscription_state.label'));
        $this->assertNull($item['inactive_reason']);
        $this->assertSame('Ana Costa', data_get($item, 'member_profile.name'));
        $this->assertSame('TechBless Solutions', data_get($item, 'member_profile.business_name'));
        $this->assertSame($sector->id, data_get($item, 'member_profile.sector.id'));
        $this->assertSame($sector->name, data_get($item, 'member_profile.sector.name'));
        $this->assertSame($location->id, data_get($item, 'member_profile.location.id'));
        $this->assertSame($location->name, data_get($item, 'member_profile.location.name'));
        $this->assertArrayNotHasKey('phone', $item);
        $this->assertArrayNotHasKey('password', $item);
        $this->assertArrayNotHasKey('nif', $item);
        $this->assertArrayNotHasKey('billing_name', $item);
        $this->assertArrayNotHasKey('billing_address', $item);
        $this->assertArrayNotHasKey('stripe_id', $item);
        $this->assertArrayNotHasKey('pm_last_four', $item);
        $this->assertArrayNotHasKey('remember_token', $item);
    }

    #[Test]
    public function member_without_profile_has_null_member_profile(): void
    {
        $user = User::factory()->create();

        $item = $this->itemFor($this->getJson('/api/admin/users')->assertOk(), $user->id);

        $this->assertArrayHasKey('member_profile', $item);
        $this->assertNull($item['member_profile']);
    }

    #[Test]
    public function list_item_shows_the_inactive_reason(): void
    {
        $blocked = User::factory()->inactive(InactiveReason::Blocked)->create();
        $active = User::factory()->create();

        $response = $this->getJson('/api/admin/users')->assertOk();
        $blockedItem = $this->itemFor($response, $blocked->id);
        $activeItem = $this->itemFor($response, $active->id);

        $this->assertArrayHasKey('inactive_reason', $blockedItem);
        $this->assertArrayHasKey('inactive_reason', $activeItem);
        $this->assertSame('blocked', $blockedItem['inactive_reason']);
        $this->assertNull($activeItem['inactive_reason']);
    }

    #[Test]
    public function subscription_state_filter_lists_matching_users_and_counts_all_states(): void
    {
        $blocked = User::factory()
            ->inactive(InactiveReason::Blocked)
            ->create();

        User::factory()->inactive(InactiveReason::Rejected)->create();
        User::factory()->inactive(InactiveReason::Unpaid)->create();
        User::factory()->create();

        $response = $this->getJson('/api/admin/users?subscription_state=blocked')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.subscription_state.value', 'blocked')
            ->assertJsonPath('data.subscription_state_counts.blocked', 1)
            ->assertJsonPath('data.subscription_state_counts.inactive', 1)
            ->assertJsonPath('data.subscription_state_counts.lapsed', 1)
            ->assertJsonPath('data.subscription_state_counts.active', 1);

        $this->assertSame([$blocked->id], $this->listedIds($response));
    }

    #[Test]
    public function subscription_state_filter_must_be_valid(): void
    {
        $this->getJson('/api/admin/users?subscription_state=not-a-state')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subscription_state']);
    }

    #[Test]
    public function list_returns_subscription_state_counts_including_admins_when_requested(): void
    {
        $this->createTabScenario();
        User::factory()->technicalAdmin()->create();

        $this->getJson('/api/admin/users?include_admins=1')
            ->assertOk()
            ->assertJsonPath('data.subscription_state_counts.pending', 2)
            ->assertJsonPath('data.subscription_state_counts.awaiting_activation', 1)
            ->assertJsonPath('data.subscription_state_counts.blocked', 1)
            ->assertJsonPath('data.subscription_state_counts.lapsed', 0)
            ->assertJsonPath('data.subscription_state_counts.inactive', 2)
            ->assertJsonPath('data.subscription_state_counts.exempt', 1)
            ->assertJsonPath('data.subscription_state_counts.grace', 0)
            ->assertJsonPath('data.subscription_state_counts.trial', 0)
            ->assertJsonPath('data.subscription_state_counts.active', 4);
    }

    #[Test]
    public function created_at_is_returned_in_iso_8601_utc(): void
    {
        $this->travelTo('2026-10-14 10:30:00');
        $user = User::factory()->create();
        $this->travelBack();

        $item = $this->itemFor($this->getJson('/api/admin/users')->assertOk(), $user->id);

        $this->assertSame('2026-10-14T10:30:00+00:00', $item['created_at'] ?? null);
    }

    #[Test]
    public function blocked_filter_lists_only_blocked_inactive_users(): void
    {
        $blocked = User::factory()->inactive(InactiveReason::Blocked)->create();
        User::factory()->inactive(InactiveReason::Rejected)->create();
        User::factory()->inactive(InactiveReason::Unpaid)->create();
        User::factory()->create();

        $response = $this->getJson('/api/admin/users?status=Inactive&blocked=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination.total', 1);

        $this->assertSame([$blocked->id], $this->listedIds($response));
    }

    #[Test]
    public function not_blocked_filter_lists_other_inactive_users_including_null_reason(): void
    {
        User::factory()->inactive(InactiveReason::Blocked)->create();
        $expected = collect([
            User::factory()->inactive(InactiveReason::Rejected)->create(),
            User::factory()->inactive(InactiveReason::Unpaid)->create(),
            User::factory()->inactive(InactiveReason::Cancelled)->create(),
            User::factory()->inactive()->create(),
        ]);
        User::factory()->create();

        $response = $this->getJson('/api/admin/users?status=Inactive&blocked=0')
            ->assertOk()
            ->assertJsonCount(4, 'data.items')
            ->assertJsonPath('data.pagination.total', 4);

        $this->assertEqualsCanonicalizing($expected->pluck('id')->all(), $this->listedIds($response));
    }

    #[Test]
    public function blocked_filter_requires_inactive_status(): void
    {
        $message = 'O filtro de bloqueados só pode ser usado com o estado Inactive.';

        $this->getJson('/api/admin/users?blocked=1')
            ->assertUnprocessable()
            ->assertJsonPath('errors.blocked.0', $message);

        $this->getJson('/api/admin/users?status=Pending&blocked=0')
            ->assertUnprocessable()
            ->assertJsonPath('errors.blocked.0', $message);
    }

    #[Test]
    public function blocked_filter_must_be_a_boolean(): void
    {
        $this->getJson('/api/admin/users?status=Inactive&blocked=talvez')
            ->assertUnprocessable()
            ->assertJsonPath('errors.blocked.0', 'O campo bloqueados tem de ser verdadeiro ou falso.');
    }

    #[Test]
    public function list_returns_counts_per_tab(): void
    {
        $this->createTabScenario();

        $response = $this->getJson('/api/admin/users')->assertOk();

        $counts = $response->json('data.counts');
        $this->assertSame(
            ['all' => 9, 'active' => 3, 'pending' => 2, 'approved' => 1, 'blocked' => 1, 'inactive' => 2],
            $counts
        );
        $this->assertSame(
            $counts['all'],
            $counts['active'] + $counts['pending'] + $counts['approved'] + $counts['blocked'] + $counts['inactive']
        );
    }

    #[Test]
    public function counts_ignore_the_selected_tab(): void
    {
        $this->createTabScenario();

        $this->getJson('/api/admin/users?status=Inactive&blocked=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.counts', ['all' => 9, 'active' => 3, 'pending' => 2, 'approved' => 1, 'blocked' => 1, 'inactive' => 2]);
    }

    #[Test]
    public function counts_follow_the_search(): void
    {
        $this->member([], ['business_name' => 'Padaria Doce Lar']);
        $this->member(['account_status_id' => $this->statusId('Approved')], ['business_name' => 'Padaria Central']);
        $this->member([], ['business_name' => 'Oficina Rapida']);
        $this->member(['account_status_id' => $this->statusId('Pending')], ['business_name' => 'Livraria Norte']);

        $this->getJson('/api/admin/users?search=padaria')
            ->assertOk()
            ->assertJsonPath('data.counts', ['all' => 2, 'active' => 1, 'pending' => 0, 'approved' => 1, 'blocked' => 0, 'inactive' => 0]);
    }

    #[Test]
    public function counts_include_admins_when_requested(): void
    {
        $this->createTabScenario();

        $this->getJson('/api/admin/users?include_admins=1')
            ->assertOk()
            ->assertJsonPath('data.counts.all', 10)
            ->assertJsonPath('data.counts.active', 4);
    }

    #[Test]
    public function admins_count_is_the_global_number_of_admins_regardless_of_filters(): void
    {
        User::factory()->admin()->create();
        User::factory()->technicalAdmin()->create();
        User::factory()->admin()->create()->delete();
        $this->member([], ['business_name' => 'Padaria Doce Lar']);
        $this->member([], ['business_name' => 'Oficina Rapida']);
        $this->member(['account_status_id' => $this->statusId('Pending')], ['business_name' => 'Livraria Norte']);

        foreach (['', '?search=padaria', '?status=Pending', '?include_admins=1'] as $query) {
            $this->getJson("/api/admin/users{$query}")
                ->assertOk()
                ->assertJsonPath('data.admins_count', 3);
        }
    }

    #[Test]
    public function soft_deleted_users_are_not_listed_nor_counted(): void
    {
        $kept = User::factory()->create();
        $deleted = User::factory()->create();
        $deleted->delete();

        $response = $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('data.counts.all', 1);

        $this->assertSame([$kept->id], $this->listedIds($response));
    }

    #[Test]
    public function list_query_count_does_not_grow_with_the_number_of_members(): void
    {
        // Os middlewares carregam o papel e o estado do admin só no primeiro pedido; carregados já, as duas medições ficam comparáveis.
        $this->admin->load(['role', 'accountStatus']);
        $this->member();
        $this->member();
        $withTwo = $this->countQueries(fn () => $this->getJson('/api/admin/users')->assertOk());

        for ($i = 0; $i < 8; $i++) {
            $this->member();
        }
        $withTen = $this->countQueries(fn () => $this->getJson('/api/admin/users')->assertOk());

        $this->assertSame($withTwo, $withTen);
    }

    #[Test]
    public function search_finds_admins_only_when_include_admins_is_set(): void
    {
        $otherAdmin = User::factory()->admin()->create(['email' => 'procura-admin@example.com']);

        $this->getJson('/api/admin/users?search=procura-admin')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $response = $this->getJson('/api/admin/users?search=procura-admin&include_admins=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $this->assertSame([$otherAdmin->id], $this->listedIds($response));
    }

    #[Test]
    public function page_must_be_at_least_one(): void
    {
        $this->getJson('/api/admin/users?page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page']);
    }

    #[Test]
    public function admin_with_inactive_account_cannot_list_users(): void
    {
        Sanctum::actingAs(User::factory()->admin()->inactive()->create());

        $this->getJson('/api/admin/users')
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'A conta não está ativa e não pode editar dados.',
            ]);
    }

    #[Test]
    public function list_is_ordered_by_professional_name_ignoring_case(): void
    {
        $this->createNamedMembers();

        $response = $this->getJson('/api/admin/users')->assertOk();

        $this->assertSame(['b@example.com', 'c@example.com', 'a@example.com'], $this->listedEmails($response));
    }

    #[Test]
    public function members_without_profile_are_ordered_by_email(): void
    {
        $this->createNamedMembers();
        User::factory()->create(['email' => 'berta@example.com']);

        $response = $this->getJson('/api/admin/users')->assertOk();

        $this->assertSame(
            ['b@example.com', 'berta@example.com', 'c@example.com', 'a@example.com'],
            $this->listedEmails($response)
        );
    }

    #[Test]
    public function members_with_the_same_name_are_paginated_without_repeats(): void
    {
        $ids = collect(range(1, 13))
            ->map(fn () => $this->member([], ['name' => 'Rui Costa'])->id)
            ->all();

        $firstPage = $this->listedIds($this->getJson('/api/admin/users?page=1')->assertOk()->assertJsonCount(12, 'data.items'));
        $secondPage = $this->listedIds($this->getJson('/api/admin/users?page=2')->assertOk()->assertJsonCount(1, 'data.items'));

        $sequence = array_merge($firstPage, $secondPage);
        $this->assertCount(13, array_unique($sequence));
        $this->assertEqualsCanonicalizing($ids, $sequence);

        sort($ids, SORT_STRING);
        $this->assertSame($ids, $sequence);
    }

    #[Test]
    public function list_is_paginated_twelve_per_page(): void
    {
        for ($i = 0; $i < 13; $i++) {
            $this->member();
        }

        $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(12, 'data.items')
            ->assertJsonPath('data.pagination', [
                'current_page' => 1,
                'per_page' => 12,
                'total' => 13,
                'last_page' => 2,
            ]);

        $this->getJson('/api/admin/users?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination', [
                'current_page' => 2,
                'per_page' => 12,
                'total' => 13,
                'last_page' => 2,
            ]);
    }

    #[Test]
    public function per_page_cannot_be_changed_by_the_client(): void
    {
        for ($i = 0; $i < 13; $i++) {
            $this->member();
        }

        foreach ([50, 5] as $perPage) {
            $this->getJson("/api/admin/users?per_page={$perPage}")
                ->assertOk()
                ->assertJsonCount(12, 'data.items')
                ->assertJsonPath('data.pagination.per_page', 12);
        }
    }

    private function member(array $userAttributes = [], array $profileAttributes = []): User
    {
        $user = User::factory()->create($userAttributes);
        MemberProfile::factory()->for($user)->create($profileAttributes);

        return $user;
    }

    private function createTabScenario(): void
    {
        User::factory()->count(2)->pending()->create();
        User::factory()->approved()->create();
        User::factory()->count(3)->create();
        User::factory()->inactive(InactiveReason::Blocked)->create();
        User::factory()->inactive(InactiveReason::Rejected)->create();
        User::factory()->inactive()->create();
    }

    private function createNamedMembers(): void
    {
        $this->member(['email' => 'a@example.com'], ['name' => 'carla Dias']);
        $this->member(['email' => 'b@example.com'], ['name' => 'Ana Reis']);
        $this->member(['email' => 'c@example.com'], ['name' => 'bruno Sa']);
    }

    private function statusId(string $name): string
    {
        return AccountStatus::where('name', $name)->value('id');
    }

    private function itemFor(TestResponse $response, string $id): array
    {
        $item = collect($response->json('data.items'))->firstWhere('id', $id);
        $this->assertIsArray($item, "O utilizador {$id} não está na lista.");

        return $item;
    }

    private function listedIds(TestResponse $response): array
    {
        return collect($response->json('data.items'))->pluck('id')->all();
    }

    private function listedEmails(TestResponse $response): array
    {
        return collect($response->json('data.items'))->pluck('email')->all();
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
