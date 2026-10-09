<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Jobs\CancelStripeSubscriptionJob;
use App\Models\User;
use App\Services\Payments\StripeSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stripe\Exception\ApiConnectionException;
use Tests\TestCase;

/**
 * Job de recurso do bloqueio: recebe o "retrato" do que havia para cancelar no momento do
 * bloqueio e cancela exatamente isso, sem decidir pelo estado atual da conta.
 */
class CancelStripeSubscriptionJobTest extends TestCase
{
    use RefreshDatabase;

    private function runJob(string $userId, array $subscriptionStripeIds = [], ?string $checkoutSessionId = null): void
    {
        app()->call([new CancelStripeSubscriptionJob($userId, $subscriptionStripeIds, $checkoutSessionId), 'handle']);
    }

    private function subscription(User $user, string $stripeId, string $status = 'active'): Subscription
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

    #[Test]
    public function cancels_exactly_the_subscriptions_it_received(): void
    {
        $user = User::factory()->inactive(InactiveReason::Blocked)->create();
        $this->subscription($user, 'sub_a');
        $this->subscription($user, 'sub_b');
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelNow')->once()->withArgs(fn (Subscription $s) => $s->stripe_id === 'sub_a');
            $mock->shouldNotReceive('expireCheckout');
        });

        $this->runJob($user->id, ['sub_a']);
    }

    /**
     * A subscrição antiga, do momento do bloqueio, não pode continuar a cobrar só porque a conta
     * voltou a estar ativa entretanto.
     */
    #[Test]
    public function cancels_the_snapshot_even_when_the_account_is_active_again(): void
    {
        $user = User::factory()->create();
        $this->subscription($user, 'sub_a');
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelNow')->once()->withArgs(fn (Subscription $s) => $s->stripe_id === 'sub_a');
        });

        $this->runJob($user->id, ['sub_a']);
    }

    #[Test]
    public function after_an_unblock_only_the_old_checkout_session_is_expired(): void
    {
        $user = User::factory()->approved()->withBilling()->create();
        $user->forceFill(['stripe_checkout_session_id' => 'cs_new'])->save();
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('expireCheckout')->once()->with('cs_old');
        });

        $this->runJob($user->id, [], 'cs_old');

        $this->assertSame('cs_new', $user->fresh()->stripe_checkout_session_id);
    }

    #[Test]
    public function subscription_already_cancelled_locally_is_not_sent_to_stripe(): void
    {
        $user = User::factory()->inactive(InactiveReason::Blocked)->create();
        $this->subscription($user, 'sub_a', 'canceled');
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('cancelNow');
        });

        $this->runJob($user->id, ['sub_a']);
    }

    #[Test]
    public function runs_for_an_anonymized_user_and_ignores_ids_without_a_local_row(): void
    {
        $user = User::factory()->inactive(InactiveReason::Deleted)->create();
        $this->subscription($user, 'sub_a');
        $user->delete();
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelNow')->once()->withArgs(fn (Subscription $s) => $s->stripe_id === 'sub_a');
        });

        $this->runJob($user->id, ['sub_a', 'sub_sem_linha']);

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    #[Test]
    public function runs_without_error_for_a_user_that_does_not_exist(): void
    {
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('cancelNow');
            $mock->allows('expireCheckout');
        });

        $this->runJob((string) Str::uuid(), ['sub_a'], 'cs_old');
    }

    #[Test]
    public function stripe_failure_propagates_for_the_queue_to_retry(): void
    {
        $user = User::factory()->inactive(InactiveReason::Blocked)->create();
        $this->subscription($user, 'sub_a');
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelNow')->once()->andThrow(ApiConnectionException::factory('falha'));
        });

        $this->expectException(ApiConnectionException::class);

        $this->runJob($user->id, ['sub_a']);
    }

    /**
     * O payload fica nas tabelas jobs/failed_jobs: só ids do Stripe e o id do utilizador.
     */
    #[Test]
    public function serialized_job_carries_only_the_user_id_and_stripe_ids(): void
    {
        $user = User::factory()->inactive(InactiveReason::Blocked)->withBilling()->create();

        $payload = serialize(new CancelStripeSubscriptionJob($user->id, ['sub_a', 'sub_b'], 'cs_old'));

        foreach ([$user->id, 'sub_a', 'sub_b', 'cs_old'] as $expected) {
            $this->assertStringContainsString($expected, $payload);
        }
        foreach ([$user->email, $user->nif, $user->billing_name, $user->phone] as $personal) {
            $this->assertStringNotContainsString($personal, $payload);
        }
    }

    #[Test]
    public function job_is_retried_five_times_with_increasing_backoff(): void
    {
        $job = new CancelStripeSubscriptionJob((string) Str::uuid(), [], null);

        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 900, 3600], $job->backoff());
    }

    /**
     * Esgotadas as tentativas, o log tem de bastar para cancelar à mão no dashboard do Stripe.
     */
    #[Test]
    public function failed_logs_the_user_id_the_stripe_ids_and_the_exception_class_only(): void
    {
        Log::spy();
        $userId = (string) Str::uuid();

        (new CancelStripeSubscriptionJob($userId, ['sub_a'], 'cs_old'))
            ->failed(new RuntimeException('falha para socio@example.com'));

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []) => $context == [
                'user_id' => $userId,
                'subscription_stripe_ids' => ['sub_a'],
                'checkout_session_id' => 'cs_old',
                'exception' => RuntimeException::class,
            ])
            ->once();
    }
}
