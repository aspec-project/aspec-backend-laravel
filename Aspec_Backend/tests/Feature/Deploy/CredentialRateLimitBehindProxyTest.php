<?php

namespace Tests\Feature\Deploy;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Força bruta com X-Forwarded-For forjado. Com TRUSTED_PROXIES=* (Render), quem chama a API diretamente
 * escolhe o "IP" em cada pedido e o limite de 5/min por email+IP deixa de travar ataques a uma conta.
 * Login, token e recuperação de password têm por isso também 20 tentativas por 15 minutos por email,
 * que não dependem do IP.
 *
 * Cada pedido usa um IP diferente, para o limite por email+IP nunca ser o que dá 429.
 */
class CredentialRateLimitBehindProxyTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_ORIGIN = 'http://localhost:5173';

    private const PER_EMAIL = 20;

    private int $nextIp = 1;

    protected function setUp(): void
    {
        parent::setUp();

        config(['trustedproxy.proxies' => '*']);
    }

    /**
     * Um IP de cliente nunca usado antes neste teste.
     */
    private function freshIp(): string
    {
        $n = $this->nextIp++;

        return '10.'.intdiv($n, 256).'.'.($n % 256).'.1';
    }

    private function wrongLogin(string $email): TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password-errada'], [
            'Origin' => self::FRONTEND_ORIGIN,
            'X-Forwarded-For' => $this->freshIp(),
        ]);
    }

    private function wrongToken(string $email): TestResponse
    {
        return $this->postJson('/api/auth/token', ['email' => $email, 'password' => 'password-errada'], [
            'X-Forwarded-For' => $this->freshIp(),
        ]);
    }

    private function forgotPassword(string $email): TestResponse
    {
        return $this->postJson('/api/auth/forgot-password', ['email' => $email], [
            'X-Forwarded-For' => $this->freshIp(),
        ]);
    }

    #[Test]
    public function login_to_one_account_is_limited_across_forged_ips(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= self::PER_EMAIL; $i++) {
            $this->wrongLogin($user->email)->assertUnauthorized();
        }

        $this->wrongLogin($user->email)->assertTooManyRequests();
    }

    #[Test]
    public function login_limit_per_account_does_not_block_other_accounts(): void
    {
        $victim = User::factory()->create();
        $other = User::factory()->create();

        for ($i = 0; $i <= self::PER_EMAIL; $i++) {
            $this->wrongLogin($victim->email);
        }

        $this->wrongLogin($other->email)->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $other->email, 'password' => 'password'], [
            'Origin' => self::FRONTEND_ORIGIN,
            'X-Forwarded-For' => $this->freshIp(),
        ])->assertOk();
    }

    #[Test]
    public function correct_password_is_also_refused_while_the_account_is_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= self::PER_EMAIL; $i++) {
            $this->wrongLogin($user->email)->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'], [
            'Origin' => self::FRONTEND_ORIGIN,
            'X-Forwarded-For' => $this->freshIp(),
        ])->assertTooManyRequests();

        $this->assertGuest('web');
    }

    #[Test]
    public function login_limit_per_account_lasts_fifteen_minutes(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= self::PER_EMAIL; $i++) {
            $this->wrongLogin($user->email)->assertUnauthorized();
        }

        $this->travel(2)->minutes();
        $this->wrongLogin($user->email)->assertTooManyRequests();

        $this->travel(14)->minutes();
        $this->wrongLogin($user->email)->assertUnauthorized();
    }

    #[Test]
    public function login_without_email_is_not_limited_per_account(): void
    {
        for ($i = 0; $i <= self::PER_EMAIL; $i++) {
            $this->postJson('/api/auth/login', ['password' => 'password-errada'], [
                'Origin' => self::FRONTEND_ORIGIN,
                'X-Forwarded-For' => $this->freshIp(),
            ])->assertUnprocessable();
        }
    }

    #[Test]
    public function token_requests_to_one_account_are_limited_across_forged_ips(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        for ($i = 1; $i <= self::PER_EMAIL; $i++) {
            $this->wrongToken($user->email)->assertUnauthorized();
        }

        $this->wrongToken($user->email)->assertTooManyRequests();
        $this->wrongToken($other->email)->assertUnauthorized();
    }

    #[Test]
    public function forgot_password_for_one_account_is_limited_across_forged_ips(): void
    {
        Queue::fake();
        Notification::fake();

        $user = User::factory()->create();
        $other = User::factory()->create();

        for ($i = 1; $i <= self::PER_EMAIL; $i++) {
            $this->forgotPassword($user->email)->assertOk();
        }

        $this->forgotPassword($user->email)->assertTooManyRequests();
        $this->forgotPassword($other->email)->assertOk();

        Notification::assertNothingSent();
    }
}
