<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/auth/reset-password';
    private const NEW_PASSWORD = 'NewSecret456!';

    private function payload(User $user, string $token, array $overrides = []): array
    {
        return [
            'email' => $user->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
            ...$overrides,
        ];
    }

    #[Test]
    public function active_user_can_reset_password_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson(self::URL, $this->payload($user, $token))
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Password redefinida com sucesso. Inicie sessão novamente.',
                'data' => null,
            ]);

        $storedPassword = $user->fresh()->password;

        $this->assertNotSame(self::NEW_PASSWORD, $storedPassword);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $storedPassword));
        Notification::assertSentTo($user, PasswordChangedNotification::class);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    #[Test]
    public function reset_revokes_user_tokens_and_sessions_but_preserves_other_users(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $user->createToken('web');
        $user->createToken('mobile');
        $otherUser->createToken('web');

        $sessionId = Str::random(40);
        $otherSessionId = Str::random(40);

        foreach ([
            ['id' => $sessionId, 'user_id' => $user->id],
            ['id' => $otherSessionId, 'user_id' => $otherUser->id],
        ] as $session) {
            DB::table(config('session.table'))->insert([
                ...$session,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'payload' => '',
                'last_activity' => now()->timestamp,
            ]);
        }

        $token = Password::broker()->createToken($user);

        $this->postJson(self::URL, $this->payload($user, $token))->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $otherUser->tokens()->count());
        $this->assertDatabaseMissing(config('session.table'), ['id' => $sessionId]);
        $this->assertDatabaseHas(config('session.table'), ['id' => $otherSessionId]);
    }

    #[Test]
    public function invalid_token_returns_error_without_changing_password(): void
    {
        $user = User::factory()->create();
        $oldPassword = $user->password;

        $this->postJson(self::URL, $this->payload($user, 'token-invalido'))
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'message' => 'Não foi possível redefinir a password. O token é inválido ou expirou.',
            ]);

        $this->assertSame($oldPassword, $user->fresh()->password);
    }

    #[Test]
    public function inactive_user_cannot_reset_password_even_with_valid_token(): void
    {
        $user = User::factory()->inactive()->create();
        $oldPassword = $user->password;
        $token = Password::broker()->createToken($user);

        $this->postJson(self::URL, $this->payload($user, $token))
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'message' => 'Não foi possível redefinir a password. O token é inválido ou expirou.',
            ]);

        $this->assertSame($oldPassword, $user->fresh()->password);
    }

    #[Test]
    public function expired_token_cannot_reset_password(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->travel(61)->minutes();

        $this->postJson(self::URL, $this->payload($user, $token))
            ->assertUnprocessable();

        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    #[Test]
    public function reset_token_can_only_be_used_once(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson(self::URL, $this->payload($user, $token))->assertOk();

        $this->postJson(self::URL, $this->payload(
            $user,
            $token,
            ['password' => 'AnotherSecret789!', 'password_confirmation' => 'AnotherSecret789!']
        ))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function required_fields_and_password_confirmation_are_validated(): void
    {
        $this->postJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'token', 'password']);

        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson(self::URL, $this->payload(
            $user,
            $token,
            ['password_confirmation' => 'DifferentSecret789!']
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }
}