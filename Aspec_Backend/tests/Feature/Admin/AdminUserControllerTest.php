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
use Illuminate\Support\Facades\Notification;
use App\Notifications\ActivationLinkNotification;
use App\Notifications\ReactivationLinkNotification;
use App\Services\Payments\Data\CheckoutSessionData;
use App\Services\Payments\StripeSubscriptionService;
use App\Services\Payments\SubscriptionService;
use Mockery\MockInterface;

class AdminUserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

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
                'Utilizador aprovado. Foi enviado o email de ativação.'
            )
            ->assertJsonPath(
                'data.id',
                $pendingUser->id
            )
            ->assertJsonPath(
                'data.account_status.name',
                'Approved'
            );

        $this->assertDatabaseHas('users', [
            'id' => $pendingUser->id,
            'inactive_reason' => null,
            'account_status_id' => AccountStatus::where(
                'name',
                'Approved'
            )->value('id'),
        ]);

        Notification::assertSentToTimes($pendingUser, ActivationLinkNotification::class, 1);
        Notification::assertNotSentTo($pendingUser, ReactivationLinkNotification::class);
    }

    #[Test]
    public function link_in_the_approval_email_opens_the_activation_page(): void
    {
        config(['app.frontend_url' => 'http://localhost:5173']);
        $admin = User::factory()->admin()->create();
        $pendingUser = User::factory()->pending()->create();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/admin/users/{$pendingUser->id}/approve")->assertOk();

        $url = null;
        Notification::assertSentTo($pendingUser, ActivationLinkNotification::class, function ($notification) use ($pendingUser, &$url) {
            $url = $notification->toMail($pendingUser->fresh())->actionUrl;

            return true;
        });

        $this->getJson(str_replace('http://localhost:5173/ativacao/', '/api/account-activations/', $url))
            ->assertOk()
            ->assertJsonPath('data.type', 'activation');
    }

    #[Test]
    public function admin_cannot_approve_an_already_approved_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->approved()->create();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$user->id}/approve")
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Apenas utilizadores pendentes podem ser aprovados.',
            ]);

        Notification::assertNothingSent();
    }

    #[Test]
    public function admin_cannot_approve_an_inactive_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->inactive(InactiveReason::Rejected)->create();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$user->id}/approve")
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Apenas utilizadores pendentes podem ser aprovados.',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'inactive_reason' => InactiveReason::Rejected->value,
        ]);
        Notification::assertNothingSent();
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

        Notification::assertNothingSent();
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

        Notification::assertNothingSent();
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

        Notification::assertNothingSent();
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
    public function unblocking_a_user_who_never_subscribed_sends_the_activation_link(): void
    {
        // Nunca Active direto: sem subscrição, só o pagamento volta a dar acesso.
        $admin = User::factory()->admin()->create();
        $user = User::factory()->inactive(InactiveReason::Blocked)->create();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$user->id}/unblock")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Utilizador desbloqueado. Foi enviado o email de ativação.')
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.account_status.name', 'Approved');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'inactive_reason' => null,
            'account_status_id' => AccountStatus::where('name', 'Approved')->value('id'),
        ]);
        Notification::assertSentToTimes($user, ActivationLinkNotification::class, 1);
        Notification::assertNotSentTo($user, ReactivationLinkNotification::class);
    }

    #[Test]
    public function blocked_user_who_paid_an_old_checkout_can_reactivate_after_unblock(): void
    {
        // A sessão paga antes do bloqueio (subscrição já cancelada) não pode dar 409 para sempre.
        config(['app.frontend_url' => 'http://localhost:5173']);
        $admin = User::factory()->admin()->create();
        $user = User::factory()->inactive(InactiveReason::Blocked)->withBilling()->create();
        $user->forceFill(['stripe_checkout_session_id' => 'cs_test_A'])->save();
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_1',
            'stripe_status' => 'canceled',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'ends_at' => now()->subDay(),
        ]);
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('retrieveCheckout');
            $mock->shouldReceive('createCheckout')->once()
                ->withArgs(fn (User $customer, bool $withTrial) => $withTrial === false)
                ->andReturn(new CheckoutSessionData('cs_test_B', 'https://checkout.stripe.com/c/pay/cs_test_B', 'open'));
        });

        Sanctum::actingAs($admin);
        $this->patchJson("/api/admin/users/{$user->id}/unblock")->assertOk();

        $this->assertNull($user->fresh()->stripe_checkout_session_id);

        $url = app(SubscriptionService::class)->activationUrl($user->fresh());

        $this->postJson(str_replace('http://localhost:5173/ativacao/', '/api/account-activations/', $url))
            ->assertOk()
            ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.com/c/pay/cs_test_B');
    }

    #[Test]
    public function unblocking_a_user_who_already_subscribed_sends_the_reactivation_link(): void
    {
        // O bloqueio cancelou a subscrição: volta como sem pagamento e paga de novo, sem novo período experimental.
        $admin = User::factory()->admin()->create();
        $user = User::factory()->inactive(InactiveReason::Blocked)->withBilling()->create();
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_1',
            'stripe_status' => 'canceled',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'ends_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$user->id}/unblock")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Utilizador desbloqueado. Foi enviado o email de reativação.')
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.account_status.name', 'Inactive');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'inactive_reason' => InactiveReason::Unpaid->value,
            'account_status_id' => AccountStatus::where('name', 'Inactive')->value('id'),
        ]);
        Notification::assertSentToTimes($user, ReactivationLinkNotification::class, 1);
        Notification::assertNotSentTo($user, ActivationLinkNotification::class);
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

    Notification::assertNothingSent();
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

    Notification::assertNothingSent();
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

    Notification::assertNothingSent();
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

    Notification::assertNothingSent();
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

    Notification::assertNothingSent();
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

    Notification::assertNothingSent();
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





    
}