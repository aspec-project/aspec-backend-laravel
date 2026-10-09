<?php

namespace Tests\Feature\Admin;

use App\Models\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Support\Facades\Auth;
use App\Enums\InactiveReason;
use Illuminate\Support\Facades\DB;

class AdminUserControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_can_approve_pending_user(): void
    {
        $admin = User::factory()
            ->admin()
            ->create();

        $pendingUser = User::factory()
            ->pending()
            ->create();

        Sanctum::actingAs($admin);

        $response = $this->patchJson(
            "/api/admin/users/{$pendingUser->id}/approve"
        );

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'Utilizador aprovado com sucesso.'
            )
            ->assertJsonPath(
                'data.id',
                $pendingUser->id
            )
            ->assertJsonPath(
                'data.account_status.name',
                'Active'
            );

        $this->assertDatabaseHas('users', [
            'id' => $pendingUser->id,
            'account_status_id' => AccountStatus::where(
                'name',
                'Active'
            )->value('id'),
        ]);
    }

    #[Test]
    public function regular_user_cannot_approve_pending_user(): void
    {
        $member = User::factory()
            ->create();

        $pendingUser = User::factory()
            ->pending()
            ->create();

        Sanctum::actingAs($member);

        $response = $this->patchJson(
            "/api/admin/users/{$pendingUser->id}/approve"
        );

        $response
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'Não tem permissão para realizar esta ação.',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $pendingUser->id,
            'account_status_id' => AccountStatus::where(
                'name',
                'Pending'
            )->value('id'),
        ]);
    }

    #[Test]
    public function unauthenticated_user_cannot_approve_pending_user(): void
    {
        $pendingUser = User::factory()
            ->pending()
            ->create();

        $response = $this->patchJson(
            "/api/admin/users/{$pendingUser->id}/approve"
        );

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Não autenticado.',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $pendingUser->id,
            'account_status_id' => AccountStatus::where(
                'name',
                'Pending'
            )->value('id'),
        ]);
    }


    #[Test]
    public function admin_cannot_approve_nonexistent_user(): void
    {
        $admin = User::factory()
            ->admin()
            ->create();

        Sanctum::actingAs($admin);

        $nonExistentId = '00000000-0000-0000-0000-000000000000';

        $response = $this->patchJson(
            "/api/admin/users/{$nonExistentId}/approve"
        );

        $response->assertNotFound();
    }


    #[Test]
    public function admin_can_reject_pending_user_and_revoke_tokens(): void
    {
        $admin = User::factory()
            ->admin()
            ->create();

        $pendingUser = User::factory()
            ->pending()
            ->create();

        $pendingUserToken = $pendingUser
            ->createToken('pending-user-token')
            ->plainTextToken;

        Sanctum::actingAs($admin);

        $response = $this->patchJson(
            "/api/admin/users/{$pendingUser->id}/reject"
        );

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'Utilizador rejeitado com sucesso.'
            )
            ->assertJsonPath(
                'data.id',
                $pendingUser->id
            )
            ->assertJsonPath(
                'data.account_status.name',
                'Inactive'
            );

        $this->assertDatabaseHas('users', [
            'id' => $pendingUser->id,
            'account_status_id' => AccountStatus::where(
                'name',
                'Inactive'
            )->value('id'),
        ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $pendingUser->id,
            'name' => 'pending-user-token',
        ]);

        \Illuminate\Support\Facades\Auth::forgetGuards();

        $this
            ->withHeader('Authorization', 'Bearer '.$pendingUserToken)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }


    #[Test]
    public function regular_user_cannot_reject_pending_user(): void
    {
        $member = User::factory()
            ->create();

        $pendingUser = User::factory()
            ->pending()
            ->create();

        Sanctum::actingAs($member);

        $response = $this->patchJson(
            "/api/admin/users/{$pendingUser->id}/reject"
        );

        $response
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'Não tem permissão para realizar esta ação.',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $pendingUser->id,
            'account_status_id' => AccountStatus::where(
                'name',
                'Pending'
            )->value('id'),
        ]);
    }

    #[Test]
    public function unauthenticated_user_cannot_reject_pending_user(): void
    {  
        $pendingUser = User::factory()
            ->pending()
            ->create();

        $response = $this->patchJson(
            "/api/admin/users/{$pendingUser->id}/reject"
        );

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Não autenticado.',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $pendingUser->id,
            'account_status_id' => AccountStatus::where(
                'name',
                'Pending'
            )->value('id'),
        ]);
    }


    #[Test]
    public function admin_cannot_approve_active_user(): void
    {
        $admin = User::factory()
            ->admin()
            ->create();

        $activeUser = User::factory()
            ->create();

        Sanctum::actingAs($admin);

        $response = $this->patchJson(
            "/api/admin/users/{$activeUser->id}/approve"
        );

        $response
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Apenas utilizadores pendentes podem ser aprovados.',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $activeUser->id,
            'account_status_id' => AccountStatus::where(
                'name',
                'Active'
            )->value('id'),
        ]);
    }

    #[Test]
    public function admin_cannot_reject_inactive_user(): void
    {
        $admin = User::factory()
            ->admin()
            ->create();

        $inactiveUser = User::factory()
            ->inactive()
            ->create();

        Sanctum::actingAs($admin);

        $response = $this->patchJson(
            "/api/admin/users/{$inactiveUser->id}/reject"
        );

        $response
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Apenas utilizadores pendentes podem ser rejeitados.',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $inactiveUser->id,
            'account_status_id' => AccountStatus::where(
                'name',
                'Inactive'
            )->value('id'),
        ]);
    }

    #[Test]
    public function admin_cannot_approve_another_admin(): void
    {
        $admin = User::factory()
            ->admin()
            ->create();

        $otherAdmin = User::factory()
            ->admin()
            ->pending()
            ->create();

        Sanctum::actingAs($admin);

        $response = $this->patchJson(
            "/api/admin/users/{$otherAdmin->id}/approve"
        );

        $response
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Não pode alterar o estado de um administrador.',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $otherAdmin->id,
            'account_status_id' => AccountStatus::where(
                'name',
                'Pending'
            )->value('id'),
        ]);
    }

    #[Test]
    public function admin_cannot_reject_another_admin(): void
    {
        $admin = User::factory()
            ->admin()
            ->create();

        $otherAdmin = User::factory()
            ->admin()
            ->pending()
            ->create();

        Sanctum::actingAs($admin);

        $response = $this->patchJson(
            "/api/admin/users/{$otherAdmin->id}/reject"
        );

        $response
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Não pode alterar o estado de um administrador.',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $otherAdmin->id,
            'account_status_id' => AccountStatus::where(
                'name',
                'Pending'
            )->value('id'),
        ]);
    }

    #[Test]
    public function admin_cannot_approve_themselves(): void
    {
        $admin = User::factory()
            ->admin()
            ->create();

        Sanctum::actingAs($admin);

        $response = $this->patchJson(
            "/api/admin/users/{$admin->id}/approve"
        );

        $response
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Não pode alterar o estado da própria conta.',
            ]);
    }

    #[Test]
    public function admin_cannot_reject_themselves(): void
    {
        $admin = User::factory()
            ->admin()
            ->create();

        Sanctum::actingAs($admin);

        $response = $this->patchJson(
            "/api/admin/users/{$admin->id}/reject"
        );

        $response
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Não pode alterar o estado da própria conta.',
            ]);
    }

    #[Test]
    public function inactive_admin_cannot_approve_pending_user(): void
    {
        $admin = User::factory()
            ->admin()
            ->inactive()
            ->create();

        $pendingUser = User::factory()
            ->pending()
            ->create();

        Sanctum::actingAs($admin);

        $response = $this->patchJson(
            "/api/admin/users/{$pendingUser->id}/approve"
        );

        $response
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'A conta não está ativa e não pode editar dados.',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $pendingUser->id,
            'account_status_id' => AccountStatus::where(
                'name',
                'Pending'
            )->value('id'),
        ]);
    }

    #[Test]
    public function admin_cannot_reject_nonexistent_user(): void
    {
        $admin = User::factory()
            ->admin()
            ->create();

        Sanctum::actingAs($admin);

        $nonExistentId = '00000000-0000-0000-0000-000000000000';

        $response = $this->patchJson(
            "/api/admin/users/{$nonExistentId}/reject"
        );

        $response->assertNotFound();
    }


    #[Test]
    public function admin_can_block_active_user_and_revoke_tokens(): void
    {
        $admin = User::factory()
            ->admin()
            ->create();

        $user = User::factory()
            ->create();

        $userToken = $user->createToken('user-token')->plainTextToken;

        DB::table('sessions')->insert([
            'id' => 'session-to-delete',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson(
            "/api/admin/users/{$user->id}/block"
        );

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'Utilizador bloqueado com sucesso.'
            )
            ->assertJsonPath(
                'data.id',
                $user->id
            )
            ->assertJsonPath(
                'data.account_status.name',
                'Inactive'
            );

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'account_status_id' => AccountStatus::where(
                'name',
                'Inactive'
            )->value('id'),
        ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'user-token',
        ]);

        $this->assertDatabaseMissing('sessions', [
            'id' => 'session-to-delete',
        ]);

        Auth::forgetGuards();

        $this
            ->withHeader('Authorization', 'Bearer '.$userToken)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }


    // #[Test]
    // public function admin_can_unblock_inactive_user(): void
    // {
    //     $admin = User::factory()
    //         ->admin()
    //         ->create();

    //     $user = User::factory()
    //         ->inactive(InactiveReason::Blocked)
    //         ->create();

    //     Sanctum::actingAs($admin);

    //     $response = $this->patchJson(
    //         "/api/admin/users/{$user->id}/unblock"
    //     );

    //     $response
    //         ->assertOk()
    //         ->assertJsonPath('success', true)
    //         ->assertJsonPath(
    //             'message',
    //             'Utilizador desbloqueado com sucesso.'
    //         )
    //         ->assertJsonPath(
    //             'data.id',
    //             $user->id
    //         )
    //         ->assertJsonPath(
    //             'data.account_status.name',
    //             'Active'
    //         );

    //     $this->assertDatabaseHas('users', [
    //         'id' => $user->id,
    //         'account_status_id' => AccountStatus::where(
    //             'name',
    //             'Active'
    //         )->value('id'),
    //     ]);
    // }

    #[Test]
    public function admin_can_unblock_blocked_user(): void
    {
        $this->markTestIncomplete(
            'O fluxo de sucesso do unblock depende do SubscriptionService de ativação/reativação.'
        );
    }

    #[Test]
public function regular_user_cannot_block_user(): void
{
    $member = User::factory()
        ->create();

    $user = User::factory()
        ->create();

    Sanctum::actingAs($member);

    $response = $this->patchJson(
        "/api/admin/users/{$user->id}/block"
    );

    $response
        ->assertForbidden()
        ->assertJson([
            'success' => false,
            'message' => 'Não tem permissão para realizar esta ação.',
        ]);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'account_status_id' => AccountStatus::where(
            'name',
            'Active'
        )->value('id'),
    ]);
}

#[Test]
public function regular_user_cannot_unblock_user(): void
{
    $member = User::factory()
        ->create();

    $user = User::factory()
        ->inactive()
        ->create();

    Sanctum::actingAs($member);

    $response = $this->patchJson(
        "/api/admin/users/{$user->id}/unblock"
    );

    $response
        ->assertForbidden()
        ->assertJson([
            'success' => false,
            'message' => 'Não tem permissão para realizar esta ação.',
        ]);

    $this->assertDatabaseHas('users', [
    'id' => $user->id,
    'account_status_id' => AccountStatus::where(
        'name',
        'Inactive'
    )->value('id'),
]);
}

#[Test]
public function blocking_user_clears_grace_period_and_sets_blocked_reason(): void
{
    $admin = User::factory()
        ->admin()
        ->create();

    $user = User::factory()->create([
        'grace_ends_at' => now()->addDays(5),
    ]);

    Sanctum::actingAs($admin);

    $this
        ->patchJson("/api/admin/users/{$user->id}/block")
        ->assertOk();

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'inactive_reason' => InactiveReason::Blocked->value,
        'grace_ends_at' => null,
        'account_status_id' => AccountStatus::where(
            'name',
            'Inactive'
        )->value('id'),
    ]);
}

#[Test]
public function admin_cannot_unblock_pending_user(): void
{
    $admin = User::factory()
        ->admin()
        ->create();

    $user = User::factory()
        ->pending()
        ->create();

    Sanctum::actingAs($admin);

    $this
        ->patchJson("/api/admin/users/{$user->id}/unblock")
        ->assertStatus(409)
        ->assertJson([
            'success' => false,
            'message' => 'Apenas utilizadores bloqueados podem ser desbloqueados.',
        ]);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'account_status_id' => AccountStatus::where(
            'name',
            'Pending'
        )->value('id'),
    ]);
}

#[Test]
public function admin_cannot_unblock_rejected_user(): void
{
    $admin = User::factory()
        ->admin()
        ->create();

    $user = User::factory()
        ->inactive(InactiveReason::Rejected)
        ->create();

    Sanctum::actingAs($admin);

    $this
        ->patchJson("/api/admin/users/{$user->id}/unblock")
        ->assertStatus(409)
        ->assertJson([
            'success' => false,
            'message' => 'Apenas utilizadores bloqueados podem ser desbloqueados.',
        ]);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'inactive_reason' => InactiveReason::Rejected->value,
    ]);
}

#[Test]
public function admin_cannot_unblock_unpaid_user(): void
{
    $admin = User::factory()
        ->admin()
        ->create();

    $user = User::factory()
        ->inactive(InactiveReason::Unpaid)
        ->create();

    Sanctum::actingAs($admin);

    $this
        ->patchJson("/api/admin/users/{$user->id}/unblock")
        ->assertStatus(409)
        ->assertJson([
            'success' => false,
            'message' => 'Apenas utilizadores bloqueados podem ser desbloqueados.',
        ]);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'inactive_reason' => InactiveReason::Unpaid->value,
    ]);
}

#[Test]
public function admin_cannot_unblock_active_user(): void
{
    $admin = User::factory()
        ->admin()
        ->create();

    $user = User::factory()->create();

    Sanctum::actingAs($admin);

    $this
        ->patchJson("/api/admin/users/{$user->id}/unblock")
        ->assertStatus(409)
        ->assertJson([
            'success' => false,
            'message' => 'Apenas utilizadores bloqueados podem ser desbloqueados.',
        ]);
}


#[Test]
public function admin_cannot_unblock_another_admin(): void
{
    $admin = User::factory()
        ->admin()
        ->create();

    $otherAdmin = User::factory()
        ->admin()
        ->inactive(InactiveReason::Blocked)
        ->create();

    Sanctum::actingAs($admin);

    $this
        ->patchJson("/api/admin/users/{$otherAdmin->id}/unblock")
        ->assertStatus(409)
        ->assertJson([
            'success' => false,
            'message' => 'Não pode alterar o estado de um administrador.',
        ]);

    $this->assertDatabaseHas('users', [
        'id' => $otherAdmin->id,
        'inactive_reason' => InactiveReason::Blocked->value,
    ]);
}

#[Test]
public function admin_cannot_unblock_themselves(): void
{
    $admin = User::factory()
        ->admin()
        ->inactive(InactiveReason::Blocked)
        ->create();

    Sanctum::actingAs($admin);

    $this
        ->patchJson("/api/admin/users/{$admin->id}/unblock")
        ->assertForbidden()
        ->assertJson([
            'success' => false,
            'message' => 'A conta não está ativa e não pode editar dados.',
    ]);
}


#[Test]
public function admin_cannot_block_themselves(): void
{
    $admin = User::factory()
        ->admin()
        ->create();

    Sanctum::actingAs($admin);

    $this
        ->patchJson("/api/admin/users/{$admin->id}/block")
        ->assertConflict()
        ->assertJson([
            'success' => false,
            'message' => 'Não pode alterar o estado da própria conta.',
        ]);
}


#[Test]
public function admin_cannot_block_another_admin(): void
{
    $admin = User::factory()
        ->admin()
        ->create();

    $otherAdmin = User::factory()
        ->admin()
        ->create();

    Sanctum::actingAs($admin);

    $this
        ->patchJson("/api/admin/users/{$otherAdmin->id}/block")
        ->assertConflict()
        ->assertJson([
            'success' => false,
            'message' => 'Não pode alterar o estado de um administrador.',
        ]);
}


#[Test]
public function admin_cannot_block_an_already_blocked_user(): void
{
    $admin = User::factory()
        ->admin()
        ->create();

    $user = User::factory()
        ->inactive(InactiveReason::Blocked)
        ->create();

    Sanctum::actingAs($admin);

    $this
        ->patchJson("/api/admin/users/{$user->id}/block")
        ->assertConflict()
        ->assertJson([
            'success' => false,
            'message' => 'O utilizador já está bloqueado.',
        ]);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'inactive_reason' => InactiveReason::Blocked->value,
    ]);
}

#[Test]
public function admin_can_list_users_without_status_filter(): void
{
    $admin = User::factory()
        ->admin()
        ->create();

    User::factory()->pending()->create();
    User::factory()->approved()->create();
    User::factory()->create(); // Active por padrão
    User::factory()->inactive()->create();

    Sanctum::actingAs($admin);

    $this->getJson('/api/admin/users')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath(
            'message',
            'Lista de utilizadores obtida com sucesso.'
        )
        ->assertJsonCount(5, 'data.items')
        ->assertJsonPath('data.pagination.current_page', 1)
        ->assertJsonPath('data.pagination.per_page', 15)
        ->assertJsonPath('data.pagination.total', 5)
        ->assertJsonPath('data.pagination.last_page', 1);
}

#[Test]
public function admin_can_filter_users_by_account_status(): void
{
    $admin = User::factory()
        ->admin()
        ->create();

    $usersByStatus = [
        'Pending' => User::factory()->pending()->create(),
        'Approved' => User::factory()->approved()->create(),
        'Active' => User::factory()->create(),
        'Inactive' => User::factory()->inactive()->create(),
    ];

    Sanctum::actingAs($admin);

    foreach ($usersByStatus as $status => $expectedUser) {
        $response = $this->getJson('/api/admin/users?status='.$status);

        $response
            ->assertOk()
            ->assertJsonCount(
                $status === 'Active' ? 2 : 1,
                'data.items'
            );

        $listedIds = collect($response->json('data.items'))
            ->pluck('id')
            ->all();

        $this->assertContains($expectedUser->id, $listedIds);
    }
}

#[Test]
public function admin_cannot_filter_users_by_unknown_status(): void
{
    $admin = User::factory()
        ->admin()
        ->create();

    Sanctum::actingAs($admin);

    $this->getJson('/api/admin/users?status=Unknown')
        ->assertUnprocessable();
}

#[Test]
public function unauthenticated_user_cannot_list_admin_users(): void
{
    $this->getJson('/api/admin/users')
        ->assertUnauthorized();
}

#[Test]
public function regular_user_cannot_list_admin_users(): void
{
    $member = User::factory()->create();

    Sanctum::actingAs($member);

    $this->getJson('/api/admin/users')
        ->assertForbidden();
}

#[Test]
public function admin_user_list_is_paginated(): void
{
    $admin = User::factory()
        ->admin()
        ->create();

    User::factory()->count(16)->create();

    Sanctum::actingAs($admin);

    $this->getJson('/api/admin/users')
        ->assertOk()
        ->assertJsonCount(15, 'data.items')
        ->assertJsonPath('data.pagination.current_page', 1)
        ->assertJsonPath('data.pagination.per_page', 15)
        ->assertJsonPath('data.pagination.total', 17)
        ->assertJsonPath('data.pagination.last_page', 2);
}

#[Test]
public function admin_can_search_users_within_the_selected_status(): void
{
    $admin = User::factory()
        ->admin()
        ->create();

    $pendingUser = User::factory()
        ->pending()
        ->create();

    \App\Models\MemberProfile::factory()
        ->for($pendingUser)
        ->create([
            'name' => 'Joao Pesquisa',
            'business_name' => 'Consultoria Exemplo',
        ]);

    $activeUser = User::factory()->create([
        'email' => 'joao.active@example.com',
    ]);

    \App\Models\MemberProfile::factory()
        ->for($activeUser)
        ->create([
            'name' => 'Joao Active',
            'business_name' => 'Outra Empresa',
        ]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/admin/users?status=Pending&search=Joao')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.id', $pendingUser->id)
        ->assertJsonPath('data.pagination.total', 1);
}





    
}