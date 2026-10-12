<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Exceptions\ActivationLinkNoLongerValidException;
use App\Jobs\CancelStripeSubscriptionJob;
use App\Models\User;
use App\Services\Payments\StripeSubscriptionService;
use App\Services\Payments\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Cashier\Subscription;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Stripe\Exception\ApiConnectionException;
use Tests\TestCase;

/**
 * Regras do SubscriptionService chamadas diretamente (sem HTTP), para casos que o endpoint
 * não consegue provocar, como a conta mudar de estado entre a validação do pedido e o lock.
 */
class SubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function account_blocked_before_the_lock_cannot_start_a_checkout(): void
    {
        // O controller viu a conta Approved; entretanto o admin bloqueou-a.
        $user = User::factory()->approved()->create();
        User::whereKey($user->id)->first()->deactivate(InactiveReason::Blocked->value);
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('createCheckout');
            $mock->shouldNotReceive('retrieveCheckout');
        });

        try {
            app(SubscriptionService::class)->startCheckout($user, ['billing_name' => 'X'], 'http://localhost:5173/ativacao/x');
            $this->fail('Devia ter lançado ActivationLinkNoLongerValidException.');
        } catch (ActivationLinkNoLongerValidException $e) {
            $this->assertSame('Este link já não é válido para esta conta.', $e->getMessage());
        }

        $fresh = $user->fresh();
        $this->assertSame(InactiveReason::Blocked, $fresh->inactive_reason);
        $this->assertNull($fresh->billing_name);
        $this->assertNull($fresh->stripe_checkout_session_id);
    }

    private function subscription(User $user, string $stripeId, string $status): Subscription
    {
        return $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => $stripeId,
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'ends_at' => $status === 'canceled' ? now()->subMonth() : null,
        ]);
    }

    private function blockedUser(): User
    {
        return User::factory()->inactive(InactiveReason::Blocked)->withBilling()->create();
    }

    #[Test]
    public function cancel_cancels_every_subscription_that_has_not_ended(): void
    {
        $user = $this->blockedUser();
        foreach (['active', 'trialing', 'past_due', 'incomplete', 'canceled', 'incomplete_expired'] as $status) {
            $this->subscription($user, "sub_{$status}", $status);
        }
        $cancelled = [];
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) use (&$cancelled) {
            $mock->shouldReceive('cancelNow')->times(4)->andReturnUsing(function (Subscription $s) use (&$cancelled) {
                $cancelled[] = $s->stripe_id;
            });
            $mock->shouldNotReceive('expireCheckout');
        });

        app(SubscriptionService::class)->cancel($user);

        $this->assertEqualsCanonicalizing(['sub_active', 'sub_trialing', 'sub_past_due', 'sub_incomplete'], $cancelled);
    }

    #[Test]
    public function cancel_expires_the_stored_checkout_session_and_clears_it(): void
    {
        $user = $this->blockedUser();
        $user->forceFill(['stripe_checkout_session_id' => 'cs_test_1'])->save();
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('expireCheckout')->once()->with('cs_test_1');
        });

        app(SubscriptionService::class)->cancel($user);

        $this->assertNull($user->fresh()->stripe_checkout_session_id);
    }

    #[Test]
    public function cancel_without_subscriptions_or_session_does_not_call_stripe(): void
    {
        Queue::fake();
        $user = $this->blockedUser();
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('cancelNow');
            $mock->shouldNotReceive('expireCheckout');
        });

        app(SubscriptionService::class)->cancel($user);

        Queue::assertNothingPushed();
    }

    /**
     * A instância recebida pode estar desatualizada (ex.: um Checkout de reativação gravou a sessão
     * depois de o admin carregar o utilizador); o que conta é o que está na base de dados.
     */
    #[Test]
    public function cancel_uses_the_checkout_session_stored_in_the_database_not_the_one_in_memory(): void
    {
        $user = $this->blockedUser();
        DB::table('users')->where('id', $user->id)->update(['stripe_checkout_session_id' => 'cs_new']);
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('expireCheckout')->once()->with('cs_new');
        });

        app(SubscriptionService::class)->cancel($user);

        $this->assertNull($user->fresh()->stripe_checkout_session_id);
    }

    #[Test]
    public function cancel_uses_the_subscriptions_stored_in_the_database_not_the_loaded_relation(): void
    {
        $user = $this->blockedUser();
        $user->load('subscriptions');
        Subscription::create([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_late',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelNow')->once()->withArgs(fn (Subscription $s) => $s->stripe_id === 'sub_late');
        });

        app(SubscriptionService::class)->cancel($user);
    }

    /**
     * Uma sessão aberta ainda pode ser paga a qualquer momento e criar uma subscrição nova;
     * as subscrições existentes só voltam a cobrar na renovação.
     */
    #[Test]
    public function cancel_expires_the_checkout_session_before_cancelling_subscriptions(): void
    {
        $user = $this->blockedUser();
        $user->forceFill(['stripe_checkout_session_id' => 'cs_test_1'])->save();
        $this->subscription($user, 'sub_test_1', 'active');
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('expireCheckout')->once()->with('cs_test_1')->ordered();
            $mock->shouldReceive('cancelNow')->once()->ordered();
        });

        app(SubscriptionService::class)->cancel($user);
    }

    #[Test]
    public function failure_expiring_the_session_still_cancels_the_subscriptions_and_queues_the_whole_snapshot(): void
    {
        Queue::fake();
        $user = $this->blockedUser();
        $user->forceFill(['stripe_checkout_session_id' => 'cs_test_1'])->save();
        $this->subscription($user, 'sub_test_1', 'active');
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('expireCheckout')->once()->andThrow(ApiConnectionException::factory('falha'));
            $mock->shouldReceive('cancelNow')->once()->withArgs(fn (Subscription $s) => $s->stripe_id === 'sub_test_1');
        });

        app(SubscriptionService::class)->cancel($user);

        Queue::assertPushed(CancelStripeSubscriptionJob::class, fn ($job) => $job->userId === $user->id
            && $job->subscriptionStripeIds === ['sub_test_1']
            && $job->checkoutSessionId === 'cs_test_1');
        $this->assertSame('cs_test_1', $user->fresh()->stripe_checkout_session_id);
    }

    #[Test]
    public function failure_cancelling_one_subscription_still_cancels_the_other(): void
    {
        Queue::fake();
        $user = $this->blockedUser();
        $this->subscription($user, 'sub_a', 'active');
        $this->subscription($user, 'sub_b', 'past_due');
        $attempted = [];
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) use (&$attempted) {
            $mock->shouldReceive('cancelNow')->twice()->andReturnUsing(function (Subscription $s) use (&$attempted) {
                $attempted[] = $s->stripe_id;
                if ($s->stripe_id === 'sub_a') {
                    throw ApiConnectionException::factory('falha');
                }
            });
        });

        app(SubscriptionService::class)->cancel($user);

        $this->assertEqualsCanonicalizing(['sub_a', 'sub_b'], $attempted);
        Queue::assertPushed(CancelStripeSubscriptionJob::class, fn ($job) => $job->userId === $user->id
            && collect($job->subscriptionStripeIds)->sort()->values()->all() === ['sub_a', 'sub_b']
            && $job->checkoutSessionId === null);
    }

    #[Test]
    public function stripe_failure_does_not_throw_and_logs_a_warning_without_personal_data(): void
    {
        Queue::fake();
        Log::spy();
        $user = $this->blockedUser();
        $user->forceFill(['stripe_checkout_session_id' => 'cs_test_1'])->save();
        $this->subscription($user, 'sub_test_1', 'active');
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('expireCheckout')->once()->with('cs_test_1');
            $mock->shouldReceive('cancelNow')->once()->andThrow(ApiConnectionException::factory('falha'));
        });

        app(SubscriptionService::class)->cancel($user);

        Queue::assertPushed(CancelStripeSubscriptionJob::class, fn ($job) => $job->userId === $user->id
            && $job->subscriptionStripeIds === ['sub_test_1']
            && $job->checkoutSessionId === 'cs_test_1');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => $context['user_id'] === $user->id
                && $context['exception'] === ApiConnectionException::class
                && array_key_exists('stripe_request_id', $context)
                && ! str_contains(json_encode($context), $user->email)
                && ! str_contains(json_encode($context), $user->nif))
            ->once();
        $this->assertNull($user->fresh()->stripe_checkout_session_id);
        $this->assertSame('active', $user->subscriptions()->first()->stripe_status);
    }

    #[Test]
    public function fallback_job_is_only_dispatched_after_the_commit(): void
    {
        Queue::fake();
        $user = $this->blockedUser();
        $this->subscription($user, 'sub_test_1', 'active');
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelNow')->andThrow(ApiConnectionException::factory('falha'));
        });

        app(SubscriptionService::class)->cancel($user);

        Queue::assertPushed(CancelStripeSubscriptionJob::class, fn ($job) => $job->afterCommit === true);
    }

    /**
     * Com a fila sync (phpunit, ou um .env com sync) o job de recurso corre logo e volta a falhar;
     * mesmo assim o bloqueio feito pelo admin não pode acabar num 500.
     */
    #[Test]
    public function cancel_does_not_throw_even_when_the_fallback_job_runs_synchronously_and_fails(): void
    {
        Log::spy();
        $user = $this->blockedUser();
        $user->forceFill(['stripe_checkout_session_id' => 'cs_test_1'])->save();
        $this->subscription($user, 'sub_test_1', 'active');
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('expireCheckout')->andThrow(ApiConnectionException::factory('falha'));
            $mock->shouldReceive('cancelNow')->andThrow(ApiConnectionException::factory('falha'));
        });

        app(SubscriptionService::class)->cancel($user);

        $fresh = $user->fresh();
        $this->assertSame('Inactive', $fresh->accountStatus->name);
        $this->assertSame(InactiveReason::Blocked, $fresh->inactive_reason);
    }

    #[Test]
    public function cancel_or_fail_propagates_the_stripe_error(): void
    {
        $user = $this->blockedUser();
        $this->subscription($user, 'sub_test_1', 'active');
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelNow')->once()->andThrow(ApiConnectionException::factory('falha'));
        });

        $this->expectException(ApiConnectionException::class);

        app(SubscriptionService::class)->cancelOrFail($user->id, ['sub_test_1'], null);
    }

    #[Test]
    public function cancel_or_fail_with_two_failures_rethrows_the_first_one(): void
    {
        $user = $this->blockedUser();
        $user->forceFill(['stripe_checkout_session_id' => 'cs_test_1'])->save();
        $this->subscription($user, 'sub_test_1', 'active');
        $sessionError = ApiConnectionException::factory('falha na sessão');
        $subscriptionError = ApiConnectionException::factory('falha na subscrição');
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) use ($sessionError, $subscriptionError) {
            $mock->shouldReceive('expireCheckout')->once()->andThrow($sessionError);
            $mock->shouldReceive('cancelNow')->once()->andThrow($subscriptionError);
        });

        try {
            app(SubscriptionService::class)->cancelOrFail($user->id, ['sub_test_1'], 'cs_test_1');
            $this->fail('Devia ter lançado a exceção do Stripe.');
        } catch (ApiConnectionException $e) {
            $this->assertSame($sessionError, $e);
        }
    }

    /**
     * O job pode correr muito depois do bloqueio: se entretanto houve desbloqueio e um Checkout
     * novo e legítimo, a sessão nova não pode ser apagada.
     */
    #[Test]
    public function cancel_or_fail_never_clears_a_different_checkout_session(): void
    {
        $user = User::factory()->approved()->withBilling()->create();
        $user->forceFill(['stripe_checkout_session_id' => 'cs_new'])->save();
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('expireCheckout')->once()->with('cs_old');
        });

        app(SubscriptionService::class)->cancelOrFail($user->id, [], 'cs_old');

        $this->assertSame('cs_new', $user->fresh()->stripe_checkout_session_id);
    }
}
