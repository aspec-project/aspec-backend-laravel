<?php

namespace Tests\Feature\Auth;

use App\Jobs\SendPasswordResetLinkJob;
use App\Models\AccountStatus;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/auth/forgot-password';

    #[Test]
    public function active_user_queues_password_reset_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->postJson(self::URL, ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('success', true);

        Queue::assertPushed(
            SendPasswordResetLinkJob::class,
            fn (SendPasswordResetLinkJob $job) => $job->userId === $user->id
        );

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    #[Test]
    public function non_active_users_do_not_queue_password_reset_job(): void
    {
        Queue::fake();

        foreach (['pending', 'approved', 'inactive'] as $state) {
            $user = User::factory()->{$state}()->create();

            $this->postJson(self::URL, ['email' => $user->email])->assertOk();

            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        }

        Queue::assertNotPushed(SendPasswordResetLinkJob::class);
    }

    #[Test]
    public function unknown_email_receives_same_response_without_queueing_a_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $knownResponse = $this->postJson(self::URL, ['email' => $user->email]);
        $unknownResponse = $this->postJson(self::URL, ['email' => 'nao.existe@example.com']);

        $unknownResponse->assertOk();
        $this->assertSame($knownResponse->json(), $unknownResponse->json());

        Queue::assertPushed(
            SendPasswordResetLinkJob::class,
            fn (SendPasswordResetLinkJob $job) => $job->userId === $user->id
        );
        Queue::assertPushed(SendPasswordResetLinkJob::class, 1);
    }

    #[Test]
    public function queued_job_sends_notification_and_creates_reset_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $job = new SendPasswordResetLinkJob($user->id);

        $job->handle();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    #[Test]
    public function queued_job_does_not_send_email_if_account_is_no_longer_active(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $job = new SendPasswordResetLinkJob($user->id);

        $user->forceFill([
            'account_status_id' => AccountStatus::where('name', 'Inactive')->value('id'),
        ])->save();

        $job->handle();

        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    #[Test]
    public function email_is_required_and_must_be_valid(): void
    {
        $this->postJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson(self::URL, ['email' => 'invalido'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function requests_are_rate_limited(): void
    {
        Queue::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::URL, ['email' => 'x@example.com'])->assertOk();
        }

        $this->postJson(self::URL, ['email' => 'x@example.com'])
            ->assertTooManyRequests();
    }
}