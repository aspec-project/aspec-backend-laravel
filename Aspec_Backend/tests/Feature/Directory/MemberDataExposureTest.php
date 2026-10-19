<?php

namespace Tests\Feature\Directory;

use App\Models\MemberProfile;
use App\Models\SocialPlatform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsDirectoryMembers;
use Tests\TestCase;

/**
 * O que o diretório nunca pode expor.
 *
 * Telefone e contactos comerciais só para contas Active; o email de login nunca (é a credencial
 * de acesso); congregação e cargo nunca (revelam convicção religiosa, categoria especial do RGPD);
 * dados internos, de faturação e do Stripe nunca.
 */
class MemberDataExposureTest extends TestCase
{
    use BuildsDirectoryMembers, RefreshDatabase;

    private const PHONE = '961234567';

    private const EMAIL = 'dono.do.perfil@empresa.test';

    private const COMMERCIAL_CONTACTS = 'Contacto comercial ZXQWCOMERCIAL';

    private const CONGREGATION = 'Congregacao ZXQWIGREJA';

    private const ROLE_IN_CONGREGATION = 'Cargo ZXQWCARGO';

    private const NIF = '987654321';

    /** Chaves que não podem aparecer em lado nenhum do JSON do diretório. */
    private const FORBIDDEN_KEYS = [
        'email',
        'congregation',
        'role_in_congregation',
        'user_id',
        'user',
        'logo_path',
        'image_path',
        'account_status',
        'account_status_id',
        'role',
        'role_id',
        'trial_ends_at',
        'grace_ends_at',
        'inactive_reason',
        'nif',
        'billing_name',
        'billing_address',
        'billing_postal_code',
        'billing_city',
        'stripe_id',
        'stripe_checkout_session_id',
        'pm_type',
        'pm_last_four',
        'password',
        'remember_token',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function exposedOwner(): MemberProfile
    {
        $owner = User::factory()->withBilling()->create([
            'email' => self::EMAIL,
            'phone' => self::PHONE,
            'nif' => self::NIF,
            'stripe_id' => 'cus_ZXQWSTRIPE',
            'pm_type' => 'visa',
            'pm_last_four' => '4242',
            'trial_ends_at' => now()->addDays(10),
        ]);

        $profile = $this->profileFor($owner, [
            'business_name' => 'Empresa Exposta',
            'commercial_contacts' => self::COMMERCIAL_CONTACTS,
            'congregation' => self::CONGREGATION,
            'role_in_congregation' => self::ROLE_IN_CONGREGATION,
            'logo_path' => "logos/{$owner->id}/logo.png",
            'website_url' => 'https://empresa.example',
            'address' => 'Avenida Central 10, Porto',
        ]);
        $profile->portfolios()->create(['image_path' => "portfolios/{$owner->id}/foto.jpg"]);
        $profile->socialPlatforms()->attach(
            SocialPlatform::where('name', 'Instagram')->value('id'),
            ['url' => 'https://instagram.com/empresa'],
        );

        return $profile;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function viewers(): array
    {
        return [
            'visitante' => ['visitor'],
            'membro Active' => ['active'],
        ];
    }

    private function asViewer(string $viewer): static
    {
        if ($viewer === 'active') {
            $this->withToken($this->listedMember(['business_name' => 'Quem Consulta'])->user->createToken('postman')->plainTextToken);
        }

        return $this;
    }

    private function assertNoForbiddenKeys(TestResponse $response): void
    {
        $leaked = array_intersect(self::FORBIDDEN_KEYS, $this->allJsonKeys($response->json()));

        $this->assertSame([], array_values($leaked), 'Chaves proibidas no JSON: '.implode(', ', $leaked));
    }

    private function assertNoSecretValues(TestResponse $response): void
    {
        $response->assertDontSee(self::EMAIL)
            ->assertDontSee(self::CONGREGATION)
            ->assertDontSee(self::ROLE_IN_CONGREGATION)
            ->assertDontSee(self::NIF)
            ->assertDontSee('cus_ZXQWSTRIPE');
    }

    #[Test]
    #[DataProvider('viewers')]
    public function list_items_only_have_the_contract_keys(string $viewer): void
    {
        $this->exposedOwner();

        $response = $this->asViewer($viewer)->listMembers()->assertOk();

        foreach ($response->json('data.items') as $item) {
            $this->assertEqualsCanonicalizing(self::LIST_ITEM_KEYS, array_keys($item));
        }
        $this->assertNoForbiddenKeys($response);
        foreach (['phone', 'commercial_contacts', 'members_only'] as $key) {
            $this->assertNotContains($key, $this->allJsonKeys($response->json()));
        }
    }

    #[Test]
    #[DataProvider('viewers')]
    public function list_json_contains_no_contact_or_congregation_values(string $viewer): void
    {
        $this->exposedOwner();

        $response = $this->asViewer($viewer)->listMembers()->assertOk()->assertJsonPath('data.pagination.total', $viewer === 'active' ? 2 : 1);

        $this->assertNoSecretValues($response);
        $response->assertDontSee(self::PHONE)->assertDontSee(self::COMMERCIAL_CONTACTS);
    }

    #[Test]
    public function visitor_profile_hides_phone_and_commercial_contacts_but_shows_public_showcase(): void
    {
        $profile = $this->exposedOwner();

        $this->showMember($profile->id)
            ->assertOk()
            ->assertJsonPath('data.members_only', null)
            ->assertDontSee(self::PHONE)
            ->assertDontSee(self::COMMERCIAL_CONTACTS)
            ->assertJsonPath('data.address', 'Avenida Central 10, Porto')
            ->assertJsonPath('data.website_url', 'https://empresa.example')
            ->assertJsonPath('data.social_links.0.url', 'https://instagram.com/empresa');
    }

    #[Test]
    #[DataProvider('viewers')]
    public function profile_never_contains_login_email_or_congregation(string $viewer): void
    {
        $profile = $this->exposedOwner();

        $response = $this->asViewer($viewer)->showMember($profile->id)->assertOk();

        $this->assertNoSecretValues($response);
        foreach (['email', 'congregation', 'role_in_congregation'] as $key) {
            $this->assertNotContains($key, $this->allJsonKeys($response->json()));
        }
    }

    #[Test]
    #[DataProvider('viewers')]
    public function profile_never_contains_internal_billing_or_stripe_fields(string $viewer): void
    {
        $profile = $this->exposedOwner();

        $response = $this->asViewer($viewer)->showMember($profile->id)->assertOk();

        $this->assertNoForbiddenKeys($response);
    }

    #[Test]
    public function members_only_phone_is_the_profile_owner_phone_not_the_viewer_phone(): void
    {
        $profile = $this->exposedOwner();
        $viewer = User::factory()->create(['phone' => '931111111']);
        $this->profileFor($viewer, ['business_name' => 'Quem Consulta']);

        $this->withToken($viewer->createToken('postman')->plainTextToken)
            ->showMember($profile->id)
            ->assertOk()
            ->assertJsonPath('data.members_only.phone', self::PHONE)
            ->assertJsonPath('data.members_only.commercial_contacts', self::COMMERCIAL_CONTACTS)
            ->assertDontSee('931111111');
    }

    #[Test]
    public function active_member_cannot_open_an_inactive_profile(): void
    {
        $inactive = $this->profileFor(User::factory()->inactive());
        $viewer = $this->listedMember()->user;

        $this->withToken($viewer->createToken('postman')->plainTextToken)
            ->showMember($inactive->id)
            ->assertNotFound()
            ->assertExactJson(self::NOT_FOUND_BODY);
    }

    #[Test]
    public function anonymized_member_never_appears_in_list_or_search(): void
    {
        $profile = $this->listedMember(['name' => 'Nome Antigo', 'business_name' => 'Negocio Antigo']);
        $this->listedMember(['business_name' => 'Outra Empresa']);
        $profile->user->anonymizeAndDelete();

        $this->listMembers()
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertDontSee('Nome Antigo')
            ->assertDontSee('Negocio Antigo')
            ->assertDontSee('Utilizador An')
            ->assertDontSee('Empresa Removida');

        foreach (['Antigo', 'Utilizador', 'Removida'] as $term) {
            $this->listMembers(['search' => $term])
                ->assertOk()
                ->assertJsonPath('data.items', [])
                ->assertJsonPath('data.pagination.total', 0);
        }
    }

    #[Test]
    #[DataProvider('viewers')]
    public function profile_response_is_never_cached_by_shared_proxies(string $viewer): void
    {
        $profile = $this->exposedOwner();

        $response = $this->asViewer($viewer)->showMember($profile->id)->assertOk();

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }
}
