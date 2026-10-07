<?php

namespace Tests\Feature;

use App\Models\AccountStatus;
use App\Models\MemberProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cada ação tem o seu próprio limite de 10 pedidos por minuto (ASPEC-78).
 *
 * Com throttle:10,1 sem nome, o Laravel usa só o utilizador como chave,
 * por isso todas as rotas partilhavam o mesmo contador e guardar a montra
 * (PUT + logótipo + imagens) dava 429 a meio.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

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

        // Esgota o limite dos uploads do portfólio (pedidos inválidos, para não criar imagens).
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/member-portfolio', [])->assertUnprocessable();
        }
        $this->postJson('/api/member-portfolio', [])->assertTooManyRequests();

        // As outras rotas continuam com o seu próprio contador.
        $this->putJson('/api/member-profile', ['business_name' => 'Nova Empresa'])->assertOk();
        $this->postJson('/api/member-profile/logo', [])->assertUnprocessable();
        $this->deleteJson('/api/member-portfolio/'.Str::uuid())->assertNotFound();
        $this->putJson('/api/account/password', [])->assertUnprocessable();
    }

    #[Test]
    public function limits_are_per_user(): void
    {
        Sanctum::actingAs($this->activeMember());

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/member-portfolio', [])->assertUnprocessable();
        }
        $this->postJson('/api/member-portfolio', [])->assertTooManyRequests();

        // Outro membro não é afetado pelo limite do primeiro.
        Sanctum::actingAs($this->activeMember());

        $this->postJson('/api/member-portfolio', [])->assertUnprocessable();
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
    public function registration_is_rate_limited_after_five_attempts(): void
    {
        $sector = \App\Models\Sector::firstOrFail();
        $location = \App\Models\Location::firstOrFail();

        $basePayload = [
            'name' => 'Membro de Teste',
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
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $payload = $basePayload;
            $payload['email'] = "member{$attempt}@example.com";

            $this
                ->postJson('/api/auth/register', $payload)
                ->assertCreated();
        }

        $payload = $basePayload;
        $payload['email'] = 'member6@example.com';

        $this
            ->postJson('/api/auth/register', $payload)
            ->assertTooManyRequests();
    }
}
