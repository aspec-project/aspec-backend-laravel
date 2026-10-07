<?php

namespace Tests\Feature\Admin;

use App\Models\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

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
    public function admin_can_reject_pending_user(): void
    {
        $admin = User::factory()
            ->admin()
            ->create();

        $pendingUser = User::factory()
            ->pending()
            ->create();

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






    
}