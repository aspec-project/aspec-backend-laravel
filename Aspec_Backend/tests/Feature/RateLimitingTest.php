<?php

namespace Tests\Feature;

use App\Models\AccountStatus;
use App\Models\EventInstance;
use App\Models\Location;
use App\Models\MemberProfile;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FillsRateLimits;
use Tests\TestCase;

/**
 * Limites de pedidos em pedidos reais, com os contadores pré-enchidos para não fazer centenas de pedidos.
 *
 * Cada ação do membro tem o seu próprio limite (120/min e 2000/hora, por utilizador): com
 * throttle:10,1 sem nome, o Laravel usa só o utilizador como chave, por isso todas as rotas
 * partilhavam o mesmo contador e guardar a montra (PUT + logótipo + imagens) dava 429 a meio.
 * Por cima, um limite geral da API (300/min por utilizador ou IP) cobre as rotas sem limitador próprio.
 */
class RateLimitingTest extends TestCase
{
    use FillsRateLimits;
    use RefreshDatabase;

    private const MSG_429 = 'Demasiados pedidos. Tente novamente mais tarde.';

    private const DEFAULT_IP = '127.0.0.1';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function activeMember(): User
    {
        $user = User::factory()->create([
            'role_id' => Role::where('name', 'Member')->value('id'),
            'account_status_id' => AccountStatus::where('name', 'Active')->value('id'),
        ]);
        MemberProfile::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    /**
     * Pedido com um token Bearer real: o Sanctum::actingAs autentica em todos os guards e esconderia
     * um limitador que lesse o guard por omissão (web), que ignora o Bearer nas rotas públicas.
     */
    private function getWithToken(string $uri, string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson($uri);
    }

    /**
     * Pedido sem credenciais, depois de esquecer o utilizador memorizado pelo guard num pedido anterior.
     */
    private function getAsVisitor(string $uri): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->getJson($uri);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('rate-limit-test')->plainTextToken;
    }

    #[Test]
    public function saving_the_full_showcase_in_one_minute_is_not_throttled(): void
    {
        $user = $this->activeMember();
        Sanctum::actingAs($user);

        // O mesmo que o "Guardar alterações" do frontend: PUT, logótipo e 10 imagens seguidas.
        $this->putJson('/api/member-profile', ['business_name' => 'Nova Empresa'])->assertOk();
        $this->postJson('/api/member-profile/logo', ['logo' => UploadedFile::fake()->image('logo.png')])->assertOk();

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/member-portfolio', ['image' => UploadedFile::fake()->image("foto{$i}.jpg")])
                ->assertCreated();
        }

        $this->assertDatabaseCount('portfolios', 10);
    }

    #[Test]
    public function exhausting_one_limit_does_not_block_the_other_routes(): void
    {
        $user = $this->activeMember();
        Sanctum::actingAs($user);
        $this->fillRateLimit('portfolio-upload', 'minute:'.$user->id, 120);

        $this->postJson('/api/member-portfolio', [])->assertTooManyRequests();

        $this->putJson('/api/member-profile', ['business_name' => 'Nova Empresa'])->assertOk();
        $this->postJson('/api/member-profile/logo', [])->assertUnprocessable();
        $this->deleteJson('/api/member-portfolio/'.Str::uuid())->assertNotFound();
        $this->putJson('/api/account/password', [])->assertUnprocessable();
    }

    #[Test]
    public function limits_are_per_user(): void
    {
        $user = $this->activeMember();
        Sanctum::actingAs($user);
        $this->fillRateLimit('portfolio-upload', 'minute:'.$user->id, 120);

        $this->postJson('/api/member-portfolio', [])->assertTooManyRequests();

        Sanctum::actingAs($this->activeMember());

        $this->postJson('/api/member-portfolio', [])->assertUnprocessable();
    }

    #[Test]
    public function notification_preferences_update_is_limited_per_minute_per_user(): void
    {
        $user = $this->activeMember();
        Sanctum::actingAs($user);
        $this->fillRateLimit('notification-preferences', 'minute:'.$user->id, 119);
        $payload = ['notify_new_events' => false, 'notify_weekly_agenda' => true];

        $this->putJson('/api/account/notification-preferences', $payload)->assertOk();
        $this->putJson('/api/account/notification-preferences', $payload)->assertTooManyRequests();

        $this->putJson('/api/account/password', [])->assertUnprocessable();

        Sanctum::actingAs($this->activeMember());

        $this->putJson('/api/account/notification-preferences', $payload)->assertOk();
    }

    // B1 — o limite por hora não acaba ao fim de um minuto

    #[Test]
    public function hourly_limit_blocks_beyond_the_minute_window(): void
    {
        $user = $this->activeMember();
        Sanctum::actingAs($user);
        $this->fillRateLimit('profile-update', 'hour:'.$user->id, 2000, 3600);

        $response = $this->putJson('/api/member-profile', ['business_name' => 'Nova Empresa'])
            ->assertTooManyRequests()
            ->assertExactJson(['success' => false, 'message' => self::MSG_429])
            ->assertHeader('Retry-After');
        $this->assertGreaterThan(60, (int) $response->headers->get('Retry-After'));

        $this->travel(2)->minutes();

        $this->putJson('/api/member-profile', ['business_name' => 'Nova Empresa'])->assertTooManyRequests();
        $this->assertDatabaseMissing('member_profiles', ['user_id' => $user->id, 'business_name' => 'Nova Empresa']);
    }

    // B2 — o limite por minuto liberta ao fim do minuto

    #[Test]
    public function minute_limit_is_released_after_the_minute(): void
    {
        $user = $this->activeMember();
        Sanctum::actingAs($user);
        $this->fillRateLimit('profile-update', 'minute:'.$user->id, 120);

        $this->putJson('/api/member-profile', ['business_name' => 'Nova Empresa'])->assertTooManyRequests();

        $this->travel(61)->seconds();

        $this->putJson('/api/member-profile', ['business_name' => 'Nova Empresa'])->assertOk();
    }

    #[Test]
    public function registration_is_rate_limited_after_twenty_attempts(): void
    {
        $this->fillRateLimit('auth-register', self::DEFAULT_IP, 19);

        $payload = [
            'name' => 'Membro de Teste',
            'password' => 'Password123!',
            'phone' => '912345678',
            'business_name' => 'Empresa de Teste',
            'sector_id' => Sector::firstOrFail()->id,
            'location_id' => Location::firstOrFail()->id,
            'congregation' => 'Congregação de Teste',
            'role_in_congregation' => 'Membro',
            'description' => 'Descrição do perfil de teste.',
            'website_url' => 'https://example.com',
            'address' => 'Rua de Teste, 1',
            'postal_code' => '1000-100',
            'city' => 'Lisboa',
            'commercial_email' => 'geral@empresa-teste.pt',
            'commercial_phone' => '213 456 789',
            'terms_accepted' => true,
        ];

        $this->postJson('/api/auth/register', [...$payload, 'email' => 'member20@example.com'])
            ->assertCreated();

        $this->postJson('/api/auth/register', [...$payload, 'email' => 'member21@example.com'])
            ->assertTooManyRequests();

        $this->assertDatabaseMissing('users', ['email' => 'member21@example.com']);
    }

    #[Test]
    public function login_is_rate_limited_after_five_attempts(): void
    {
        $user = User::factory()->create();

        $payload = [
            'email' => $user->email,
            'password' => 'password-errada',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this
                ->withHeader('Origin', 'http://localhost:5173')
                ->postJson('/api/auth/login', $payload)
                ->assertUnauthorized();
        }

        $this
            ->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/auth/login', $payload)
            ->assertTooManyRequests();
    }

    // The throttle middleware runs before the controller, so requests rejected for lacking a session still count.
    #[Test]
    public function login_without_session_counts_towards_throttle(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this
                ->postJson('/api/auth/login', [
                    'email' => $user->email,
                    'password' => 'password-errada',
                ])
                ->assertBadRequest();
        }

        $this
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password-errada',
            ])
            ->assertTooManyRequests();
    }

    #[Test]
    public function token_login_is_rate_limited_after_five_attempts(): void
    {
        $user = User::factory()->create();

        $payload = [
            'email' => $user->email,
            'password' => 'password-errada',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this
                ->postJson('/api/auth/token', $payload)
                ->assertUnauthorized();
        }

        $this
            ->postJson('/api/auth/token', $payload)
            ->assertTooManyRequests();
    }

    #[Test]
    public function member_directory_is_limited_to_a_hundred_and_twenty_requests_per_minute_per_ip(): void
    {
        $this->fillRateLimit('member-directory', self::DEFAULT_IP, 119);

        $this->getJson('/api/members')->assertOk();
        $this->getJson('/api/members')->assertTooManyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->getJson('/api/members')
            ->assertOk();
    }

    #[Test]
    public function member_directory_list_and_profile_share_the_same_counter(): void
    {
        $profile = $this->activeMember()->memberProfile;
        $this->fillRateLimit('member-directory', self::DEFAULT_IP, 119);

        $this->getJson('/api/members')->assertOk();
        $this->getJson("/api/members/{$profile->id}")->assertTooManyRequests();
        $this->getJson('/api/sectors')->assertOk();
    }

    // B6 — membro autenticado no diretório não usa o contador do IP

    #[Test]
    public function authenticated_member_is_not_blocked_by_an_exhausted_directory_ip(): void
    {
        $member = $this->activeMember();
        $this->fillRateLimit('member-directory', self::DEFAULT_IP, 120);

        $this->getAsVisitor('/api/members')->assertTooManyRequests();

        $this->getWithToken('/api/members', $this->tokenFor($member))->assertOk();
        $this->getWithToken("/api/members/{$member->memberProfile->id}", $this->tokenFor($member))->assertOk();
    }

    // B7 — um token inválido não troca o IP por outra chave

    #[Test]
    public function invalid_bearer_token_falls_back_to_the_ip_counter(): void
    {
        $member = $this->activeMember();
        $this->fillRateLimit('member-directory', self::DEFAULT_IP, 120);

        $this->getWithToken('/api/members', $this->tokenFor($member))->assertOk();

        $this->getWithToken('/api/members', '1|invalido')->assertTooManyRequests();
    }

    // B8 — dois membros na mesma rede têm contadores separados

    #[Test]
    public function members_on_the_same_ip_have_separate_directory_counters(): void
    {
        $first = $this->activeMember();
        $second = $this->activeMember();
        $this->fillRateLimit('member-directory', (string) $first->id, 120);

        $this->getWithToken('/api/members', $this->tokenFor($first))->assertTooManyRequests();
        $this->getWithToken('/api/members', $this->tokenFor($second))->assertOk();
        $this->getAsVisitor('/api/members')->assertOk();
    }

    // B9 — agenda pública: membro autenticado não usa o contador do IP

    #[Test]
    public function authenticated_member_is_not_blocked_by_an_exhausted_events_ip(): void
    {
        $member = $this->activeMember();
        $event = EventInstance::factory()->create();
        $this->fillRateLimit('public-events', self::DEFAULT_IP, 120);

        $this->getAsVisitor('/api/events')->assertTooManyRequests();

        $this->getWithToken('/api/events', $this->tokenFor($member))->assertOk();
        $this->getWithToken("/api/events/{$event->id}", $this->tokenFor($member))->assertOk();
    }
}
