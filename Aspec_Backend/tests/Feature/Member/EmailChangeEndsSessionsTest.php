<?php

namespace Tests\Feature\Member;

use App\Models\BusinessHour;
use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Mudar o email tem de terminar as outras sessões SPA do utilizador: o AuthenticateSession
 * só compara o hash da password, por isso uma sessão aberta noutro browser sobreviveria à mudança.
 */
class EmailChangeEndsSessionsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/member-profile';

    private const NEW_EMAIL = 'novo.email@example.com';

    private const FRONTEND_HEADERS = [
        'Origin' => 'http://localhost:5173',
        'Referer' => 'http://localhost:5173/',
        'Accept' => 'application/json',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    private function member(): User
    {
        $user = User::factory()->create();
        MemberProfile::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    /**
     * Limpa o que o cliente de teste e a aplicação guardam entre pedidos, para cada pedido
     * se comportar como um browser independente. O session.store e o auth.driver são singletons
     * à parte: se ficassem, o guard usaria a sessão do pedido anterior e qualquer cookie autenticava.
     * O driver array guarda as sessões no próprio objeto do driver: esquecê-lo perderia o login.
     */
    private function startNewRequest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        config(['auth.defaults.guard' => 'web']);

        if (config('session.driver') === 'database') {
            $this->app['session']->forgetDrivers();
            $this->app->forgetInstance('session.store');
        }

        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }

    private function login(User $user): string
    {
        $this->startNewRequest();

        $response = $this->withCredentials()
            ->withHeaders(self::FRONTEND_HEADERS)
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        return $response->getCookie(config('session.cookie'))->getValue();
    }

    private function asBrowser(string $sessionId): static
    {
        $this->startNewRequest();

        return $this->withCredentials()
            ->withHeaders(self::FRONTEND_HEADERS)
            ->withCookie(config('session.cookie'), $sessionId);
    }

    private function asBearer(string $token, array $headers = []): static
    {
        $this->startNewRequest();

        return $this->withHeaders($headers)->withToken($token);
    }

    private function emailChangePayload(array $extra = []): array
    {
        return ['email' => self::NEW_EMAIL, 'current_password' => 'password', ...$extra];
    }

    private function assertSessionBelongsTo(string $sessionId, User $user): void
    {
        $this->assertDatabaseHas('sessions', ['id' => $sessionId, 'user_id' => $user->id]);
    }

    private function insertSession(?string $userId): string
    {
        $id = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => now()->getTimestamp(),
        ]);

        return $id;
    }

    #[Test]
    public function email_change_ends_other_sessions_and_keeps_current(): void
    {
        $user = $this->member();
        $sessionA = $this->login($user);
        $sessionB = $this->login($user);

        $this->asBrowser($sessionA)->putJson(self::URL, $this->emailChangePayload())->assertOk();

        // Antes de B fazer qualquer pedido: depois do 401 a linha volta com o mesmo id e user_id null.
        $this->assertDatabaseMissing('sessions', ['id' => $sessionB]);
        $this->assertSessionBelongsTo($sessionA, $user);

        $this->asBrowser($sessionB)->getJson(self::URL)->assertUnauthorized();
        $this->assertDatabaseMissing('sessions', ['id' => $sessionB, 'user_id' => $user->id]);

        $this->asBrowser($sessionA)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.email', self::NEW_EMAIL);
    }

    #[Test]
    public function bearer_email_change_removes_all_sessions(): void
    {
        $user = $this->member();
        $this->login($user);
        $this->login($user);
        $token = $user->createToken('postman');

        $this->asBearer($token->plainTextToken)->putJson(self::URL, $this->emailChangePayload())->assertOk();

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->getKey()]);
    }

    #[Test]
    public function bearer_with_frontend_origin_removes_all_sessions(): void
    {
        $user = $this->member();
        $sessionA = $this->login($user);
        $sessionB = $this->login($user);
        $token = $user->createToken('postman');

        $this->asBearer($token->plainTextToken, self::FRONTEND_HEADERS)
            ->putJson(self::URL, $this->emailChangePayload())
            ->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => $sessionA]);
        $this->assertDatabaseMissing('sessions', ['id' => $sessionB]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->getKey()]);
    }

    #[Test]
    public function update_without_email_change_keeps_sessions(): void
    {
        $user = $this->member();
        $sessionA = $this->login($user);
        $sessionB = $this->login($user);

        $this->asBrowser($sessionA)
            ->putJson(self::URL, ['name' => 'Nome Novo', 'commercial_email' => 'comercial@example.com'])
            ->assertOk();

        $this->assertSessionBelongsTo($sessionA, $user);
        $this->assertSessionBelongsTo($sessionB, $user);
        $this->asBrowser($sessionB)->getJson(self::URL)->assertOk();
    }

    #[Test]
    public function same_email_keeps_sessions(): void
    {
        $user = $this->member();
        $sessionA = $this->login($user);
        $sessionB = $this->login($user);

        $this->asBrowser($sessionA)
            ->putJson(self::URL, ['email' => $user->email, 'current_password' => 'password'])
            ->assertOk();

        $this->assertSessionBelongsTo($sessionA, $user);
        $this->assertSessionBelongsTo($sessionB, $user);
        $this->asBrowser($sessionB)->getJson(self::URL)->assertOk();
    }

    #[Test]
    public function wrong_current_password_keeps_sessions(): void
    {
        $user = $this->member();
        $sessionA = $this->login($user);
        $sessionB = $this->login($user);

        $this->asBrowser($sessionA)
            ->putJson(self::URL, ['email' => self::NEW_EMAIL, 'current_password' => 'errada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        $this->assertSessionBelongsTo($sessionA, $user);
        $this->assertSessionBelongsTo($sessionB, $user);
    }

    #[Test]
    public function sessions_are_kept_when_the_transaction_fails(): void
    {
        $user = $this->member();
        $sessionA = $this->login($user);
        $sessionB = $this->login($user);
        BusinessHour::creating(fn () => throw new RuntimeException('Falha simulada.'));

        $this->asBrowser($sessionA)
            ->putJson(self::URL, $this->emailChangePayload([
                'business_hours' => [['week_day_id' => 1, 'open_time' => '09:00', 'close_time' => '18:00']],
            ]))
            ->assertInternalServerError();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        $this->assertSessionBelongsTo($sessionA, $user);
        $this->assertSessionBelongsTo($sessionB, $user);
    }

    #[Test]
    public function other_users_and_guest_sessions_are_kept(): void
    {
        $user = $this->member();
        $other = $this->member();
        $sessionA = $this->login($user);
        $otherSession = $this->insertSession($other->id);
        $guestSession = $this->insertSession(null);

        $this->asBrowser($sessionA)->putJson(self::URL, $this->emailChangePayload())->assertOk();

        $this->assertSessionBelongsTo($sessionA, $user);
        $this->assertSessionBelongsTo($otherSession, $other);
        $this->assertDatabaseHas('sessions', ['id' => $guestSession, 'user_id' => null]);
    }

    #[Test]
    public function array_driver_does_not_break_email_change(): void
    {
        config(['session.driver' => 'array']);
        $user = $this->member();
        $session = $this->login($user);

        $this->asBrowser($session)
            ->putJson(self::URL, $this->emailChangePayload())
            ->assertOk()
            ->assertJsonPath('data.email', self::NEW_EMAIL);
    }
}
