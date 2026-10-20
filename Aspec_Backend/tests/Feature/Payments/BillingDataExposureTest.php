<?php

namespace Tests\Feature\Payments;

use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O NIF e a morada de faturação só aparecem ao próprio membro em GET /api/subscription.
 * Nenhum outro endpoint que devolve dados do membro (o próprio, outro membro ou um admin) os expõe.
 */
class BillingDataExposureTest extends TestCase
{
    use RefreshDatabase;

    private const NIF = '275193846';

    private const BILLING_ADDRESS = 'Rua da Faturacao Unica 987';

    private User $member;

    private MemberProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = User::factory()->withBilling()->create([
            'nif' => self::NIF,
            'billing_address' => self::BILLING_ADDRESS,
        ]);
        $this->profile = MemberProfile::factory()->create([
            'user_id' => $this->member->id,
            'business_name' => 'Empresa Exposicao Lda',
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function endpoints(): array
    {
        return [
            'o próprio em /auth/me' => ['me'],
            'o próprio em /member-profile' => ['own profile'],
            'outro membro no perfil público' => ['public profile'],
            'admin na lista de utilizadores' => ['admin list'],
            'admin no detalhe do utilizador' => ['admin detail'],
        ];
    }

    private function request(string $endpoint): TestResponse
    {
        return match ($endpoint) {
            'me' => $this->actingAsUser($this->member)->getJson('/api/auth/me'),
            'own profile' => $this->actingAsUser($this->member)->getJson('/api/member-profile'),
            'public profile' => $this->actingAsUser(User::factory()->create())->getJson('/api/members/'.$this->profile->id),
            'admin list' => $this->actingAsUser(User::factory()->admin()->create())->getJson('/api/admin/users'),
            'admin detail' => $this->actingAsUser(User::factory()->admin()->create())->getJson('/api/admin/users/'.$this->member->id),
        };
    }

    private function actingAsUser(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function nif_and_billing_address_are_never_exposed(string $endpoint): void
    {
        $response = $this->request($endpoint)->assertOk();

        $response->assertSee('Empresa Exposicao Lda', false)
            ->assertDontSee(self::NIF, false)
            ->assertDontSee(self::BILLING_ADDRESS, false);
        $this->assertStringNotContainsString('"nif"', $response->getContent());
        $this->assertStringNotContainsString('"billing_', $response->getContent());
    }
}
