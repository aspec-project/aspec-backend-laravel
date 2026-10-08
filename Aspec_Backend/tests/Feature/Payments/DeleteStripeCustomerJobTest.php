<?php

namespace Tests\Feature\Payments;

use App\Jobs\DeleteStripeCustomerJob;
use App\Models\User;
use App\Services\Payments\StripeCustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stripe\Exception\ApiConnectionException;
use Tests\TestCase;

class DeleteStripeCustomerJobTest extends TestCase
{
    use RefreshDatabase;

    private const NIF = '245678901';

    private function deletedUserWithStripeId(?string $stripeId = 'cus_test123'): User
    {
        $user = User::factory()->create();
        $user->forceFill(['stripe_id' => $stripeId, 'nif' => self::NIF])->save();
        $user->delete();

        return $user;
    }

    private function runJob(string $userId): void
    {
        app()->call([new DeleteStripeCustomerJob($userId), 'handle']);
    }

    #[Test]
    public function deletes_the_customer_of_a_soft_deleted_user_and_clears_stripe_id(): void
    {
        $user = $this->deletedUserWithStripeId();
        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('delete')->once()->with('cus_test123');
        });

        $this->runJob($user->id);

        $this->assertNull(User::withTrashed()->find($user->id)->stripe_id);
    }

    #[Test]
    public function does_nothing_when_stripe_id_is_already_null(): void
    {
        $user = $this->deletedUserWithStripeId(null);
        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('delete');
        });

        $this->runJob($user->id);

        $this->assertNull(User::withTrashed()->find($user->id)->stripe_id);
    }

    #[Test]
    public function does_nothing_when_the_user_does_not_exist(): void
    {
        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('delete');
        });

        $this->runJob((string) Str::uuid());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function stripe_failure_propagates_and_keeps_stripe_id_for_the_retry(): void
    {
        $user = $this->deletedUserWithStripeId();
        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('delete')->once()->andThrow(ApiConnectionException::factory('falha'));
        });

        try {
            $this->runJob($user->id);
            $this->fail('A exceção do Stripe devia propagar para a fila repetir o job.');
        } catch (ApiConnectionException) {
        }

        $this->assertSame('cus_test123', User::withTrashed()->find($user->id)->stripe_id);
    }

    #[Test]
    public function serialized_job_carries_only_the_user_id(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['stripe_id' => 'cus_test123', 'nif' => self::NIF])->save();

        $job = new DeleteStripeCustomerJob($user->id);
        $payload = serialize($job);

        $this->assertSame($user->id, $job->userId);
        $this->assertStringContainsString($user->id, $payload);
        $this->assertStringNotContainsString($user->email, $payload);
        $this->assertStringNotContainsString(self::NIF, $payload);
    }

    #[Test]
    public function job_is_retried_five_times_with_increasing_backoff(): void
    {
        $job = new DeleteStripeCustomerJob((string) Str::uuid());

        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 900, 3600], $job->backoff());
    }

    #[Test]
    public function failed_logs_an_error_with_only_the_user_id_and_exception_class(): void
    {
        Log::spy();
        $userId = (string) Str::uuid();

        (new DeleteStripeCustomerJob($userId))->failed(new RuntimeException('falha para socio@example.com'));

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []) => $context == [
                'user_id' => $userId,
                'exception' => RuntimeException::class,
            ])
            ->once();
    }
}
