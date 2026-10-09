<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/auth/forgot-password';

    #[Test]
    public function test_active_user_receives_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson(self::URL, ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    #[Test]
    public function test_non_active_users_do_not_receive_reset_link(): void
    {
        Notification::fake();

        foreach (['pending', 'approved', 'inactive'] as $state) {
            $user = User::factory()->{$state}()->create();

            $this->postJson(self::URL, ['email' => $user->email])->assertOk();
            Notification::assertNotSentTo($user, ResetPasswordNotification::class);
            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        }
    }

    #[Test]
    public function test_response_is_identical_for_unknown_email(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->postJson(self::URL, ['email' => $user->email]);
        $unknown = $this->postJson(self::URL, ['email' => 'nao.existe@example.com']);

        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertCount(1);
    }

    #[Test]
    public function test_email_is_required_and_must_be_valid(): void
    {
        $this->postJson(self::URL, [])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson(self::URL, ['email' => 'invalido'])->assertStatus(422);
    }

    #[Test]
    public function test_requests_are_rate_limited(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::URL, ['email' => 'x@example.com'])->assertOk();
        }

        $this->postJson(self::URL, ['email' => 'x@example.com'])->assertStatus(429);
    }
}