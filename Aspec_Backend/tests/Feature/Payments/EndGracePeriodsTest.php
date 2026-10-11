<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Jobs\CancelStripeSubscriptionJob;
use App\Models\User;
use App\Notifications\ReactivationLinkNotification;
use App\Services\Payments\StripeSubscriptionService;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Cashier\Subscription;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Stripe\Exception\ApiConnectionException;
use Symfony\Component\Console\Command\Command;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * Comando subscriptions:end-grace: fonte de verdade do fim da carência. Cada conta com a carência
 * expirada é confirmada no Stripe antes de ficar Inactive (desativar quem pagou é o pior erro);
 * depois de desativada, a subscrição é cancelada pelo mesmo caminho do bloqueio. O Stripe é mockado.
 */
class EndGracePeriodsTest extends TestCase
{
    use RefreshDatabase, SignsStripeWebhooks;

    private const COMMAND = 'subscriptions:end-grace';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['app.frontend_url' => 'http://localhost:5173', 'subscription.grace_days' => 7]);
    }

    /**
     * Membro Active em carência (7 dias a partir de agora) com uma subscrição past_due.
     */
    private function memberInGrace(string $subscriptionId = 'sub_test_1', string $localStatus = 'past_due'): User
    {
        $user = User::factory()->withBilling()->create([
            'stripe_id' => 'cus_'.fake()->unique()->lexify('??????????'),
        ]);
        $user->forceFill(['grace_ends_at' => now()->addDays(7)])->save();
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => $subscriptionId,
            'stripe_status' => $localStatus,
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'ends_at' => $localStatus === 'canceled' ? now() : null,
        ]);

        return $user;
    }

    private function stripeSays(string $status): MockInterface
    {
        return $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) use ($status) {
            $mock->shouldReceive('retrieveStatus')->andReturn($status);
            $mock->shouldReceive('cancelNow')->byDefault();
            $mock->shouldNotReceive('expireCheckout');
        });
    }

    private function assertDeactivatedForNonPayment(User $user): void
    {
        $fresh = $user->fresh();
        $this->assertSame('Inactive', $fresh->accountStatus->name);
        $this->assertSame(InactiveReason::Unpaid, $fresh->inactive_reason);
        $this->assertNull($fresh->grace_ends_at);
        Notification::assertSentToTimes($fresh, ReactivationLinkNotification::class, 1);
    }

    private function addSession(User $user): void
    {
        DB::table(config('session.table'))->insert([
            'id' => 'sessao-'.$user->id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }

    #[Test]
    public function expired_grace_confirmed_unpaid_in_stripe_deactivates_the_account_then_cancels_the_subscription(): void
    {
        $user = $this->memberInGrace();
        $user->createToken('membro');
        $this->addSession($user);
        $statusWhenCancelled = null;
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) use ($user, &$statusWhenCancelled) {
            $mock->shouldReceive('retrieveStatus')
                ->once()
                ->withArgs(fn (Subscription $subscription) => $subscription->stripe_id === 'sub_test_1')
                ->andReturn('past_due');
            $mock->shouldReceive('cancelNow')
                ->once()
                ->withArgs(fn (Subscription $subscription) => $subscription->stripe_id === 'sub_test_1')
                ->andReturnUsing(function () use ($user, &$statusWhenCancelled) {
                    $statusWhenCancelled = User::find($user->id)->accountStatus->name;
                });
        });
        $this->travel(8)->days();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertDeactivatedForNonPayment($user);
        $this->assertSame('Inactive', $statusWhenCancelled);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing(config('session.table'), ['user_id' => $user->id]);
    }

    #[Test]
    public function expired_grace_with_stripe_unpaid_deactivates_the_account(): void
    {
        $user = $this->memberInGrace();
        $mock = $this->stripeSays('unpaid');
        $mock->shouldReceive('cancelNow')->once();
        $this->travel(8)->days();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertDeactivatedForNonPayment($user);
    }

    /**
     * O pagamento chegou ao Stripe mas o webhook perdeu-se ou atrasou-se: o membro pagou e
     * não pode perder o acesso.
     */
    #[Test]
    public function expired_grace_but_paid_in_stripe_keeps_the_account_active_and_clears_the_grace(): void
    {
        $user = $this->memberInGrace();
        $mock = $this->stripeSays('active');
        $mock->shouldNotReceive('cancelNow');
        $this->travel(8)->days();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $fresh = $user->fresh();
        $this->assertSame('Active', $fresh->accountStatus->name);
        $this->assertNull($fresh->grace_ends_at);
        $this->assertDatabaseHas('subscriptions', ['stripe_id' => 'sub_test_1', 'stripe_status' => 'active']);
        Notification::assertNothingSent();
    }

    #[Test]
    public function subscription_already_canceled_in_stripe_is_marked_locally_and_not_cancelled_again(): void
    {
        $user = $this->memberInGrace();
        $mock = $this->stripeSays('canceled');
        $mock->shouldNotReceive('cancelNow');
        $this->travel(8)->days();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('subscriptions', ['stripe_id' => 'sub_test_1', 'stripe_status' => 'canceled']);
        $this->assertDeactivatedForNonPayment($user);
    }

    #[Test]
    public function grace_still_running_is_not_checked_in_stripe(): void
    {
        $user = $this->memberInGrace();
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('retrieveStatus');
            $mock->shouldNotReceive('cancelNow');
        });
        $this->travel(6)->days();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $fresh = $user->fresh();
        $this->assertSame('Active', $fresh->accountStatus->name);
        $this->assertNotNull($fresh->grace_ends_at);
        Notification::assertNothingSent();
    }

    #[Test]
    public function grace_ending_exactly_now_deactivates_the_account(): void
    {
        $this->freezeSecond();
        $user = $this->memberInGrace();
        $user->forceFill(['grace_ends_at' => now()])->save();
        $mock = $this->stripeSays('past_due');
        $mock->shouldReceive('cancelNow')->once();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertDeactivatedForNonPayment($user);
    }

    #[Test]
    public function blocked_account_with_an_old_grace_date_is_not_selected(): void
    {
        $user = User::factory()->inactive(InactiveReason::Blocked)->withBilling()->create(['stripe_id' => 'cus_bloqueado']);
        $user->forceFill(['grace_ends_at' => now()->subDays(3)])->save();
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('retrieveStatus');
            $mock->shouldNotReceive('cancelNow');
        });

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertSame(InactiveReason::Blocked, $user->fresh()->inactive_reason);
        Notification::assertNothingSent();
    }

    /**
     * Stripe indisponível para uma conta: essa fica como está (nunca se desativa sem confirmar)
     * e é tentada na hora seguinte; as outras contas continuam a ser processadas.
     */
    #[Test]
    public function stripe_failure_skips_only_that_account_logs_a_warning_and_fails_the_run(): void
    {
        Log::spy();
        $unreachable = $this->memberInGrace('sub_sem_resposta');
        $other = $this->memberInGrace('sub_outro');
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('retrieveStatus')->andReturnUsing(function (Subscription $subscription) {
                if ($subscription->stripe_id === 'sub_sem_resposta') {
                    throw ApiConnectionException::factory('falha');
                }

                return 'past_due';
            });
            $mock->shouldReceive('cancelNow')
                ->once()
                ->withArgs(fn (Subscription $subscription) => $subscription->stripe_id === 'sub_outro');
        });
        $this->travel(8)->days();

        $this->artisan(self::COMMAND)->assertExitCode(Command::FAILURE);

        $fresh = $unreachable->fresh();
        $this->assertSame('Active', $fresh->accountStatus->name);
        $this->assertNotNull($fresh->grace_ends_at);
        Notification::assertNotSentTo($fresh, ReactivationLinkNotification::class);
        $this->assertDeactivatedForNonPayment($other);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => ($context['user_id'] ?? null) === $unreachable->id
                && ! str_contains(json_encode([$message, $context]), $unreachable->email))
            ->once();
    }

    #[Test]
    public function stripe_failure_when_cancelling_keeps_the_account_inactive_and_queues_the_retry_job(): void
    {
        Queue::fake();
        $user = $this->memberInGrace();
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('retrieveStatus')->andReturn('past_due');
            $mock->shouldReceive('cancelNow')->andThrow(ApiConnectionException::factory('falha'));
        });
        $this->travel(8)->days();

        $this->artisan(self::COMMAND);

        $this->assertDeactivatedForNonPayment($user);
        Queue::assertPushed(CancelStripeSubscriptionJob::class, fn ($job) => $job->userId === $user->id
            && $job->subscriptionStripeIds === ['sub_test_1']);
    }

    #[Test]
    public function account_without_an_ongoing_subscription_is_deactivated_without_calling_stripe(): void
    {
        $user = $this->memberInGrace('sub_test_1', 'canceled');
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('retrieveStatus');
            $mock->shouldNotReceive('cancelNow');
            $mock->shouldNotReceive('expireCheckout');
        });
        $this->travel(8)->days();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertDeactivatedForNonPayment($user);
    }

    /**
     * Um webhook de pagamento (ou o admin) muda a conta entre a seleção e o lock: a decisão
     * usa o estado relido com lock, não o retrato da seleção.
     */
    #[Test]
    public function grace_cleared_between_selection_and_lock_does_not_deactivate(): void
    {
        $user = $this->memberInGrace();
        $mock = $this->stripeSays('past_due');
        $mock->shouldNotReceive('cancelNow');
        $this->travel(8)->days();
        $cleared = false;
        Event::listen('eloquent.retrieved: '.User::class, function () use (&$cleared, $user) {
            if ($cleared) {
                return;
            }
            $cleared = true;
            DB::table('users')->where('id', $user->id)->update(['grace_ends_at' => null]);
        });

        $this->artisan(self::COMMAND);

        $fresh = $user->fresh();
        $this->assertSame('Active', $fresh->accountStatus->name);
        $this->assertNull($fresh->inactive_reason);
        Notification::assertNothingSent();
    }

    #[Test]
    public function running_the_command_twice_sends_one_reactivation_email(): void
    {
        $user = $this->memberInGrace();
        $mock = $this->stripeSays('past_due');
        $mock->shouldReceive('cancelNow')->once();
        $this->travel(8)->days();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);
        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertDeactivatedForNonPayment($user);
    }

    #[Test]
    public function command_is_scheduled_hourly_without_overlapping_for_at_most_55_minutes(): void
    {
        $this->app->make(Kernel::class)->bootstrap();

        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event) => str_contains((string) $event->command, self::COMMAND));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(55, $event->expiresAt);
    }

    /**
     * Webhooks e cancelar/retomar prendem users e depois escrevem subscriptions; o comando tem de
     * seguir a mesma ordem, senão duas transações esperam uma pela outra (deadlock no MySQL).
     * O SQLite não gera FOR UPDATE: prova a ordem das queries, não o lock.
     */
    #[Test]
    public function paid_in_stripe_reads_the_user_before_writing_the_subscription_in_the_same_transaction(): void
    {
        $this->memberInGrace();
        $mock = $this->stripeSays('active');
        $mock->shouldNotReceive('cancelNow');
        $this->travel(8)->days();
        $log = [];
        Event::listen(TransactionBeginning::class, function () use (&$log) {
            $log[] = 'begin';
        });
        DB::listen(function (QueryExecuted $query) use (&$log) {
            $log[] = strtolower($query->sql);
        });

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $update = collect($log)->search(fn (string $sql) => str_starts_with($sql, 'update "subscriptions"'));
        $this->assertIsInt($update);
        $begin = collect($log)->slice(0, $update)->keys()->filter(fn ($i) => $log[$i] === 'begin')->last();
        $this->assertNotNull($begin);
        $userSelect = collect($log)->slice($begin, $update - $begin)
            ->search(fn (string $sql) => str_starts_with($sql, 'select') && str_contains($sql, 'from "users"'));
        $this->assertIsInt($userSelect, 'A transação tem de ler o utilizador antes de atualizar a subscrição.');
    }

    /**
     * Um updated(active) sem passagem de past_due (ex.: só metadata) chega depois da falha: a
     * carência mantém-se e o comando desativa a conta no fim.
     */
    #[Test]
    public function update_without_payment_after_a_failure_does_not_save_the_account_from_deactivation(): void
    {
        $user = User::factory()->withBilling()->create(['stripe_id' => 'cus_test_1']);
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_1',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);
        $mock = $this->stripeSays('past_due');
        $mock->shouldReceive('cancelNow')->once();

        $this->postStripeWebhook($this->stripeEvent(
            'invoice.payment_failed',
            $this->stripeInvoice('cus_test_1', 6000, null, 'open'),
        ))->assertOk();
        $this->postStripeWebhook($this->stripeEvent(
            'customer.subscription.updated',
            $this->stripeSubscription('cus_test_1', 'active'),
            previousAttributes: ['metadata' => []],
        ))->assertOk();
        $this->assertNotNull($user->fresh()->grace_ends_at);

        $this->travel(8)->days();
        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertDeactivatedForNonPayment($user);
    }
}
