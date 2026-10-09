<?php

namespace Tests\Feature\Account;

use App\Models\AccountStatus;
use App\Models\MemberProfile;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UpdatePasswordTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/account/password';
    private const OLD = 'OldSecret123!';
    private const NEW = 'NewSecret456!';

    private function makeUser(string $role = 'Member', string $status = 'Active', bool $withProfile = true): User
    {
        $user = User::factory()->create([
            'password' => self::OLD,
            'role_id' => Role::where('name', $role)->value('id'),
            'account_status_id' => AccountStatus::where('name', $status)->value('id'),
        ]);

        if ($withProfile) {
            MemberProfile::factory()->create(['user_id' => $user->id]);
        }

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return [
            'current_password' => self::OLD,
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
            ...$overrides,
        ];
    }

    private function storedHash(User $user): string
    {
        return User::whereKey($user->id)->value('password');
    }

    #[Test]
    public function success_returns_exact_contract(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->putJson(self::URL, $this->validPayload())
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Password alterada com sucesso. Inicie sessão novamente.',
                'data' => null,
            ]);
    }

    #[Test]
    public function new_password_is_stored_hashed_and_old_one_stops_working(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, $this->validPayload())->assertOk();

        $hash = $this->storedHash($user);
        $this->assertNotSame(self::NEW, $hash);
        $this->assertTrue(Hash::check(self::NEW, $hash));
        $this->assertFalse(Hash::check(self::OLD, $hash));
    }

    #[Test]
    public function response_never_contains_the_passwords(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->putJson(self::URL, $this->validPayload())
            ->assertOk()
            ->assertDontSee(self::OLD)
            ->assertDontSee(self::NEW);
    }

    #[Test]
    public function validation_error_response_never_contains_the_passwords(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->putJson(self::URL, $this->validPayload(['password_confirmation' => 'Mismatch789']))
            ->assertUnprocessable()
            ->assertDontSee(self::OLD)
            ->assertDontSee(self::NEW)
            ->assertDontSee('Mismatch789');
    }

    #[Test]
    public function all_tokens_of_the_user_including_current_are_revoked(): void
    {
        $user = $this->makeUser();
        $current = $user->createToken('web')->plainTextToken;
        $user->createToken('mobile');
        $user->createToken('tablet');

        $other = $this->makeUser();
        $other->createToken('web');
        $other->createToken('mobile');

        $this->withToken($current)->putJson(self::URL, $this->validPayload())->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertSame(2, $other->tokens()->count());
    }

    #[Test]
    public function spa_session_is_ended_after_password_change(): void
    {
        $user = $this->makeUser();
        $origin = ['Origin' => 'http://localhost:5173'];
        $cookie = config('session.cookie');

        $sessionId = $this->withHeaders($origin)
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::OLD])
            ->assertOk()
            ->getCookie($cookie)
            ->getValue();

        // Entre pedidos do mesmo teste o guard guarda o user em memória: esquecê-lo obriga
        // a autenticar só pelo cookie de sessão, como num browser.
        $this->app['auth']->forgetGuards();
        $this->withHeaders($origin)->withCookie($cookie, $sessionId)->getJson('/api/auth/me')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeaders($origin)->withCookie($cookie, $sessionId)
            ->putJson(self::URL, $this->validPayload())
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeaders($origin)->withCookie($cookie, $sessionId)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    #[Test]
    public function other_spa_session_that_only_logged_in_is_ended_after_password_change(): void
    {
        $user = $this->makeUser();
        $origin = ['Origin' => 'http://localhost:5173'];
        $cookie = config('session.cookie');
        $login = fn () => $this->withHeaders($origin)
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::OLD])
            ->assertOk()
            ->getCookie($cookie)
            ->getValue();

        $sessionA = $login();
        $this->app['auth']->forgetGuards();
        $sessionB = $login();

        $this->app['auth']->forgetGuards();
        $this->withHeaders($origin)->withCookie($cookie, $sessionA)
            ->putJson(self::URL, $this->validPayload())
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeaders($origin)->withCookie($cookie, $sessionB)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    #[Test]
    public function revoked_token_cannot_be_used_again(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->putJson(self::URL, $this->validPayload())->assertOk();

        // O guard guarda o user resolvido entre pedidos no mesmo teste; sem isto o 401 não seria provado.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->putJson(self::URL, [
                'current_password' => self::NEW,
                'password' => 'AnotherSecret789!',
                'password_confirmation' => 'AnotherSecret789!',
            ])
            ->assertUnauthorized();

        $this->assertTrue(Hash::check(self::NEW, $this->storedHash($user)));
    }

    #[Test]
    public function admin_without_profile_can_change_password(): void
    {
        $admin = $this->makeUser('Admin', 'Active', withProfile: false);
        Sanctum::actingAs($admin);

        $this->putJson(self::URL, $this->validPayload())
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check(self::NEW, $this->storedHash($admin)));
    }

    #[Test]
    public function wrong_current_password_is_rejected_with_specific_message(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->putJson(self::URL, $this->validPayload(['current_password' => 'WrongSecret000']))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['current_password' => 'A password atual está incorreta.']);
    }

    public static function invalidPayloads(): array
    {
        return [
            'wrong current password' => [['current_password' => 'WrongSecret000'], 'current_password'],
            'missing current password' => [['current_password' => null], 'current_password'],
            'missing new password' => [['password' => null, 'password_confirmation' => null], 'password'],
            'missing confirmation' => [['password_confirmation' => null], 'password'],
            'confirmation mismatch' => [['password_confirmation' => 'Mismatch789'], 'password'],
            'shorter than 8 characters' => [['password' => 'Short1!', 'password_confirmation' => 'Short1!'], 'password'],
            'without symbol' => [['password' => 'NoSymbol123', 'password_confirmation' => 'NoSymbol123'], 'password'],
            'without uppercase' => [['password' => 'lowercase123!', 'password_confirmation' => 'lowercase123!'], 'password'],
            'without lowercase' => [['password' => 'UPPERCASE123!', 'password_confirmation' => 'UPPERCASE123!'], 'password'],
            'without number' => [['password' => 'NoNumbers!!', 'password_confirmation' => 'NoNumbers!!'], 'password'],
            'same as current' => [['password' => self::OLD, 'password_confirmation' => self::OLD], 'password'],
            'non string password' => [['password' => 12345678, 'password_confirmation' => 12345678], 'password'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPayloads')]
    public function invalid_request_is_rejected_without_changing_password_or_tokens(array $overrides, string $errorField): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('web')->plainTextToken;
        $user->createToken('mobile');
        $hashBefore = $this->storedHash($user);

        // null no override = campo omitido do pedido.
        $payload = array_filter($this->validPayload($overrides), fn ($value) => $value !== null);

        $this->withToken($token)
            ->putJson(self::URL, $payload)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors([$errorField]);

        $this->assertSame($hashBefore, $this->storedHash($user));
        $this->assertSame(2, $user->tokens()->count());
    }

    #[Test]
    public function empty_request_reports_both_required_fields(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->putJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password', 'password']);
    }

    #[Test]
    public function unauthenticated_request_is_rejected(): void
    {
        $this->putJson(self::URL, $this->validPayload())
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Não autenticado.']);
    }

    #[Test]
    public function pending_member_cannot_change_password(): void
    {
        $user = $this->makeUser('Member', 'Pending');
        $hashBefore = $this->storedHash($user);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'A conta não está ativa e não pode editar dados.');

        $this->assertSame($hashBefore, $this->storedHash($user));
    }

    #[Test]
    public function inactive_member_cannot_change_password(): void
    {
        $user = $this->makeUser('Member', 'Inactive');
        $hashBefore = $this->storedHash($user);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertSame($hashBefore, $this->storedHash($user));
    }

    #[Test]
    public function requests_are_limited_to_ten_per_minute(): void
    {
        Sanctum::actingAs($this->makeUser());

        // Erros que não são do current_password, para não acionar o bloqueio de falhas (decisão 26).
        for ($i = 0; $i < 10; $i++) {
            $this->putJson(self::URL, $this->validPayload(['password_confirmation' => "Mismatch{$i}xx"]))
                ->assertUnprocessable();
        }

        $this->putJson(self::URL, $this->validPayload())->assertTooManyRequests();
    }

    // Bloqueio de falhas do current_password (decisão 26)

    private function failCurrentPassword(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->putJson(self::URL, $this->validPayload(['current_password' => "Wrong{$i}Secret"]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['current_password']);
        }
    }

    #[Test]
    public function sixth_request_after_five_failures_is_blocked_even_with_correct_password(): void
    {
        $user = $this->makeUser();
        $hashBefore = $this->storedHash($user);
        Sanctum::actingAs($user);

        $this->failCurrentPassword(5);

        // 6.º pedido: abaixo do limite de 10/min (password-update), por isso o 429 vem do bloqueio de falhas.
        $this->putJson(self::URL, $this->validPayload())
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertExactJson(['success' => false, 'message' => 'Demasiados pedidos. Tente novamente mais tarde.']);

        $this->assertSame($hashBefore, $this->storedHash($user));
    }

    #[Test]
    public function four_failures_do_not_block(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->failCurrentPassword(4);

        $this->putJson(self::URL, $this->validPayload())->assertOk();
    }

    #[Test]
    public function success_clears_the_failure_counter(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->failCurrentPassword(4);
        $this->putJson(self::URL, $this->validPayload())->assertOk();

        $this->travel(61)->seconds();
        $this->failCurrentPassword(4);

        $this->putJson(self::URL, [
            'current_password' => self::NEW,
            'password' => 'AnotherSecret789!',
            'password_confirmation' => 'AnotherSecret789!',
        ])->assertOk();

        $this->assertTrue(Hash::check('AnotherSecret789!', $this->storedHash($user)));
    }

    #[Test]
    public function other_validation_errors_do_not_count_as_failures(): void
    {
        Sanctum::actingAs($this->makeUser());

        for ($i = 0; $i < 3; $i++) {
            $this->putJson(self::URL, $this->validPayload(['password_confirmation' => "Mismatch{$i}xx"]))
                ->assertUnprocessable()
                ->assertJsonMissingValidationErrors(['current_password']);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->putJson(self::URL, $this->validPayload(['password' => 'Short1', 'password_confirmation' => 'Short1']))
                ->assertUnprocessable()
                ->assertJsonMissingValidationErrors(['current_password']);
        }

        $this->putJson(self::URL, $this->validPayload())->assertOk();
    }

    #[Test]
    public function block_expires_after_fifteen_minutes(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->failCurrentPassword(5);
        $this->putJson(self::URL, $this->validPayload())->assertTooManyRequests();

        $this->travel(16)->minutes();

        $this->putJson(self::URL, $this->validPayload())->assertOk();
        $this->assertTrue(Hash::check(self::NEW, $this->storedHash($user)));
    }

    #[Test]
    public function block_is_per_user(): void
    {
        $blocked = $this->makeUser();
        $other = $this->makeUser();

        Sanctum::actingAs($blocked);
        $this->failCurrentPassword(5);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other);

        $this->putJson(self::URL, $this->validPayload())->assertOk();
    }

    #[Test]
    public function email_change_failures_count_towards_password_block(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 2; $i++) {
            $this->putJson('/api/member-profile', ['email' => 'novo@exemplo.pt', 'current_password' => "Wrong{$i}Secret"])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['current_password']);
        }
        $this->failCurrentPassword(3);

        $this->putJson(self::URL, $this->validPayload())->assertTooManyRequests();
    }

    public static function emptyCurrentPasswords(): array
    {
        return [
            'empty string' => [''],
            'null' => [null],
        ];
    }

    #[Test]
    #[DataProvider('emptyCurrentPasswords')]
    public function empty_current_password_is_rejected_and_does_not_clear_the_counter(?string $empty): void
    {
        $user = $this->makeUser();
        $hashBefore = $this->storedHash($user);
        Sanctum::actingAs($user);

        $this->failCurrentPassword(4);

        $this->putJson(self::URL, $this->validPayload(['current_password' => $empty]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->failCurrentPassword(1);

        $this->putJson(self::URL, $this->validPayload())->assertTooManyRequests();
        $this->assertSame($hashBefore, $this->storedHash($user));
    }

    #[Test]
    #[DataProvider('emptyCurrentPasswords')]
    public function profile_update_with_empty_current_password_does_not_clear_the_counter(?string $empty): void
    {
        $user = $this->makeUser();
        $hashBefore = $this->storedHash($user);
        Sanctum::actingAs($user);

        $this->failCurrentPassword(4);

        $this->assertLessThan(500, $this->putJson('/api/member-profile', ['current_password' => $empty])->status());

        // Se o pedido vazio contar como falha, este já vem bloqueado; o que não pode é o contador ter sido limpo.
        $this->assertContains(
            $this->putJson(self::URL, $this->validPayload(['current_password' => 'Wrong9Secret']))->status(),
            [422, 429]
        );

        $this->putJson(self::URL, $this->validPayload())->assertTooManyRequests();
        $this->assertSame($hashBefore, $this->storedHash($user));
    }

    #[Test]
    public function array_current_password_is_rejected_with_422(): void
    {
        $user = $this->makeUser();
        $hashBefore = $this->storedHash($user);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, $this->validPayload(['current_password' => [self::OLD]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->assertSame($hashBefore, $this->storedHash($user));
    }

    #[Test]
    public function array_current_password_does_not_count_as_failure(): void
    {
        Sanctum::actingAs($this->makeUser());

        for ($i = 0; $i < 5; $i++) {
            $this->putJson(self::URL, $this->validPayload(['current_password' => ["Wrong{$i}Secret"]]))
                ->assertUnprocessable();
        }

        $this->putJson(self::URL, $this->validPayload())->assertOk();
    }

    // Notificação de password alterada (decisão 27)

    #[Test]
    public function mail_failure_still_returns_200_with_password_changed_and_tokens_revoked(): void
    {
        Exceptions::fake();
        Event::listen(NotificationSending::class, fn () => throw new RuntimeException('SMTP indisponível'));

        $user = $this->makeUser();
        $token = $user->createToken('web')->plainTextToken;
        $user->createToken('mobile');

        $this->withToken($token)
            ->putJson(self::URL, $this->validPayload())
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Password alterada com sucesso. Inicie sessão novamente.',
                'data' => null,
            ]);

        $this->assertTrue(Hash::check(self::NEW, $this->storedHash($user)));
        $this->assertSame(0, $user->tokens()->count());
        Exceptions::assertReported(RuntimeException::class);
    }

    #[Test]
    public function notification_is_sent_once_by_mail_to_the_user_on_success(): void
    {
        Notification::fake();
        $user = $this->makeUser();
        $other = $this->makeUser();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, $this->validPayload())->assertOk();

        Notification::assertSentToTimes($user, PasswordChangedNotification::class, 1);
        Notification::assertSentTo($user, PasswordChangedNotification::class, fn ($notification, array $channels) => $channels === ['mail']);
        Notification::assertNotSentTo($other, PasswordChangedNotification::class);
    }

    #[Test]
    public function notification_is_not_sent_on_validation_error(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->makeUser());

        $this->putJson(self::URL, $this->validPayload(['current_password' => 'WrongSecret000']))->assertUnprocessable();
        $this->putJson(self::URL, $this->validPayload(['password_confirmation' => 'Mismatch789']))->assertUnprocessable();

        Notification::assertNothingSent();
    }

    #[Test]
    public function notification_is_not_sent_when_blocked(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->makeUser());

        $this->failCurrentPassword(5);
        $this->putJson(self::URL, $this->validPayload())->assertTooManyRequests();

        Notification::assertNothingSent();
    }

    #[Test]
    public function patch_is_not_allowed(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->patchJson(self::URL, $this->validPayload())->assertMethodNotAllowed();
    }

    // Reposição de password pendente

    private function createPasswordResetToken(User $user): string
    {
        return Password::broker()->createToken($user);
    }

    #[Test]
    public function pending_password_reset_tokens_are_deleted_after_password_change(): void
    {
        $user = $this->makeUser();
        $resetToken = $this->createPasswordResetToken($user);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, $this->validPayload())->assertOk();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertFalse(Password::broker()->tokenExists($user->fresh(), $resetToken));
    }

    #[Test]
    public function other_users_password_reset_tokens_are_kept_after_password_change(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();
        $this->createPasswordResetToken($user);
        $otherResetToken = $this->createPasswordResetToken($other);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, $this->validPayload())->assertOk();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $other->email]);
        $this->assertTrue(Password::broker()->tokenExists($other, $otherResetToken));
    }

    #[Test]
    public function password_reset_tokens_are_kept_when_current_password_is_wrong(): void
    {
        $user = $this->makeUser();
        $resetToken = $this->createPasswordResetToken($user);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, $this->validPayload(['current_password' => 'WrongSecret000']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $this->assertTrue(Password::broker()->tokenExists($user, $resetToken));
    }
}
