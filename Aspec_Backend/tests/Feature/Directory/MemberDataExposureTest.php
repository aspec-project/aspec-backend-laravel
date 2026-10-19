<?php

namespace Tests\Feature\Directory;

use App\Enums\InactiveReason;
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
 * Website, morada, redes sociais e contactos comerciais só para contas Active; o email e o
 * telefone da conta nunca (são contactos pessoais e o email é a credencial de acesso);
 * congregação e cargo nunca (revelam convicção religiosa, categoria especial do RGPD);
 * dados internos, de faturação e do Stripe nunca.
 *
 * Os valores marcados com ZXQW servem para o assertDontSee. Os URLs são procurados sem barras,
 * porque o JSON escapa "/" como "\/" e um assertDontSee do URL completo passaria sempre.
 */
class MemberDataExposureTest extends TestCase
{
    use BuildsDirectoryMembers, RefreshDatabase;

    private const ACCOUNT_EMAIL = 'dono.ZXQWPESSOAL@empresa.test';

    private const ACCOUNT_PHONE = '961234567';

    private const COMMERCIAL_EMAIL = 'comercial.ZXQW@empresa.test';

    private const COMMERCIAL_PHONE = '+351213456789';

    private const WEBSITE = 'https://zxqw-site.test';

    private const ADDRESS = 'Rua ZXQWMORADA 1';

    private const LINKEDIN_URL = 'https://www.linkedin.com/company/zxqw-linkedin';

    private const INSTAGRAM_URL = 'https://instagram.com/zxqw-instagram';

    private const CONGREGATION = 'Congregacao ZXQWIGREJA';

    private const ROLE_IN_CONGREGATION = 'Cargo ZXQWCARGO';

    private const NIF = '987654321';

    /** Valores só para contas Active, tal como aparecem no texto do JSON. */
    private const MEMBERS_ONLY_VALUES = [
        self::COMMERCIAL_EMAIL,
        '213456789',
        'zxqw-site',
        'ZXQWMORADA',
        'zxqw-linkedin',
        'zxqw-instagram',
    ];

    /** Valores que nunca saem do diretório, para ninguém. */
    private const NEVER_VALUES = [
        self::ACCOUNT_EMAIL,
        'ZXQWPESSOAL',
        self::ACCOUNT_PHONE,
        self::CONGREGATION,
        self::ROLE_IN_CONGREGATION,
        self::NIF,
        'cus_ZXQWSTRIPE',
    ];

    /** Chaves que não podem aparecer em lado nenhum do JSON do diretório. */
    private const FORBIDDEN_KEYS = [
        'email',
        'phone',
        'commercial_contacts',
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
            'email' => self::ACCOUNT_EMAIL,
            'phone' => self::ACCOUNT_PHONE,
            'nif' => self::NIF,
            'stripe_id' => 'cus_ZXQWSTRIPE',
            'pm_type' => 'visa',
            'pm_last_four' => '4242',
            'trial_ends_at' => now()->addDays(10),
        ]);

        $profile = $this->profileFor($owner, [
            'business_name' => 'Empresa Exposta',
            'description' => 'Servicos de consultoria.',
            'commercial_email' => self::COMMERCIAL_EMAIL,
            'commercial_phone' => self::COMMERCIAL_PHONE,
            'congregation' => self::CONGREGATION,
            'role_in_congregation' => self::ROLE_IN_CONGREGATION,
            'logo_path' => "logos/{$owner->id}/logo.png",
            'website_url' => self::WEBSITE,
            'address' => self::ADDRESS,
        ]);
        $profile->portfolios()->create(['image_path' => "portfolios/{$owner->id}/foto.jpg"]);
        $profile->socialPlatforms()->attach([
            SocialPlatform::where('name', 'LinkedIn')->value('id') => ['url' => self::LINKEDIN_URL],
            SocialPlatform::where('name', 'Instagram')->value('id') => ['url' => self::INSTAGRAM_URL],
        ]);

        return $profile;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function listViewers(): array
    {
        return [
            'visitante' => ['visitor'],
            'membro Active' => ['active'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function viewersWithoutContacts(): array
    {
        return [
            'visitante' => ['visitor'],
            'Pending' => ['pending'],
            'Approved' => ['approved'],
            'Inactive blocked' => ['blocked'],
            'Inactive unpaid' => ['unpaid'],
            'Inactive cancelled' => ['cancelled'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allViewers(): array
    {
        return [
            ...self::viewersWithoutContacts(),
            'membro Active' => ['active'],
            'o próprio' => ['owner'],
            'admin Active' => ['admin'],
        ];
    }

    private function asViewer(string $viewer, ?MemberProfile $owner = null): static
    {
        $user = match ($viewer) {
            'visitor' => null,
            'active' => $this->listedMember(['business_name' => 'Quem Consulta'])->user,
            'owner' => $owner->user,
            'admin' => User::factory()->admin()->create(),
            'pending' => User::factory()->pending()->create(),
            'approved' => User::factory()->approved()->create(),
            default => User::factory()->inactive(InactiveReason::from($viewer))->create(),
        };

        if ($user) {
            $this->withToken($user->createToken('postman')->plainTextToken);
        }

        return $this;
    }

    private function assertNoForbiddenKeys(TestResponse $response): void
    {
        $leaked = array_intersect(self::FORBIDDEN_KEYS, $this->allJsonKeys($response->json()));

        $this->assertSame([], array_values($leaked), 'Chaves proibidas no JSON: '.implode(', ', $leaked));
    }

    private function assertDontSeeAny(TestResponse $response, array $values): void
    {
        foreach ($values as $value) {
            $response->assertDontSee($value, false);
        }
    }

    #[Test]
    #[DataProvider('viewersWithoutContacts')]
    public function profile_for_visitor_or_non_active_account_contains_no_contact_value(string $viewer): void
    {
        $profile = $this->exposedOwner();

        $response = $this->asViewer($viewer)->showMember($profile->id)
            ->assertOk()
            ->assertJsonPath('data.members_only', null)
            ->assertJsonPath('data.business_name', 'Empresa Exposta');

        $this->assertDontSeeAny($response, [...self::MEMBERS_ONLY_VALUES, ...self::NEVER_VALUES]);
    }

    #[Test]
    #[DataProvider('allViewers')]
    public function profile_never_contains_account_contacts_congregation_or_internal_fields(string $viewer): void
    {
        $profile = $this->exposedOwner();

        $response = $this->asViewer($viewer, $profile)->showMember($profile->id)->assertOk();

        $this->assertDontSeeAny($response, self::NEVER_VALUES);
        $this->assertNoForbiddenKeys($response);
    }

    #[Test]
    public function members_only_has_the_profile_owner_contacts_not_the_viewer_contacts(): void
    {
        $profile = $this->exposedOwner();
        $viewer = User::factory()->create(['phone' => '931111111']);
        $this->profileFor($viewer, [
            'business_name' => 'Quem Consulta',
            'commercial_email' => 'quem.consulta@empresa.test',
            'commercial_phone' => '+351229876543',
        ]);

        $this->withToken($viewer->createToken('postman')->plainTextToken)
            ->showMember($profile->id)
            ->assertOk()
            ->assertJsonPath('data.members_only.commercial_email', self::COMMERCIAL_EMAIL)
            ->assertJsonPath('data.members_only.commercial_phone', self::COMMERCIAL_PHONE)
            ->assertJsonPath('data.members_only.website_url', self::WEBSITE)
            ->assertJsonPath('data.members_only.address', self::ADDRESS)
            ->assertDontSee('931111111')
            ->assertDontSee('quem.consulta@empresa.test')
            ->assertDontSee('229876543');
    }

    #[Test]
    #[DataProvider('listViewers')]
    public function list_items_only_have_the_contract_keys(string $viewer): void
    {
        $this->exposedOwner();

        $response = $this->asViewer($viewer)->listMembers()->assertOk();

        foreach ($response->json('data.items') as $item) {
            $this->assertEqualsCanonicalizing(self::LIST_ITEM_KEYS, array_keys($item));
        }
        $this->assertNoForbiddenKeys($response);
        foreach (['members_only', 'commercial_email', 'commercial_phone', 'website_url', 'address', 'social_links'] as $key) {
            $this->assertNotContains($key, $this->allJsonKeys($response->json()));
        }
    }

    #[Test]
    #[DataProvider('listViewers')]
    public function list_json_contains_no_contact_or_congregation_values(string $viewer): void
    {
        $this->exposedOwner();

        $response = $this->asViewer($viewer)->listMembers()
            ->assertOk()
            ->assertJsonPath('data.pagination.total', $viewer === 'active' ? 2 : 1);

        $this->assertDontSeeAny($response, [...self::MEMBERS_ONLY_VALUES, ...self::NEVER_VALUES]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function contactSearchTerms(): array
    {
        return [
            'email comercial' => [self::COMMERCIAL_EMAIL],
            'telefone comercial' => [self::COMMERCIAL_PHONE],
            'telefone comercial sem indicativo' => ['213456789'],
            'morada' => [self::ADDRESS],
            'parte da morada' => ['ZXQWMORADA'],
            'website' => [self::WEBSITE],
            'parte do website' => ['zxqw-site'],
            'rede social' => ['zxqw-linkedin'],
            'email da conta' => [self::ACCOUNT_EMAIL],
            'telefone da conta' => [self::ACCOUNT_PHONE],
        ];
    }

    #[Test]
    #[DataProvider('contactSearchTerms')]
    public function search_never_matches_contacts_address_or_website(string $term): void
    {
        $this->exposedOwner();

        $this->listMembers(['search' => 'Exposta'])->assertOk()->assertJsonPath('data.pagination.total', 1);

        $this->listMembers(['search' => $term])
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.pagination.total', 0);
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
    #[DataProvider('listViewers')]
    public function profile_response_is_never_cached_by_shared_proxies(string $viewer): void
    {
        $profile = $this->exposedOwner();

        $response = $this->asViewer($viewer)->showMember($profile->id)->assertOk();

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }
}
