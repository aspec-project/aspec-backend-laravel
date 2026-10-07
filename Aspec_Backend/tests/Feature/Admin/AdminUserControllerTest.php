<?php

namespace Tests\Feature\Admin;

use App\Models\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Support\Facades\Auth;
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


    #[Test]
    public function admin_can_unblock_inactive_user(): void
    {
        $admin = User::factory()
            ->admin()
            ->create();

        $user = User::factory()
            ->inactive()
            ->create();

        Sanctum::actingAs($admin);

        $response = $this->patchJson(
            "/api/admin/users/{$user->id}/unblock"
        );

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'Utilizador desbloqueado com sucesso.'
            )
            ->assertJsonPath(
                'data.id',
                $user->id
            )
            ->assertJsonPath(
                'data.account_status.name',
                'Active'
            );

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'account_status_id' => AccountStatus::where(
                'name',
                'Active'
            )->value('id'),
        ]);
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






    
}