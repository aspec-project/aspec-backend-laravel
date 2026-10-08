<?php

namespace Tests\Feature\Auth;

use App\Models\AccountStatus;
use App\Models\Location;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Enums\InactiveReason;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_ORIGIN = 'http://localhost:5173';

    private const NO_SESSION_MESSAGE = 'Pedido de login sem sessão. Use o frontend da plataforma ou POST /api/auth/token.';

    private function activeUser(): User
    {
        return User::factory()->create([
            'account_status_id' => AccountStatus::where('name', 'Active')->value('id'),
        ]);
    }

    private function pendingUser(): User
    {
        return User::factory()
            ->pending()
            ->create();
    }

    private function inactiveUser(): User
    {
        return User::factory()
            ->inactive()
            ->create();
    }

    private function loginPayload(User $user): array
    {
        return [
            'email' => $user->email,
            'password' => 'password',
        ];
    }

    #[Test]
    public function active_user_can_register(): void
    {
        $role = Role::where('name', 'Member')->firstOrFail();
        $sector = Sector::firstOrFail();
        $location = Location::firstOrFail();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Membro de Teste',
            'email' => 'novo.membro@example.com',
            'password' => 'Password123!',
            'phone' => '912345678',
            'business_name' => 'Empresa de Teste',
            'sector_id' => $sector->id,
            'location_id' => $location->id,
            'congregation' => 'Congregação de Teste',
            'role_in_congregation' => 'Membro',
            'description' => 'Descrição do perfil de teste.',
            'website_url' => 'https://example.com',
            'address' => 'Rua de Teste, 1',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'Candidatura submetida com sucesso. A conta aguarda aprovação do administrador.'
            );

        $this->assertDatabaseHas('users', [
            'email' => 'novo.membro@example.com',
            'role_id' => $role->id,
            'account_status_id' => AccountStatus::where('name', 'Pending')->value('id'),
        ]);

        $this->assertDatabaseHas('member_profiles', [
            'business_name' => 'Empresa de Teste',
            'congregation' => 'Congregação de Teste',
        ]);
    }

    #[Test]
    public function active_user_can_login_with_spa_session(): void
    {
        $user = $this->activeUser();

        $response = $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/auth/login', $this->loginPayload($user));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('data.token');

        $this->assertAuthenticatedAs($user, 'web');
    }

    #[Test]
    public function login_does_not_create_a_personal_access_token(): void
    {
        $user = $this->activeUser();

        $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/auth/login', $this->loginPayload($user))
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    #[Test]
    public function login_fails_with_invalid_password(): void
    {
        $user = $this->activeUser();

        $response = $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password-errada',
            ]);

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Credenciais inválidas. Verifique o seu email e password.',
            ]);

        $this->assertGuest('web');
    }

    #[Test]
    public function login_fails_with_unknown_email(): void
    {
        $response = $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/auth/login', [
                'email' => 'nao-existe@example.com',
                'password' => 'password',
            ]);

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Credenciais inválidas. Verifique o seu email e password.',
            ]);

        $this->assertGuest('web');
    }

    #[Test]
    public function login_validates_required_fields(): void
    {
        $response = $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/auth/login', []);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors([
                'email',
                'password',
            ], 'errors');

        $this->assertGuest('web');
    }

    #[Test]
    public function pending_user_cannot_login(): void
    {
        $user = $this->pendingUser();

        $response = $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/auth/login', $this->loginPayload($user));

        $response
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'A sua conta está pendente de aprovação pelo administrador.',
            ]);

        $this->assertGuest('web');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    #[Test]
    public function inactive_user_cannot_login(): void
    {
        $user = $this->inactiveUser();

        $response = $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/auth/login', $this->loginPayload($user));

        $response
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'A sua conta não está ativa.',
            ]);

        $this->assertGuest('web');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    #[Test]
    public function authenticated_user_can_read_me_with_spa_session(): void
    {
        $user = $this->activeUser();

        $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/auth/login', $this->loginPayload($user))
            ->assertOk();

        $response = $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->getJson('/api/auth/me');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    #[Test]
    public function me_requires_authentication(): void
    {
        $this
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Não autenticado.',
            ]);
    }

    #[Test]
    public function logout_invalidates_spa_session(): void
    {
        $user = $this->activeUser();

        $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/auth/login', $this->loginPayload($user))
            ->assertOk();

        $this->assertAuthenticatedAs($user, 'web');

        $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Sessão terminada com sucesso.',
                'data' => null,
            ]);

        $this->assertGuest('web');

        $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    #[Test]
    public function active_user_can_create_a_bearer_token(): void
    {
        $user = $this->activeUser();

        $response = $this->postJson('/api/auth/token', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $token = $response->json('data.token');

        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'postman',
        ]);
    }

    #[Test]
    public function token_login_fails_with_invalid_password(): void
    {
        $user = $this->activeUser();

        $response = $this->postJson('/api/auth/token', [
            'email' => $user->email,
            'password' => 'password-errada',
        ]);

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Credenciais inválidas. Verifique o seu email e password.',
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    #[Test]
    public function pending_user_cannot_create_a_bearer_token(): void
    {
        $user = $this->pendingUser();

        $this
            ->postJson('/api/auth/token', $this->loginPayload($user))
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'A sua conta está pendente de aprovação pelo administrador.',
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    #[Test]
    public function inactive_user_cannot_create_a_bearer_token(): void
    {
        $user = $this->inactiveUser();

        $this
            ->postJson('/api/auth/token', $this->loginPayload($user))
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'A sua conta não está ativa.',
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    #[Test]
    public function authenticated_user_can_read_me_with_a_bearer_token(): void
    {
        $user = $this->activeUser();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    #[Test]
    public function logout_revokes_the_current_bearer_token(): void
    {
        $user = $this->activeUser();
        $token = $user->createToken('test')->plainTextToken;

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Sessão terminada com sucesso.',
                'data' => null,
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'test',
        ]);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    #[Test]
    public function bearer_token_can_access_protected_route_without_a_session(): void
    {
        $user = $this->activeUser();
        $token = $user->createToken('test')->plainTextToken;

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk();

        $this->assertGuest('web');
    }

    #[Test]
    public function invalid_bearer_token_is_rejected(): void
    {
        $this
            ->withHeader('Authorization', 'Bearer token-invalido')
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Não autenticado.',
            ]);
    }

    #[Test]
    public function logout_without_authentication_is_rejected(): void
    {
        $this
            ->postJson('/api/auth/logout')
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Não autenticado.',
            ]);
    }


    #[Test]
    public function logout_handles_bearer_token_and_spa_session_together(): void
    {
        $user = $this->activeUser();

        $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/auth/login', $this->loginPayload($user))
            ->assertOk();

        $token = $user->createToken('combined-auth')->plainTextToken;

        $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Sessão terminada com sucesso.',
                'data' => null,
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'combined-auth',
        ]);

        $this->assertGuest('web');
    }



    #[Test]
    public function approved_user_cannot_login_before_activation(): void
    {
        $user = User::factory()
            ->approved()
            ->create();

        $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/auth/login', $this->loginPayload($user))
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'A sua conta foi aprovada. Ative-a através do link enviado por email.',
            ]);

        $this->assertGuest('web');
    }

    #[Test]
    public function unpaid_user_cannot_login(): void
    {
        $user = User::factory()
            ->inactive(InactiveReason::Unpaid)
            ->create();

        $this
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/auth/login', $this->loginPayload($user))
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'A sua conta está inativa por falta de pagamento. Use o link de reativação enviado por email.',
            ]);

        $this->assertGuest('web');
    }

    #[Test]
    public function approved_user_cannot_create_a_bearer_token_before_activation(): void
    {
        $user = User::factory()
            ->approved()
            ->create();

        $this
            ->postJson('/api/auth/token', $this->loginPayload($user))
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'A sua conta foi aprovada. Ative-a através do link enviado por email.',
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    #[Test]
    public function unpaid_user_cannot_create_a_bearer_token(): void
    {
        $user = User::factory()
            ->inactive(InactiveReason::Unpaid)
            ->create();

        $this
            ->postJson('/api/auth/token', $this->loginPayload($user))
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'A sua conta está inativa por falta de pagamento. Use o link de reativação enviado por email.',
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    #[Test]
    public function login_without_origin_returns_400_without_authenticating(): void
    {
        $user = $this->activeUser();

        $this
            ->postJson('/api/auth/login', $this->loginPayload($user))
            ->assertBadRequest()
            ->assertExactJson([
                'success' => false,
                'message' => self::NO_SESSION_MESSAGE,
            ]);

        $this->assertGuest('web');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // The session check runs before the credentials, so this path can never be used to test passwords.
    #[Test]
    public function login_with_wrong_password_without_origin_returns_400(): void
    {
        $user = $this->activeUser();

        $this
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password-errada',
            ])
            ->assertBadRequest()
            ->assertExactJson([
                'success' => false,
                'message' => self::NO_SESSION_MESSAGE,
            ]);

        $this->assertGuest('web');
    }

    #[Test]
    public function login_without_origin_and_empty_body_returns_400(): void
    {
        $this
            ->postJson('/api/auth/login', [])
            ->assertBadRequest()
            ->assertExactJson([
                'success' => false,
                'message' => self::NO_SESSION_MESSAGE,
            ]);

        $this->assertGuest('web');
    }

    #[Test]
    public function login_from_a_non_stateful_origin_returns_400(): void
    {
        $user = $this->activeUser();

        $this
            ->withHeader('Origin', 'http://evil.example')
            ->postJson('/api/auth/login', $this->loginPayload($user))
            ->assertBadRequest()
            ->assertExactJson([
                'success' => false,
                'message' => self::NO_SESSION_MESSAGE,
            ]);

        $this->assertGuest('web');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function login_with_stateful_referer_is_accepted(): void
    {
        $user = $this->activeUser();

        $this
            ->withHeader('Referer', self::FRONTEND_ORIGIN.'/login')
            ->postJson('/api/auth/login', $this->loginPayload($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user, 'web');
    }
}
