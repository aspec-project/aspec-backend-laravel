<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Models\AccountStatus;
use App\Models\Invoice;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\ReactivationLinkNotification;
use App\Notifications\SubscriptionCancelledNotification;
use App\Notifications\SubscriptionResumedNotification;
use App\Services\Payments\StripeSubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Subscription;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Stripe\Exception\ApiConnectionException;
use Tests\Concerns\FillsRateLimits;
use Tests\TestCase;

/**
 * POST /api/subscription/cancel e /resume: o membro cancela a subscrição no fim do período já
 * pago (sem reembolso) e pode retomá-la até lá. O acesso mantém-se até ao fim; o webhook
 * subscription.deleted é que desativa a conta. O Stripe é mockado: o mock altera a linha local
 * como o Cashier (ends_at no cancelamento, null ao retomar).
 */
class SubscriptionCancellationTest extends TestCase
{
    use FillsRateLimits;
    use RefreshDatabase;

    private const CANCEL_URL = '/api/subscription/cancel';

    private const RESUME_URL = '/api/subscription/resume';

    private const FRONTEND_URL = 'http://localhost:5173';

    private const NOT_FOUND = 'Não existe subscrição.';

    private const DATA_KEYS = ['status', 'trial_starts_at', 'trial_ends_at', 'trial_days', 'next_payment_at', 'grace_ends_at', 'ends_at', 'cancel_at_period_end', 'price', 'billing'];

    private const ALREADY_SCHEDULED = 'O cancelamento da subscrição já está agendado.';

    private const PAYMENT_OVERDUE = 'Tem um pagamento em atraso. Atualize o cartão ou, se não pagar, a subscrição termina no fim do período de carência.';

    private const CANNOT_CANCEL = 'A subscrição não pode ser cancelada no estado atual.';

    private const NOT_SCHEDULED = 'A subscrição não tem um cancelamento agendado.';

    private const RESUMED = 'A subscrição foi retomada.';

    private const PAYMENTS_DOWN = 'Não foi possível contactar o serviço de pagamentos. Tente novamente.';

    private const NOT_ACTIVE = 'A conta não está ativa e não pode editar dados.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
        Notification::fake();
        config([
            'app.frontend_url' => self::FRONTEND_URL,
            'subscription.price_amount' => 60.00,
        ]);
    }

    private function member(): User
    {
        return User::factory()->withBilling()->create(['stripe_id' => 'cus_'.fake()->unique()->lexify('??????????')]);
    }

    private function subscriptionFor(User $user, string $status = 'active', array $attributes = []): Subscription
    {
        return $user->subscriptions()->create(array_merge([
            'type' => 'default',
            'stripe_id' => 'sub_'.fake()->unique()->lexify('??????????'),
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ], $attributes));
    }

    private function periodEnd()
    {
        return now()->addDays(20);
    }

    /**
     * Mock do Stripe que cancela e retoma como o Cashier: no trial o fim é o fim do trial,
     * senão o fim do período pago.
     */
    private function stripe(): MockInterface
    {
        return $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelAtPeriodEnd')->byDefault()->andReturnUsing(function (Subscription $subscription) {
                $subscription->forceFill([
                    'ends_at' => $subscription->onTrial() ? $subscription->trial_ends_at : $this->periodEnd(),
                ])->save();
            });
            $mock->shouldReceive('resume')->byDefault()->andReturnUsing(function (Subscription $subscription) {
                $subscription->forceFill(['ends_at' => null])->save();
            });
        });
    }

    private function stripeNeverCalled(): void
    {
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('cancelAtPeriodEnd');
            $mock->shouldNotReceive('resume');
        });
    }

    private function mailText(MailMessage $mail): string
    {
        return implode("\n", array_merge(
            [$mail->subject, $mail->greeting, $mail->actionText],
            $mail->introLines,
            $mail->outroLines,
        ));
    }

    public static function accountsWithoutAccess(): array
    {
        return [
            'aprovada' => ['approved'],
            'sem pagamento' => ['unpaid'],
            'cancelada' => ['cancelled'],
        ];
    }

    private function accountInState(string $state): User
    {
        $factory = match ($state) {
            'approved' => User::factory()->approved(),
            'unpaid' => User::factory()->inactive(InactiveReason::Unpaid),
            'cancelled' => User::factory()->inactive(InactiveReason::from('cancelled')),
        };
        $user = $factory->withBilling()->create(['stripe_id' => 'cus_'.fake()->unique()->lexify('??????????')]);
        $this->subscriptionFor($user, 'canceled', ['ends_at' => now()->subDay()]);

        return $user;
    }

    #[Test]
    public function active_member_schedules_the_cancellation_for_the_end_of_the_paid_period(): void
    {
        $member = $this->member();
        $subscription = $this->subscriptionFor($member);
        $this->stripe()->shouldReceive('cancelAtPeriodEnd')->once()
            ->withArgs(fn (Subscription $s) => $s->is($subscription))
            ->andReturnUsing(fn (Subscription $s) => $s->forceFill(['ends_at' => $this->periodEnd()])->save());
        Sanctum::actingAs($member);

        $this->postJson(self::CANCEL_URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Cancelamento agendado. Mantém o acesso até '.$this->periodEnd()->format('d/m/Y').'.')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.cancel_at_period_end', true)
            ->assertJsonPath('data.ends_at', $this->periodEnd()->toIso8601String());

        $this->assertSame('Active', $member->fresh()->accountStatus->name);
        Notification::assertSentToTimes($member, SubscriptionCancelledNotification::class, 1);
        Notification::assertSentTo($member, SubscriptionCancelledNotification::class, function ($notification) use ($member) {
            return str_contains($this->mailText($notification->toMail($member)), $this->periodEnd()->format('d/m/Y'));
        });
    }

    #[Test]
    public function member_on_trial_keeps_access_until_the_trial_ends_without_being_charged(): void
    {
        $member = $this->member();
        $trialEndsAt = now()->addDays(12);
        $this->subscriptionFor($member, 'trialing', ['trial_ends_at' => $trialEndsAt]);
        $this->stripe();
        Sanctum::actingAs($member);

        $this->postJson(self::CANCEL_URL)
            ->assertOk()
            ->assertJsonPath('message', 'Cancelamento agendado. Mantém o acesso até '.$trialEndsAt->format('d/m/Y').'.')
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.cancel_at_period_end', true)
            ->assertJsonPath('data.ends_at', $trialEndsAt->toIso8601String());
    }

    #[Test]
    public function cancelling_twice_is_a_conflict(): void
    {
        $member = $this->member();
        $this->subscriptionFor($member, 'active', ['ends_at' => $this->periodEnd()]);
        $this->stripeNeverCalled();
        Sanctum::actingAs($member);

        $this->postJson(self::CANCEL_URL)
            ->assertConflict()
            ->assertExactJson(['success' => false, 'message' => self::ALREADY_SCHEDULED]);
        Notification::assertNothingSent();
    }

    public static function overdueStatuses(): array
    {
        return [
            'past_due' => ['past_due'],
            'unpaid' => ['unpaid'],
        ];
    }

    /**
     * Em carência a subscrição já termina sozinha se não for paga; misturar os dois caminhos
     * confundia o motivo (falta de pagamento vs cancelamento) e os emails.
     */
    #[Test]
    #[DataProvider('overdueStatuses')]
    public function member_with_an_overdue_payment_cannot_cancel(string $status): void
    {
        $member = $this->member();
        $member->forceFill(['grace_ends_at' => now()->addDays(5)])->save();
        $this->subscriptionFor($member, $status);
        $this->stripeNeverCalled();
        Sanctum::actingAs($member);

        $this->postJson(self::CANCEL_URL)
            ->assertConflict()
            ->assertExactJson(['success' => false, 'message' => self::PAYMENT_OVERDUE]);
    }

    public static function statusesThatCannotBeCancelled(): array
    {
        return [
            'incomplete' => ['incomplete'],
            'canceled' => ['canceled'],
        ];
    }

    /**
     * O Cashier preenche sempre o ends_at de uma subscrição terminada: a resposta tem de vir do
     * estado e não do ends_at, senão uma subscrição terminada aparecia como "já agendada".
     */
    #[Test]
    #[DataProvider('statusesThatCannotBeCancelled')]
    public function subscription_that_is_not_running_cannot_be_cancelled(string $status): void
    {
        $member = $this->member();
        $this->subscriptionFor($member, $status, ['ends_at' => $status === 'canceled' ? now()->subDay() : $this->periodEnd()]);
        $this->stripeNeverCalled();
        Sanctum::actingAs($member);

        $this->postJson(self::CANCEL_URL)
            ->assertConflict()
            ->assertExactJson(['success' => false, 'message' => self::CANNOT_CANCEL]);
    }

    #[Test]
    public function overdue_payment_wins_over_a_scheduled_end_date(): void
    {
        $member = $this->member();
        $member->forceFill(['grace_ends_at' => now()->addDays(5)])->save();
        $this->subscriptionFor($member, 'past_due', ['ends_at' => $this->periodEnd()]);
        $this->stripeNeverCalled();
        Sanctum::actingAs($member);

        $this->postJson(self::CANCEL_URL)
            ->assertConflict()
            ->assertExactJson(['success' => false, 'message' => self::PAYMENT_OVERDUE]);
    }

    #[Test]
    public function admin_without_subscription_gets_not_found(): void
    {
        $this->stripeNeverCalled();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson(self::CANCEL_URL)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => self::NOT_FOUND]);
    }

    #[Test]
    public function guest_cannot_cancel(): void
    {
        $this->stripeNeverCalled();

        $this->postJson(self::CANCEL_URL)
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Não autenticado.']);
    }

    #[Test]
    #[DataProvider('accountsWithoutAccess')]
    public function accounts_that_are_not_active_cannot_cancel(string $state): void
    {
        $this->stripeNeverCalled();
        Sanctum::actingAs($this->accountInState($state));

        $this->postJson(self::CANCEL_URL)
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => self::NOT_ACTIVE]);
    }

    #[Test]
    public function stripe_failure_on_cancel_returns_502_and_changes_nothing(): void
    {
        $member = $this->member();
        $subscription = $this->subscriptionFor($member);
        $this->stripe()->shouldReceive('cancelAtPeriodEnd')->andThrow(ApiConnectionException::factory('falha'));
        Sanctum::actingAs($member);

        $this->postJson(self::CANCEL_URL)
            ->assertStatus(502)
            ->assertExactJson(['success' => false, 'message' => self::PAYMENTS_DOWN]);

        $this->assertNull($subscription->fresh()->ends_at);
        Notification::assertNothingSent();
    }

    /**
     * POST e não GET: muda o estado no Stripe e exige o token CSRF em modo SPA.
     */
    #[Test]
    public function get_on_cancel_is_not_allowed(): void
    {
        $this->stripeNeverCalled();
        $member = $this->member();
        $this->subscriptionFor($member);
        Sanctum::actingAs($member);

        $this->getJson(self::CANCEL_URL)->assertMethodNotAllowed();
    }

    #[Test]
    public function cancel_or_resume_over_the_minute_limit_is_throttled_per_member(): void
    {
        $member = $this->member();
        $other = $this->member();
        $this->subscriptionFor($member);
        $this->subscriptionFor($other);
        $this->stripe();
        $this->fillRateLimit('subscription-change', 'minute:'.$member->id, 120);

        Sanctum::actingAs($member);

        $this->postJson(self::CANCEL_URL)
            ->assertTooManyRequests()
            ->assertExactJson(['success' => false, 'message' => 'Demasiados pedidos. Tente novamente mais tarde.']);
        $this->postJson(self::RESUME_URL)->assertTooManyRequests();

        Sanctum::actingAs($other);
        $this->postJson(self::CANCEL_URL)->assertOk();
    }

    #[Test]
    public function cancel_or_resume_over_the_daily_limit_is_throttled(): void
    {
        $member = $this->member();
        $this->subscriptionFor($member);
        $this->stripe();
        $this->fillRateLimit('subscription-change', 'day:'.$member->id, 10, 86400);

        Sanctum::actingAs($member);

        $this->postJson(self::CANCEL_URL)->assertTooManyRequests();
        $this->postJson(self::RESUME_URL)->assertTooManyRequests();
    }

    #[Test]
    public function member_keeps_access_after_scheduling_the_cancellation(): void
    {
        $member = $this->member();
        MemberProfile::factory()->create(['user_id' => $member->id]);
        $this->subscriptionFor($member);
        $this->stripe();
        Sanctum::actingAs($member);

        $this->postJson(self::CANCEL_URL)->assertOk();

        $this->getJson('/api/member-profile')->assertOk();
        $this->postJson('/api/auth/token', ['email' => $member->email, 'password' => 'password'])->assertOk();
    }

    #[Test]
    public function scheduled_cancellation_can_be_resumed(): void
    {
        $member = $this->member();
        $subscription = $this->subscriptionFor($member, 'active', ['ends_at' => $this->periodEnd()]);
        $this->stripe()->shouldReceive('resume')->once()
            ->withArgs(fn (Subscription $s) => $s->is($subscription))
            ->andReturnUsing(fn (Subscription $s) => $s->forceFill(['ends_at' => null])->save());
        Sanctum::actingAs($member);

        $this->postJson(self::RESUME_URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', self::RESUMED)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.cancel_at_period_end', false)
            ->assertJsonPath('data.ends_at', null);

        Notification::assertNotSentTo($member, SubscriptionCancelledNotification::class);
        Notification::assertSentToTimes($member, SubscriptionResumedNotification::class, 1);
        Notification::assertSentTo($member, SubscriptionResumedNotification::class, function ($notification) use ($member) {
            return str_contains($this->mailText($notification->toMail($member)), $this->periodEnd()->format('d/m/Y'));
        });
    }

    #[Test]
    public function resuming_without_a_scheduled_cancellation_is_a_conflict(): void
    {
        $member = $this->member();
        $this->subscriptionFor($member);
        $this->stripeNeverCalled();
        Sanctum::actingAs($member);

        $this->postJson(self::RESUME_URL)
            ->assertConflict()
            ->assertExactJson(['success' => false, 'message' => self::NOT_SCHEDULED]);

        Notification::assertNotSentTo($member, SubscriptionResumedNotification::class);
    }

    /**
     * O período já terminou e falta só o webhook: não há nada a retomar.
     */
    #[Test]
    public function resuming_after_the_end_date_is_a_conflict(): void
    {
        $member = $this->member();
        $this->subscriptionFor($member, 'active', ['ends_at' => now()->subMinute()]);
        $this->stripeNeverCalled();
        Sanctum::actingAs($member);

        $this->postJson(self::RESUME_URL)
            ->assertConflict()
            ->assertExactJson(['success' => false, 'message' => self::NOT_SCHEDULED]);
    }

    #[Test]
    public function admin_without_subscription_cannot_resume(): void
    {
        $this->stripeNeverCalled();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson(self::RESUME_URL)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => self::NOT_FOUND]);
    }

    #[Test]
    public function guest_cannot_resume(): void
    {
        $this->stripeNeverCalled();

        $this->postJson(self::RESUME_URL)
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Não autenticado.']);
    }

    #[Test]
    #[DataProvider('accountsWithoutAccess')]
    public function accounts_that_are_not_active_cannot_resume(string $state): void
    {
        $this->stripeNeverCalled();
        Sanctum::actingAs($this->accountInState($state));

        $this->postJson(self::RESUME_URL)
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => self::NOT_ACTIVE]);
    }

    #[Test]
    public function stripe_failure_on_resume_returns_502_keeps_the_cancellation_and_sends_no_email(): void
    {
        $member = $this->member();
        $subscription = $this->subscriptionFor($member, 'active', ['ends_at' => $this->periodEnd()]);
        $this->stripe()->shouldReceive('resume')->andThrow(ApiConnectionException::factory('falha'));
        Sanctum::actingAs($member);

        $this->postJson(self::RESUME_URL)
            ->assertStatus(502)
            ->assertExactJson(['success' => false, 'message' => self::PAYMENTS_DOWN]);

        $fresh = $subscription->fresh();
        $this->assertSame('active', $fresh->stripe_status);
        $this->assertTrue($this->periodEnd()->equalTo($fresh->ends_at));
        Notification::assertNotSentTo($member, SubscriptionResumedNotification::class);
    }

    #[Test]
    public function member_can_cancel_resume_and_cancel_again(): void
    {
        $member = $this->member();
        $this->subscriptionFor($member);
        $mock = $this->stripe();
        $mock->shouldReceive('cancelAtPeriodEnd')->twice()
            ->andReturnUsing(fn (Subscription $s) => $s->forceFill(['ends_at' => $this->periodEnd()])->save());
        $mock->shouldReceive('resume')->once()
            ->andReturnUsing(fn (Subscription $s) => $s->forceFill(['ends_at' => null])->save());
        Sanctum::actingAs($member);

        $this->postJson(self::CANCEL_URL)->assertOk()->assertJsonPath('data.cancel_at_period_end', true);
        $this->postJson(self::RESUME_URL)->assertOk()->assertJsonPath('data.cancel_at_period_end', false);
        $this->postJson(self::CANCEL_URL)->assertOk()->assertJsonPath('data.cancel_at_period_end', true);

        Notification::assertSentToTimes($member, SubscriptionCancelledNotification::class, 2);
        Notification::assertSentToTimes($member, SubscriptionResumedNotification::class, 1);
    }

    #[Test]
    public function reactivation_email_after_a_cancellation_says_the_subscription_ended(): void
    {
        $user = User::factory()->inactive(InactiveReason::Cancelled)->withBilling()->create();

        $mail = (new ReactivationLinkNotification)->toMail($user);
        $text = $this->mailText($mail);

        $this->assertSame('A sua subscrição ASPEC terminou', $mail->subject);
        $this->assertStringContainsString('A sua subscrição terminou e a conta está inativa.', $text);
        $this->assertStringContainsString('60,00', $text);
        $this->assertStringContainsString('sem novo período experimental', $text);
        $this->assertStringNotContainsString('falta de pagamento', $text);
        $this->assertStringStartsWith(self::FRONTEND_URL."/ativacao/{$user->id}?", $mail->actionUrl);
    }

    #[Test]
    public function reactivation_email_after_non_payment_keeps_the_non_payment_text(): void
    {
        $user = User::factory()->inactive(InactiveReason::Unpaid)->withBilling()->create();

        $mail = (new ReactivationLinkNotification)->toMail($user);

        $this->assertSame('Reative a sua conta ASPEC', $mail->subject);
        $this->assertStringContainsString('falta de pagamento', $this->mailText($mail));
    }

    #[Test]
    public function cancellation_email_shows_the_end_date_a_link_to_manage_it_and_a_warning(): void
    {
        $user = User::factory()->create();
        $endsAt = now()->addDays(20);

        $mail = (new SubscriptionCancelledNotification($endsAt))->toMail($user);
        $text = $this->mailText($mail);

        $this->assertSame('Cancelamento da subscrição ASPEC agendado', $mail->subject);
        $this->assertStringContainsString($endsAt->format('d/m/Y'), $text);
        $this->assertStringContainsString('Se não foi você', $text);
        $this->assertSame('Gerir subscrição', $mail->actionText);
        $this->assertSame(self::FRONTEND_URL.'/conta/subscricao', $mail->actionUrl);
    }

    #[Test]
    public function subscription_lifecycle_emails_are_queued_after_commit_without_links(): void
    {
        $notifications = [
            new PaymentFailedNotification(now()->addDays(7)),
            new SubscriptionCancelledNotification(now()->addDays(20)),
            new SubscriptionResumedNotification(now()->addDays(20)),
        ];

        foreach ($notifications as $notification) {
            $this->assertInstanceOf(ShouldQueue::class, $notification);
            $this->assertTrue($notification->afterCommit, $notification::class.' devia ter afterCommit.');
            $serialized = serialize($notification);
            $this->assertStringNotContainsString('/conta/subscricao', $serialized);
            $this->assertStringNotContainsString(self::FRONTEND_URL, $serialized);
        }
    }

    #[Test]
    public function resume_email_shows_the_renewal_date_the_price_a_link_to_manage_it_and_a_warning(): void
    {
        $user = User::factory()->create();
        $renewsAt = now()->addDays(20);

        $mail = (new SubscriptionResumedNotification($renewsAt))->toMail($user);
        $text = $this->mailText($mail);

        $this->assertSame('A sua subscrição ASPEC foi retomada', $mail->subject);
        $this->assertStringContainsString($renewsAt->format('d/m/Y'), $text);
        $this->assertStringContainsString('60,00', $text);
        $this->assertStringContainsString('Se não foi você', $text);
        $this->assertSame('Gerir subscrição', $mail->actionText);
        $this->assertSame(self::FRONTEND_URL.'/conta/subscricao', $mail->actionUrl);
    }

    /**
     * Membro autenticado ainda Active em memória (o middleware deixa passar) mas já bloqueado na
     * base de dados: simula o admin a bloquear enquanto o pedido está em curso.
     */
    private function memberBlockedAfterTheMiddleware(array $subscriptionAttributes = []): User
    {
        $member = $this->member();
        $this->subscriptionFor($member, 'active', $subscriptionAttributes);
        $member->load('accountStatus');
        DB::table('users')->where('id', $member->id)->update([
            'account_status_id' => AccountStatus::where('name', 'Inactive')->value('id'),
            'inactive_reason' => InactiveReason::Blocked->value,
        ]);

        return $member;
    }

    #[Test]
    public function account_blocked_while_cancelling_is_a_conflict_and_stripe_is_not_called(): void
    {
        $this->stripeNeverCalled();
        $member = $this->memberBlockedAfterTheMiddleware();
        Sanctum::actingAs($member);

        $this->postJson(self::CANCEL_URL)
            ->assertConflict()
            ->assertExactJson(['success' => false, 'message' => 'A conta já não está ativa.']);

        Notification::assertNothingSent();
    }

    #[Test]
    public function account_blocked_while_resuming_is_a_conflict_and_stripe_is_not_called(): void
    {
        $this->stripeNeverCalled();
        $member = $this->memberBlockedAfterTheMiddleware(['ends_at' => $this->periodEnd()]);
        Sanctum::actingAs($member);

        $this->postJson(self::RESUME_URL)
            ->assertConflict()
            ->assertExactJson(['success' => false, 'message' => 'A conta já não está ativa.']);

        Notification::assertNotSentTo($member, SubscriptionResumedNotification::class);
    }

    /**
     * O payment_failed (que abre a carência) pode chegar antes do updated(past_due): nesse
     * intervalo a linha local ainda diz active, mas o cancelamento tem de ser recusado na mesma.
     */
    #[Test]
    public function member_in_grace_cannot_cancel_even_if_the_local_subscription_still_says_active(): void
    {
        $member = $this->member();
        $member->forceFill(['grace_ends_at' => now()->addDays(5)])->save();
        $this->subscriptionFor($member, 'active');
        $this->stripeNeverCalled();
        Sanctum::actingAs($member);

        $this->postJson(self::CANCEL_URL)
            ->assertConflict()
            ->assertExactJson(['success' => false, 'message' => self::PAYMENT_OVERDUE]);
    }

    // --- Mesmo contrato do GET /api/subscription ---

    #[Test]
    public function cancel_returns_the_same_keys_as_the_subscription_endpoint(): void
    {
        $member = $this->member();
        $startedAt = now()->subDays(5);
        $member->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_trial_keys',
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'trial_ends_at' => $startedAt->copy()->addDays(30),
            'created_at' => $startedAt,
        ]);
        $this->stripe();
        Sanctum::actingAs($member);

        $response = $this->postJson(self::CANCEL_URL)
            ->assertOk()
            ->assertJsonPath('data.trial_starts_at', $startedAt->toIso8601String())
            ->assertJsonPath('data.trial_days', 30);

        $this->assertEqualsCanonicalizing(self::DATA_KEYS, array_keys($response->json('data')));
    }

    #[Test]
    public function cancel_of_an_active_subscription_removes_the_next_payment(): void
    {
        $member = $this->member();
        $this->subscriptionFor($member);
        Invoice::factory()->for($member)->demo()->issued()->create(['created_at' => now()->subDays(10)]);
        $this->stripe();
        Sanctum::actingAs($member);

        $this->postJson(self::CANCEL_URL)
            ->assertOk()
            ->assertJsonPath('data.next_payment_at', null);
    }

    #[Test]
    public function resume_returns_the_same_keys_and_the_next_payment_again(): void
    {
        $member = $this->member();
        $paidAt = now()->subDays(10);
        $this->subscriptionFor($member, 'active', ['ends_at' => $this->periodEnd()]);
        Invoice::factory()->for($member)->demo()->issued()->create(['created_at' => $paidAt]);
        $this->stripe();
        Sanctum::actingAs($member);

        $response = $this->postJson(self::RESUME_URL)
            ->assertOk()
            ->assertJsonPath('data.trial_starts_at', null)
            ->assertJsonPath('data.trial_days', null)
            ->assertJsonPath('data.next_payment_at', $paidAt->copy()->addMonthNoOverflow()->toIso8601String());

        $this->assertEqualsCanonicalizing(self::DATA_KEYS, array_keys($response->json('data')));
    }
}
