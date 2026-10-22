<?php

namespace Tests\Feature\Admin;

use App\Enums\InactiveReason;
use App\Models\User;
use App\Notifications\ActivationLinkNotification;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\ReactivationLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POST /api/admin/users/{user}/payment-link: o admin reenvia, a partir do backoffice, o email de
 * que a conta precisa para (voltar a) pagar. O estado da conta escolhe o email: Approved → link
 * de ativação, Inactive por falta de pagamento ou cancelamento → link de reativação, Active em
 * carência → lembrete de pagamento. Não usa os limites do reenvio público (que um atacante
 * consegue esgotar), mas tem limites próprios por admin e por membro alvo.
 */
class AdminUserPaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIVATION_MESSAGE = 'Foi enviado o email de ativação.';

    private const REACTIVATION_MESSAGE = 'Foi enviado o email de reativação.';

    private const PAYMENT_REMINDER_MESSAGE = 'Foi enviado o lembrete de pagamento.';

    private const NOT_FOUND_MESSAGE = 'Recurso não encontrado.';

    private const TOO_MANY_REQUESTS_MESSAGE = 'Demasiados pedidos. Tente novamente mais tarde.';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function url(string $userId): string
    {
        return "/api/admin/users/{$userId}/payment-link";
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function memberInGrace(): User
    {
        return User::factory()->create([
            'grace_ends_at' => Carbon::parse('2026-10-29 12:00:00'),
        ]);
    }

    private function assertStateUnchanged(User $user): void
    {
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'account_status_id' => $user->account_status_id,
            'inactive_reason' => $user->inactive_reason?->value,
        ]);
    }

    // --- 200: o email que o estado pede ---

    #[Test]
    public function approved_member_receives_the_activation_link(): void
    {
        $this->actingAsAdmin();
        $member = User::factory()->approved()->create();

        $this->postJson($this->url($member->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', self::ACTIVATION_MESSAGE)
            ->assertJsonPath('data.id', $member->id)
            ->assertJsonPath('data.type', 'activation');

        Notification::assertSentToTimes($member, ActivationLinkNotification::class, 1);
        Notification::assertNotSentTo($member, ReactivationLinkNotification::class);
    }

    #[Test]
    public function unpaid_inactive_member_receives_the_reactivation_link(): void
    {
        $this->actingAsAdmin();
        $member = User::factory()->inactive(InactiveReason::Unpaid)->create();

        $this->postJson($this->url($member->id))
            ->assertOk()
            ->assertJsonPath('message', self::REACTIVATION_MESSAGE)
            ->assertJsonPath('data.id', $member->id)
            ->assertJsonPath('data.type', 'reactivation');

        Notification::assertSentToTimes($member, ReactivationLinkNotification::class, 1);
        Notification::assertNotSentTo($member, ActivationLinkNotification::class);
    }

    #[Test]
    public function cancelled_inactive_member_receives_the_reactivation_link(): void
    {
        $this->actingAsAdmin();
        $member = User::factory()->inactive(InactiveReason::Cancelled)->create();

        $this->postJson($this->url($member->id))
            ->assertOk()
            ->assertJsonPath('message', self::REACTIVATION_MESSAGE)
            ->assertJsonPath('data.type', 'reactivation');

        Notification::assertSentToTimes($member, ReactivationLinkNotification::class, 1);
    }

    #[Test]
    public function active_member_in_grace_receives_the_payment_reminder(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberInGrace();

        $this->postJson($this->url($member->id))
            ->assertOk()
            ->assertJsonPath('message', self::PAYMENT_REMINDER_MESSAGE)
            ->assertJsonPath('data.id', $member->id)
            ->assertJsonPath('data.type', 'payment_reminder');

        Notification::assertSentToTimes($member, PaymentFailedNotification::class, 1);
        Notification::assertSentTo(
            $member,
            PaymentFailedNotification::class,
            fn (PaymentFailedNotification $notification) => $notification->graceEndsAt->equalTo($member->grace_ends_at),
        );
    }

    #[Test]
    public function sending_the_email_does_not_change_the_account(): void
    {
        $this->actingAsAdmin();
        $member = User::factory()->inactive(InactiveReason::Unpaid)->create();

        $this->postJson($this->url($member->id))->assertOk();

        $this->assertStateUnchanged($member);
    }

    #[Test]
    public function payment_reminder_keeps_the_grace_period(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberInGrace();

        $this->postJson($this->url($member->id))->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'account_status_id' => $member->account_status_id,
        ]);
        $this->assertTrue($member->fresh()->grace_ends_at->equalTo($member->grace_ends_at));
    }

    #[Test]
    public function paying_admin_with_a_lapsed_subscription_receives_the_reactivation_link(): void
    {
        $this->actingAsAdmin();
        $otherAdmin = User::factory()->admin()->inactive(InactiveReason::Unpaid)->create();

        $this->postJson($this->url($otherAdmin->id))
            ->assertOk()
            ->assertJsonPath('data.type', 'reactivation');

        Notification::assertSentToTimes($otherAdmin, ReactivationLinkNotification::class, 1);
    }

    #[Test]
    public function response_has_no_personal_data_nor_the_link(): void
    {
        $this->actingAsAdmin();
        $member = User::factory()->approved()->create();

        $response = $this->postJson($this->url($member->id))->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString($member->email, $content);
        $this->assertStringNotContainsString($member->phone, $content);
        $this->assertStringNotContainsString('signature', $content);
        $this->assertStringNotContainsString('ativacao', $content);
        $this->assertSame(['id', 'type'], array_keys($response->json('data')));
    }

    // --- 409: estados sem email de pagamento ---

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedStates(): array
    {
        return [
            'bloqueado' => ['blocked', 'A conta está bloqueada. Use a opção Desbloquear.'],
            'ativo sem carência' => ['active', 'A conta já está ativa.'],
            'ativo em trial' => ['trial', 'A conta já está ativa.'],
            'ativo com cancelamento agendado' => ['cancel_scheduled', 'A conta já está ativa.'],
            'admin técnico isento' => ['technical_admin', 'A conta já está ativa.'],
            'pendente' => ['pending', 'A candidatura ainda não foi aprovada.'],
            'rejeitado' => ['rejected', 'Esta conta não pode ser reativada.'],
            'inativo sem motivo' => ['inactive_without_reason', 'Esta conta não pode ser reativada.'],
            'inativo apagado' => ['deleted', 'Esta conta não pode ser reativada.'],
        ];
    }

    private function userInState(string $state): User
    {
        return match ($state) {
            'blocked' => User::factory()->inactive(InactiveReason::Blocked)->create(),
            'active' => User::factory()->create(),
            'trial' => User::factory()->create(['trial_ends_at' => now()->addDays(20)]),
            'cancel_scheduled' => $this->memberWithScheduledCancellation(),
            'technical_admin' => User::factory()->technicalAdmin()->create(),
            'pending' => User::factory()->pending()->create(),
            'rejected' => User::factory()->inactive(InactiveReason::Rejected)->create(),
            'inactive_without_reason' => User::factory()->inactive()->create(),
            'deleted' => User::factory()->inactive(InactiveReason::Deleted)->create(),
        };
    }

    private function memberWithScheduledCancellation(): User
    {
        $member = User::factory()->create(['stripe_id' => 'cus_test_cancel']);
        $member->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_cancel_scheduled',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'ends_at' => now()->addDays(10),
        ]);

        return $member;
    }

    #[Test]
    #[DataProvider('refusedStates')]
    public function account_without_a_pending_payment_is_refused_with_409(string $state, string $message): void
    {
        $this->actingAsAdmin();
        $target = $this->userInState($state);

        $this->postJson($this->url($target->id))
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', $message);

        Notification::assertNothingSent();
        $this->assertStateUnchanged($target);
    }

    #[Test]
    public function admin_calling_it_on_himself_is_refused_because_he_is_active(): void
    {
        $admin = $this->actingAsAdmin();

        $this->postJson($this->url($admin->id))
            ->assertConflict()
            ->assertJsonPath('message', 'A conta já está ativa.');

        Notification::assertNothingSent();
    }

    // --- 404 ---

    #[Test]
    public function unknown_uuid_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->postJson($this->url((string) Str::uuid()))
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', self::NOT_FOUND_MESSAGE);

        Notification::assertNothingSent();
    }

    #[Test]
    public function non_uuid_id_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->postJson($this->url('nao-e-um-uuid'))
            ->assertNotFound()
            ->assertJsonPath('success', false);

        Notification::assertNothingSent();
    }

    #[Test]
    public function anonymized_account_returns_404(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        $member = User::factory()->inactive(InactiveReason::Unpaid)->create();
        $member->anonymizeAndDelete();
        Notification::fake();

        $this->postJson($this->url($member->id))
            ->assertNotFound()
            ->assertJsonPath('message', self::NOT_FOUND_MESSAGE);

        Notification::assertNothingSent();
    }

    // --- 401 / 403 ---

    #[Test]
    public function active_member_who_is_not_admin_gets_403(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $target = User::factory()->inactive(InactiveReason::Unpaid)->create();

        $this->postJson($this->url($target->id))
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Não tem permissão para realizar esta ação.');

        Notification::assertNothingSent();
    }

    #[Test]
    public function inactive_admin_gets_403(): void
    {
        Sanctum::actingAs(User::factory()->admin()->inactive(InactiveReason::Blocked)->create());
        $target = User::factory()->inactive(InactiveReason::Unpaid)->create();

        $this->postJson($this->url($target->id))
            ->assertForbidden()
            ->assertJsonPath('success', false);

        Notification::assertNothingSent();
    }

    #[Test]
    public function unauthenticated_request_gets_401(): void
    {
        $target = User::factory()->inactive(InactiveReason::Unpaid)->create();

        $this->postJson($this->url($target->id))
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Não autenticado.');

        Notification::assertNothingSent();
    }

    // --- limites ---

    #[Test]
    public function public_resend_limit_does_not_block_the_admin(): void
    {
        $this->actingAsAdmin();
        $member = User::factory()->inactive(InactiveReason::Unpaid)->create();
        $publicKey = 'activation-resend-email:'.Str::lower($member->email);
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($publicKey, 86400);
        }

        $this->postJson($this->url($member->id))->assertOk();

        Notification::assertSentToTimes($member, ReactivationLinkNotification::class, 1);
        $this->assertSame(5, RateLimiter::attempts($publicKey));
    }

    #[Test]
    public function admin_payment_link_limiter_is_registered(): void
    {
        $this->assertNotNull(RateLimiter::limiter('admin-payment-link'));
    }

    #[Test]
    public function fourth_request_for_the_same_member_in_the_same_hour_is_throttled(): void
    {
        $this->actingAsAdmin();
        $member = User::factory()->approved()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson($this->url($member->id))->assertOk();
        }

        $this->postJson($this->url($member->id))
            ->assertTooManyRequests()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', self::TOO_MANY_REQUESTS_MESSAGE);

        Notification::assertSentToTimes($member, ActivationLinkNotification::class, 3);
    }

    #[Test]
    public function target_limit_is_shared_between_admins(): void
    {
        $member = User::factory()->approved()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAsAdmin();
            $this->postJson($this->url($member->id))->assertOk();
        }

        $this->actingAsAdmin();
        $this->postJson($this->url($member->id))->assertTooManyRequests();
    }

    #[Test]
    public function target_limit_does_not_affect_other_members(): void
    {
        $this->actingAsAdmin();
        $member = User::factory()->approved()->create();
        $otherMember = User::factory()->approved()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson($this->url($member->id))->assertOk();
        }
        $this->postJson($this->url($member->id))->assertTooManyRequests();

        $this->postJson($this->url($otherMember->id))
            ->assertOk()
            ->assertJsonPath('data.type', 'activation');
    }

    #[Test]
    public function thirty_first_request_by_the_same_admin_in_the_same_hour_is_throttled(): void
    {
        $this->actingAsAdmin();
        $members = User::factory()->approved()->count(31)->create();

        foreach ($members->take(30) as $member) {
            $this->postJson($this->url($member->id))->assertOk();
        }

        $this->postJson($this->url($members->last()->id))
            ->assertTooManyRequests()
            ->assertJsonPath('message', self::TOO_MANY_REQUESTS_MESSAGE);

        Notification::assertNotSentTo($members->last(), ActivationLinkNotification::class);
    }

    #[Test]
    public function admin_limit_is_counted_per_admin(): void
    {
        $this->actingAsAdmin();
        $members = User::factory()->approved()->count(31)->create();

        foreach ($members->take(30) as $member) {
            $this->postJson($this->url($member->id))->assertOk();
        }

        $this->actingAsAdmin();

        $this->postJson($this->url($members->last()->id))->assertOk();
    }

    #[Test]
    public function throttle_resets_after_an_hour(): void
    {
        $this->actingAsAdmin();
        $member = User::factory()->approved()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson($this->url($member->id))->assertOk();
        }
        $this->postJson($this->url($member->id))->assertTooManyRequests();

        $this->travel(61)->minutes();

        $this->postJson($this->url($member->id))->assertOk();
    }

    // --- registo ---

    #[Test]
    public function request_is_logged_with_the_target_the_admin_and_the_type(): void
    {
        Log::spy();
        $admin = $this->actingAsAdmin();
        $member = User::factory()->inactive(InactiveReason::Cancelled)->create();

        $this->postJson($this->url($member->id))->assertOk();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => ($context['user_id'] ?? null) === $member->id
                && ($context['admin_id'] ?? null) === $admin->id
                && ($context['type'] ?? null) === 'reactivation')
            ->once();
    }

    #[Test]
    public function log_never_contains_the_email(): void
    {
        Log::spy();
        $this->actingAsAdmin();
        $member = User::factory()->approved()->create();

        $this->postJson($this->url($member->id))->assertOk();

        $containsEmail = fn (...$args) => str_contains(json_encode($args, JSON_UNESCAPED_UNICODE), $member->email);

        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'] as $level) {
            Log::shouldNotHaveReceived($level, $containsEmail);
        }
    }
}
