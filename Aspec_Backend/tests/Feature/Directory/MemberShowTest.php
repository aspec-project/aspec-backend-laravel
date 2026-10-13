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
 * A rota é pública mas deteta um utilizador opcional (sessão SPA ou Bearer): só uma conta
 * Active vê os contactos, agrupados em members_only; para os restantes members_only é null.
 */
class MemberShowTest extends TestCase
{
    use BuildsDirectoryMembers, RefreshDatabase;

    private const FRONTEND_ORIGIN = 'http://localhost:5173';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function ownerWithContacts(): MemberProfile
    {
        return $this->profileFor(User::factory()->state(['phone' => '912345678']), [
            'name' => 'Ana Silva',
            'business_name' => 'Silva Lda',
            'description' => 'Contabilidade para pequenas empresas.',
            'website_url' => 'https://silva.example',
            'address' => 'Rua Direita 1, Lisboa',
            'commercial_contacts' => 'Loja aberta ao publico',
        ]);
    }

    private function showWithToken(User $viewer, string $id): TestResponse
    {
        return $this->withToken($viewer->createToken('postman')->plainTextToken)->showMember($id);
    }

    private function assertContactsShown(TestResponse $response, MemberProfile $profile): void
    {
        $response->assertOk()
            ->assertJsonPath('data.members_only.phone', $profile->user->phone)
            ->assertJsonPath('data.members_only.commercial_contacts', $profile->commercial_contacts);

        $this->assertEqualsCanonicalizing(['phone', 'commercial_contacts'], array_keys($response->json('data.members_only')));
    }

    #[Test]
    public function visitor_gets_public_profile_without_contacts(): void
    {
        $profile = $this->ownerWithContacts();
        $sector = $this->firstSector();
        $location = $this->firstLocation();

        $response = $this->showMember($profile->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Perfil do membro obtido com sucesso.')
            ->assertJsonPath('data.id', $profile->id)
            ->assertJsonPath('data.name', 'Ana Silva')
            ->assertJsonPath('data.business_name', 'Silva Lda')
            ->assertJsonPath('data.description', 'Contabilidade para pequenas empresas.')
            ->assertJsonPath('data.sector', ['id' => $sector->id, 'name' => $sector->name])
            ->assertJsonPath('data.location', ['id' => $location->id, 'name' => $location->name])
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.website_url', 'https://silva.example')
            ->assertJsonPath('data.address', 'Rua Direita 1, Lisboa')
            ->assertJsonPath('data.business_hours', [])
            ->assertJsonPath('data.social_links', [])
            ->assertJsonPath('data.portfolio', [])
            ->assertJsonPath('data.average_rating', null)
            ->assertJsonPath('data.reviews_count', 0)
            ->assertJsonPath('data.members_only', null);

        $this->assertEqualsCanonicalizing(self::DETAIL_KEYS, array_keys($response->json('data')));
    }

    #[Test]
    public function active_member_with_spa_session_sees_contacts(): void
    {
        $profile = $this->ownerWithContacts();
        $viewer = $this->listedMember()->user;

        $response = $this->actingAs($viewer, 'web')
            ->withHeaders(['Origin' => self::FRONTEND_ORIGIN, 'Referer' => self::FRONTEND_ORIGIN.'/membros'])
            ->showMember($profile->id);

        $this->assertContactsShown($response, $profile);
    }

    #[Test]
    public function active_member_with_bearer_token_sees_contacts(): void
    {
        $profile = $this->ownerWithContacts();
        $viewer = $this->listedMember()->user;

        $this->assertContactsShown($this->showWithToken($viewer, $profile->id), $profile);
    }

    #[Test]
    public function active_admin_sees_contacts(): void
    {
        $profile = $this->ownerWithContacts();
        $admin = User::factory()->admin()->create();

        $this->assertContactsShown($this->showWithToken($admin, $profile->id), $profile);
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

        $this->showWithToken($viewer, $profile->id)
            ->assertOk()
            ->assertJsonPath('data.id', $profile->id)
            ->assertJsonPath('data.members_only', null);
    }

    #[Test]
    public function invalid_bearer_token_is_treated_as_visitor_and_never_401(): void
    {
        $profile = $this->ownerWithContacts();

        $this->withToken('999|token-que-nao-existe')
            ->showMember($profile->id)
            ->assertOk()
            ->assertJsonPath('data.members_only', null);
    }

    #[Test]
    public function expired_bearer_token_is_treated_as_visitor_and_never_401(): void
    {
        $profile = $this->ownerWithContacts();
        $token = $this->listedMember()->user->createToken('postman')->plainTextToken;

        $this->travel(config('sanctum.expiration') + 1)->minutes();

        $this->withToken($token)
            ->showMember($profile->id)
            ->assertOk()
            ->assertJsonPath('data.members_only', null);
    }

    #[Test]
    public function owner_viewing_own_public_profile_sees_contacts(): void
    {
        $profile = $this->ownerWithContacts();

        $this->assertContactsShown($this->showWithToken($profile->user, $profile->id), $profile);
    }

    #[Test]
    public function business_hours_social_links_and_portfolio_are_formatted(): void
    {
        $profile = $this->listedMember();
        $profile->weekDays()->attach([
            5 => ['open_time' => '14:00:00', 'close_time' => '20:30:00'],
            1 => ['open_time' => '09:00:00', 'close_time' => '18:00:00'],
        ]);
        $linkedin = SocialPlatform::where('name', 'LinkedIn')->firstOrFail();
        $profile->socialPlatforms()->attach($linkedin->id, ['url' => 'https://linkedin.com/in/ana']);

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
            ->assertJsonPath('data.social_links', [
                ['platform_id' => $linkedin->id, 'platform' => 'LinkedIn', 'url' => 'https://linkedin.com/in/ana'],
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
        $empty = $this->listedMember(['business_name' => 'Vazio']);
        $full = $this->listedMember(['business_name' => 'Completo']);

        $full->weekDays()->attach(collect(range(1, 7))->mapWithKeys(fn (int $day) => [
            $day => ['open_time' => '09:00:00', 'close_time' => '18:00:00'],
        ])->all());
        SocialPlatform::whereIn('name', ['LinkedIn', 'Instagram', 'Facebook'])->get()
            ->each(fn (SocialPlatform $platform) => $full->socialPlatforms()->attach($platform->id, ['url' => 'https://example.com/'.$platform->name]));
        for ($i = 1; $i <= 10; $i++) {
            Portfolio::create(['profile_id' => $full->id, 'image_path' => "portfolios/{$full->user_id}/{$i}.jpg"]);
        }

        $this->assertSame(
            $this->countQueries(fn () => $this->showMember($empty->id)->assertOk()),
            $this->countQueries(fn () => $this->showMember($full->id)
                ->assertOk()
                ->assertJsonCount(7, 'data.business_hours')
                ->assertJsonCount(3, 'data.social_links')
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
