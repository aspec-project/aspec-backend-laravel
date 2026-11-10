<?php

namespace Tests\Feature\Admin;

use App\Enums\InactiveReason;
use App\Models\AccountStatus;
use App\Models\User;
use App\Notifications\ActivationLinkNotification;
use App\Notifications\ReactivationLinkNotification;
use App\Notifications\UserBlockedNotification;
use App\Notifications\UserRejectedNotification;
use App\Services\Payments\StripeSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Aprovar, rejeitar, bloquear e desbloquear leem o estado com o utilizador bloqueado na transação,
 * por isso a segunda ação de dois admins vê o estado já alterado pela primeira. O SQLite ignora o
 * lockForUpdate, por isso estes testes correm as ações em sequência: guardam o comportamento
 * depois da primeira ação (409, um só email, um só cancelamento) e o rollback de um pedido inválido.
 */
class AdminUserStatusSequenceTest extends TestCase
{
    use RefreshDatabase;

    private User $firstAdmin;

    private User $secondAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->firstAdmin = User::factory()->admin()->create();
        $this->secondAdmin = User::factory()->admin()->create();
    }

    private function statusId(string $name): string
    {
        return AccountStatus::where('name', $name)->value('id');
    }

    #[Test]
    public function approving_twice_returns_409_and_sends_one_activation_email(): void
    {
        $candidate = User::factory()->pending()->create();

        Sanctum::actingAs($this->firstAdmin);
        $this->patchJson("/api/admin/users/{$candidate->id}/approve")->assertOk();

        Sanctum::actingAs($this->secondAdmin);
        $this->patchJson("/api/admin/users/{$candidate->id}/approve")
            ->assertConflict()
            ->assertExactJson([
                'success' => false,
                'message' => 'Apenas utilizadores pendentes podem ser aprovados.',
            ]);

        Notification::assertSentToTimes($candidate, ActivationLinkNotification::class, 1);
    }

    #[Test]
    public function rejecting_after_approval_returns_409_and_keeps_the_approved_account(): void
    {
        $candidate = User::factory()->pending()->create();

        Sanctum::actingAs($this->firstAdmin);
        $this->patchJson("/api/admin/users/{$candidate->id}/approve")->assertOk();

        Sanctum::actingAs($this->secondAdmin);
        $this->patchJson("/api/admin/users/{$candidate->id}/reject", ['reason' => 'Documentação em falta.'])
            ->assertConflict()
            ->assertExactJson([
                'success' => false,
                'message' => 'Apenas utilizadores pendentes podem ser rejeitados.',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $candidate->id,
            'email' => $candidate->email,
            'account_status_id' => $this->statusId('Approved'),
            'inactive_reason' => null,
            'deleted_at' => null,
        ]);
        Notification::assertSentOnDemandTimes(UserRejectedNotification::class, 0);
        Notification::assertSentToTimes($candidate, ActivationLinkNotification::class, 1);
    }

    #[Test]
    public function approving_after_rejection_returns_404_and_sends_no_activation_email(): void
    {
        $candidate = User::factory()->pending()->create();

        Sanctum::actingAs($this->firstAdmin);
        $this->patchJson("/api/admin/users/{$candidate->id}/reject")->assertOk();

        Sanctum::actingAs($this->secondAdmin);
        $this->patchJson("/api/admin/users/{$candidate->id}/approve")
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Recurso não encontrado.',
            ]);

        $this->assertSoftDeleted('users', ['id' => $candidate->id]);
        Notification::assertNotSentTo($candidate, ActivationLinkNotification::class);
        Notification::assertSentOnDemandTimes(UserRejectedNotification::class, 1);
    }

    #[Test]
    public function invalid_rejection_reason_returns_422_and_leaves_the_account_pending(): void
    {
        $candidate = User::factory()->pending()->create();

        Sanctum::actingAs($this->firstAdmin);
        $this->patchJson("/api/admin/users/{$candidate->id}/reject", ['reason' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['reason']]);

        $this->assertDatabaseHas('users', [
            'id' => $candidate->id,
            'email' => $candidate->email,
            'account_status_id' => $this->statusId('Pending'),
            'inactive_reason' => null,
            'deleted_at' => null,
        ]);
        Notification::assertSentOnDemandTimes(UserRejectedNotification::class, 0);
    }

    /**
     * Membro Active com uma subscrição ativa: cada cancelamento chama o cancelNow do Stripe.
     */
    private function activeSubscriber(): User
    {
        $user = User::factory()->withBilling()->create();
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_active',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        return $user;
    }

    private function blockedFormerSubscriber(): User
    {
        $user = User::factory()->inactive(InactiveReason::Blocked)->withBilling()->create();
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_1',
            'stripe_status' => 'canceled',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'ends_at' => now()->subDay(),
        ]);

        return $user;
    }

    #[Test]
    public function blocking_twice_returns_409_and_sends_one_email_and_cancels_once(): void
    {
        $member = $this->activeSubscriber();
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelNow')
                ->once()
                ->withArgs(fn ($subscription) => $subscription->stripe_id === 'sub_test_active');
        });

        Sanctum::actingAs($this->firstAdmin);
        $this->patchJson("/api/admin/users/{$member->id}/block", ['reason' => 'Conduta imprópria.'])->assertOk();

        Sanctum::actingAs($this->secondAdmin);
        $this->patchJson("/api/admin/users/{$member->id}/block", ['reason' => 'Conduta imprópria.'])
            ->assertConflict()
            ->assertExactJson([
                'success' => false,
                'message' => 'O utilizador já está bloqueado.',
            ]);

        Notification::assertSentToTimes($member, UserBlockedNotification::class, 1);
        $this->assertSame(InactiveReason::Blocked, $member->fresh()->inactive_reason);
    }

    #[Test]
    public function invalid_block_reason_returns_422_and_leaves_the_account_active(): void
    {
        $member = $this->activeSubscriber();
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('cancelNow');
            $mock->shouldNotReceive('expireCheckout');
        });

        Sanctum::actingAs($this->firstAdmin);
        $this->patchJson("/api/admin/users/{$member->id}/block", ['reason' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['reason']]);

        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'account_status_id' => $this->statusId('Active'),
            'inactive_reason' => null,
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'stripe_id' => 'sub_test_active',
            'stripe_status' => 'active',
            'ends_at' => null,
        ]);
        Notification::assertNotSentTo($member, UserBlockedNotification::class);
    }

    #[Test]
    public function unblocking_twice_a_user_who_never_subscribed_sends_one_activation_link(): void
    {
        $blocked = User::factory()->inactive(InactiveReason::Blocked)->create();

        Sanctum::actingAs($this->firstAdmin);
        $this->patchJson("/api/admin/users/{$blocked->id}/unblock")->assertOk();

        Sanctum::actingAs($this->secondAdmin);
        $this->patchJson("/api/admin/users/{$blocked->id}/unblock")
            ->assertConflict()
            ->assertExactJson([
                'success' => false,
                'message' => 'Apenas utilizadores bloqueados podem ser desbloqueados.',
            ]);

        Notification::assertSentToTimes($blocked, ActivationLinkNotification::class, 1);
        Notification::assertNotSentTo($blocked, ReactivationLinkNotification::class);
        $this->assertDatabaseHas('users', [
            'id' => $blocked->id,
            'account_status_id' => $this->statusId('Approved'),
            'inactive_reason' => null,
        ]);
    }

    #[Test]
    public function unblocking_twice_a_former_subscriber_sends_one_reactivation_link(): void
    {
        $blocked = $this->blockedFormerSubscriber();

        Sanctum::actingAs($this->firstAdmin);
        $this->patchJson("/api/admin/users/{$blocked->id}/unblock")->assertOk();

        Sanctum::actingAs($this->secondAdmin);
        $this->patchJson("/api/admin/users/{$blocked->id}/unblock")
            ->assertConflict()
            ->assertExactJson([
                'success' => false,
                'message' => 'Apenas utilizadores bloqueados podem ser desbloqueados.',
            ]);

        Notification::assertSentToTimes($blocked, ReactivationLinkNotification::class, 1);
        Notification::assertNotSentTo($blocked, ActivationLinkNotification::class);
        $this->assertDatabaseHas('users', [
            'id' => $blocked->id,
            'account_status_id' => $this->statusId('Inactive'),
            'inactive_reason' => InactiveReason::Unpaid->value,
        ]);
    }
}
