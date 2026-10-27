<?php

namespace Tests\Feature\Directory;

use App\Enums\InactiveReason;
use App\Models\MemberProfile;
use App\Models\Portfolio;
use App\Models\SocialPlatform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsDirectoryMembers;
use Tests\TestCase;

/**
 * Perfil público do membro (GET /api/members/{id}).
 *
 * A rota é pública mas deteta um utilizador opcional (sessão SPA ou Bearer). Website, morada,
 * código postal, localidade, redes sociais e contactos comerciais só aparecem em members_only, e só para contas Active;
 * para os restantes members_only é null. O email e o telefone da conta nunca aparecem.
 */
class MemberShowTest extends TestCase
{
    use BuildsDirectoryMembers, RefreshDatabase;

    private const FRONTEND_ORIGIN = 'http://localhost:5173';

    private const ACCOUNT_EMAIL = 'dono.ZXQWPESSOAL@empresa.test';

    private const ACCOUNT_PHONE = '961234567';

    private const COMMERCIAL_EMAIL = 'comercial.ZXQW@empresa.test';

    private const COMMERCIAL_PHONE = '+351213456789';

    private const WEBSITE = 'https://zxqw-site.test';

    private const ADDRESS = 'Rua ZXQWMORADA 1';

    private const POSTAL_CODE = '7777-321';

    private const CITY = 'Localidade ZXQWCIDADE';

    private const LINKEDIN_URL = 'https://www.linkedin.com/company/zxqw-linkedin';

    private const INSTAGRAM_URL = 'https://instagram.com/zxqw-instagram';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function ownerWithContacts(): MemberProfile
    {
        $owner = User::factory()->create(['email' => self::ACCOUNT_EMAIL, 'phone' => self::ACCOUNT_PHONE]);

        $profile = $this->profileFor($owner, [
            'name' => 'Ana Silva',
            'business_name' => 'Silva Lda',
            'description' => 'Contabilidade para pequenas empresas.',
            'commercial_email' => self::COMMERCIAL_EMAIL,
            'commercial_phone' => self::COMMERCIAL_PHONE,
            'website_url' => self::WEBSITE,
            'address' => self::ADDRESS,
            'postal_code' => self::POSTAL_CODE,
            'city' => self::CITY,
        ]);
        $profile->socialPlatforms()->attach([
            $this->platformId('LinkedIn') => ['url' => self::LINKEDIN_URL],
            $this->platformId('Instagram') => ['url' => self::INSTAGRAM_URL],
        ]);

        return $profile;
    }

    private function platformId(string $name): string
    {
        return SocialPlatform::where('name', $name)->value('id');
    }

    private function showWithToken(User $viewer, string $id): TestResponse
    {
        return $this->withToken($viewer->createToken('postman')->plainTextToken)->showMember($id);
    }

    /**
     * O que um visitante vê: só a montra, members_only a null e nenhum valor de contacto no JSON.
     */
    private function assertVisitorView(TestResponse $response, MemberProfile $profile): void
    {
        $response->assertOk()
            ->assertJsonPath('data.id', $profile->id)
            ->assertJsonPath('data.members_only', null)
            ->assertJsonMissingPath('data.website_url')
            ->assertJsonMissingPath('data.address')
            ->assertJsonMissingPath('data.social_links')
            ->assertJsonMissingPath('data.postal_code')
            ->assertJsonMissingPath('data.city')
            ->assertDontSee(self::COMMERCIAL_EMAIL)
            ->assertDontSee(self::COMMERCIAL_PHONE)
            ->assertDontSee('zxqw-site')
            ->assertDontSee(self::ADDRESS)
            ->assertDontSee(self::POSTAL_CODE)
            ->assertDontSee('ZXQWCIDADE')
            ->assertDontSee('zxqw-linkedin')
            ->assertDontSee('zxqw-instagram');

        $this->assertEqualsCanonicalizing(self::DETAIL_KEYS, array_keys($response->json('data')));
    }

    /**
     * O que uma conta Active vê: as 7 chaves de members_only com os valores do dono do perfil.
     */
    private function assertContactsShown(TestResponse $response): void
    {
        $response->assertOk()
            ->assertJsonPath('data.members_only.commercial_email', self::COMMERCIAL_EMAIL)
            ->assertJsonPath('data.members_only.commercial_phone', self::COMMERCIAL_PHONE)
            ->assertJsonPath('data.members_only.website_url', self::WEBSITE)
            ->assertJsonPath('data.members_only.address', self::ADDRESS)
            ->assertJsonPath('data.members_only.postal_code', self::POSTAL_CODE)
            ->assertJsonPath('data.members_only.city', self::CITY)
            ->assertJsonCount(2, 'data.members_only.social_links')
            ->assertJsonMissingPath('data.website_url')
            ->assertJsonMissingPath('data.address')
            ->assertJsonMissingPath('data.postal_code')
            ->assertJsonMissingPath('data.city')
            ->assertJsonMissingPath('data.social_links');

        $this->assertEqualsCanonicalizing(self::DETAIL_KEYS, array_keys($response->json('data')));
        $this->assertEqualsCanonicalizing(self::MEMBERS_ONLY_KEYS, array_keys($response->json('data.members_only')));
        $this->assertEqualsCanonicalizing(
            [self::LINKEDIN_URL, self::INSTAGRAM_URL],
            array_column($response->json('data.members_only.social_links'), 'url'),
        );
    }

    #[Test]
    public function visitor_gets_only_the_public_showcase(): void
    {
        $profile = $this->ownerWithContacts();
        $sector = $this->firstSector();
        $location = $this->firstLocation();

        $response = $this->showMember($profile->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Perfil do membro obtido com sucesso.')
            ->assertJsonPath('data.name', 'Ana Silva')
            ->assertJsonPath('data.business_name', 'Silva Lda')
            ->assertJsonPath('data.description', 'Contabilidade para pequenas empresas.')
            ->assertJsonPath('data.sector', ['id' => $sector->id, 'name' => $sector->name])
            ->assertJsonPath('data.location', ['id' => $location->id, 'name' => $location->name])
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.business_hours', [])
            ->assertJsonPath('data.portfolio', [])
            ->assertJsonPath('data.average_rating', null)
            ->assertJsonPath('data.reviews_count', 0);

        $this->assertVisitorView($response, $profile);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function viewersThatAreNotActive(): array
    {
        return [
            'Pending' => ['pending', null],
            'Approved' => ['approved', null],
            'Inactive blocked' => ['inactive', 'blocked'],
            'Inactive unpaid' => ['inactive', 'unpaid'],
            'Inactive cancelled' => ['inactive', 'cancelled'],
        ];
    }

    #[Test]
    #[DataProvider('viewersThatAreNotActive')]
    public function authenticated_account_that_is_not_active_is_treated_as_visitor(string $state, ?string $reason): void
    {
        $profile = $this->ownerWithContacts();
        $viewer = $state === 'inactive'
            ? User::factory()->inactive(InactiveReason::from($reason))->create()
            : User::factory()->{$state}()->create();

        $this->assertVisitorView($this->showWithToken($viewer, $profile->id), $profile);
    }

    #[Test]
    public function invalid_bearer_token_is_treated_as_visitor_and_never_401(): void
    {
        $profile = $this->ownerWithContacts();

        $this->assertVisitorView($this->withToken('999|token-que-nao-existe')->showMember($profile->id), $profile);
    }

    #[Test]
    public function expired_bearer_token_is_treated_as_visitor_and_never_401(): void
    {
        $profile = $this->ownerWithContacts();
        $token = $this->listedMember()->user->createToken('postman')->plainTextToken;

        $this->travel(config('sanctum.expiration') + 1)->minutes();

        $this->assertVisitorView($this->withToken($token)->showMember($profile->id), $profile);
    }

    #[Test]
    public function active_member_with_spa_session_sees_contacts(): void
    {
        $profile = $this->ownerWithContacts();
        $viewer = $this->listedMember()->user;

        $response = $this->actingAs($viewer, 'web')
            ->withHeaders(['Origin' => self::FRONTEND_ORIGIN, 'Referer' => self::FRONTEND_ORIGIN.'/membros'])
            ->showMember($profile->id);

        $this->assertContactsShown($response);
    }

    #[Test]
    public function active_member_with_bearer_token_sees_contacts(): void
    {
        $profile = $this->ownerWithContacts();
        $viewer = $this->listedMember()->user;

        $this->assertContactsShown($this->showWithToken($viewer, $profile->id));
    }

    #[Test]
    public function owner_viewing_own_public_profile_sees_contacts(): void
    {
        $profile = $this->ownerWithContacts();

        $this->assertContactsShown($this->showWithToken($profile->user, $profile->id));
    }

    #[Test]
    public function active_admin_sees_the_same_contacts_and_never_the_account_contacts(): void
    {
        $profile = $this->ownerWithContacts();
        $admin = User::factory()->admin()->create();

        $response = $this->showWithToken($admin, $profile->id);

        $this->assertContactsShown($response);
        $response->assertDontSee(self::ACCOUNT_EMAIL)->assertDontSee(self::ACCOUNT_PHONE);
    }

    #[Test]
    public function members_only_lists_every_social_link_in_the_social_link_format(): void
    {
        $profile = $this->listedMember();
        $platforms = SocialPlatform::whereIn('name', ['LinkedIn', 'Instagram', 'Facebook'])->get();
        $this->assertCount(3, $platforms);
        $platforms->each(fn (SocialPlatform $platform) => $profile->socialPlatforms()
            ->attach($platform->id, ['url' => 'https://example.com/'.$platform->name]));
        $viewer = $this->listedMember(['business_name' => 'Quem Consulta'])->user;

        $response = $this->showWithToken($viewer, $profile->id)
            ->assertOk()
            ->assertJsonCount(3, 'data.members_only.social_links');

        $this->assertEqualsCanonicalizing(
            $platforms->map(fn (SocialPlatform $platform) => [
                'platform_id' => $platform->id,
                'platform' => $platform->name,
                'url' => 'https://example.com/'.$platform->name,
            ])->all(),
            $response->json('data.members_only.social_links'),
        );
    }

    #[Test]
    public function legacy_profile_without_contacts_still_returns_the_seven_members_only_keys(): void
    {
        $profile = $this->listedMember([
            'commercial_email' => null,
            'commercial_phone' => null,
            'website_url' => null,
            'address' => null,
            'postal_code' => null,
            'city' => null,
        ]);
        $viewer = $this->listedMember(['business_name' => 'Quem Consulta'])->user;

        $response = $this->showWithToken($viewer, $profile->id)->assertOk();

        $membersOnly = $response->json('data.members_only');
        ksort($membersOnly);
        $this->assertSame([
            'address' => null,
            'city' => null,
            'commercial_email' => null,
            'commercial_phone' => null,
            'postal_code' => null,
            'social_links' => [],
            'website_url' => null,
        ], $membersOnly);
        $this->assertEqualsCanonicalizing(self::MEMBERS_ONLY_KEYS, array_keys($membersOnly));
    }

    #[Test]
    public function business_hours_and_portfolio_are_public_and_formatted(): void
    {
        $profile = $this->listedMember();
        $profile->weekDays()->attach([
            5 => ['open_time' => '14:00:00', 'close_time' => '20:30:00'],
            1 => ['open_time' => '09:00:00', 'close_time' => '18:00:00'],
        ]);

        $older = Portfolio::create(['profile_id' => $profile->id, 'image_path' => "portfolios/{$profile->user_id}/a.jpg"]);
        $newer = Portfolio::create(['profile_id' => $profile->id, 'image_path' => "portfolios/{$profile->user_id}/b.jpg"]);
        // O primeiro a ser criado fica com a data mais recente: prova que a ordem é por created_at e não pelo id.
        $older->forceFill(['created_at' => now()->addMinute()])->save();

        $this->showMember($profile->id)
            ->assertOk()
            ->assertJsonPath('data.business_hours', [
                ['week_day_id' => 1, 'week_day' => 'Segunda-feira', 'open_time' => '09:00', 'close_time' => '18:00'],
                ['week_day_id' => 5, 'week_day' => 'Sexta-feira', 'open_time' => '14:00', 'close_time' => '20:30'],
            ])
            ->assertJsonPath('data.portfolio', [
                ['id' => $newer->id, 'image_url' => Storage::disk('public')->url($newer->image_path)],
                ['id' => $older->id, 'image_url' => Storage::disk('public')->url($older->image_path)],
            ]);
    }

    #[Test]
    public function unknown_id_returns_the_same_404_as_a_hidden_profile(): void
    {
        $pending = $this->profileFor(User::factory()->pending());

        $unknown = $this->showMember((string) Str::uuid())
            ->assertNotFound()
            ->assertExactJson(self::NOT_FOUND_BODY);

        $hidden = $this->showMember($pending->id)
            ->assertNotFound()
            ->assertExactJson(self::NOT_FOUND_BODY);

        $this->assertSame($unknown->getContent(), $hidden->getContent());
    }

    #[Test]
    public function id_that_is_not_a_uuid_returns_generic_404(): void
    {
        $this->showMember('nao-e-um-uuid')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Recurso não encontrado.']);
    }

    #[Test]
    public function number_of_queries_does_not_grow_with_hours_links_and_images(): void
    {
        $this->freezeTime();
        $empty = $this->listedMember(['business_name' => 'Vazio']);
        $full = $this->listedMember(['business_name' => 'Completo']);
        $token = $this->listedMember(['business_name' => 'Quem Consulta'])->user->createToken('postman')->plainTextToken;

        $full->weekDays()->attach(collect(range(1, 7))->mapWithKeys(fn (int $day) => [
            $day => ['open_time' => '09:00:00', 'close_time' => '18:00:00'],
        ])->all());
        SocialPlatform::whereIn('name', ['LinkedIn', 'Instagram', 'Facebook'])->get()
            ->each(fn (SocialPlatform $platform) => $full->socialPlatforms()->attach($platform->id, ['url' => 'https://example.com/'.$platform->name]));
        for ($i = 1; $i <= 10; $i++) {
            Portfolio::create(['profile_id' => $full->id, 'image_path' => "portfolios/{$full->user_id}/{$i}.jpg"]);
        }

        // Com o tempo congelado, o 1.º pedido grava o last_used_at do token e os seguintes já não o alteram.
        $this->withToken($token)->showMember($empty->id)->assertOk();

        $this->assertSame(
            $this->countQueries(fn () => $this->withToken($token)->showMember($empty->id)->assertOk()),
            $this->countQueries(fn () => $this->withToken($token)->showMember($full->id)
                ->assertOk()
                ->assertJsonCount(7, 'data.business_hours')
                ->assertJsonCount(3, 'data.members_only.social_links')
                ->assertJsonCount(10, 'data.portfolio')),
        );
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
