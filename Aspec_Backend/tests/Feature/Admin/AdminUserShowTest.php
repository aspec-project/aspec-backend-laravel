<?php

namespace Tests\Feature\Admin;

use App\Enums\InactiveReason;
use App\Models\Location;
use App\Models\MemberProfile;
use App\Models\Portfolio;
use App\Models\Sector;
use App\Models\SocialPlatform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminUserShowTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_FOUND_BODY = ['success' => false, 'message' => 'Recurso não encontrado.'];

    private const ACCOUNT_KEYS = ['id', 'email', 'phone', 'created_at', 'role', 'account_status', 'inactive_reason', 'member_profile'];

    private const PROFILE_KEYS = [
        'id', 'name', 'business_name', 'congregation', 'role_in_congregation', 'description', 'website_url',
        'commercial_contacts', 'address', 'email', 'phone', 'sector', 'location', 'logo_url', 'business_hours',
        'social_links', 'portfolio',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
    }

    #[Test]
    public function pending_candidate_detail_returns_account_and_full_member_profile(): void
    {
        $sector = Sector::query()->firstOrFail();
        $location = Location::query()->firstOrFail();
        $user = User::factory()->pending()->create(['email' => 'maria@santos.pt', 'phone' => '912345678']);
        $profile = MemberProfile::factory()->for($user)->create([
            'sector_id' => $sector->id,
            'location_id' => $location->id,
            'name' => 'Maria Santos',
            'business_name' => 'Santos Contabilidade',
            'congregation' => 'Igreja Evangelica de Lisboa',
            'role_in_congregation' => 'Diaconisa',
            'description' => 'Contabilidade para pequenas empresas.',
            'website_url' => 'https://santos.pt',
            'commercial_contacts' => 'geral@santos.pt | 213 456 789',
            'address' => 'Av. da Liberdade 100, 1250-146 Lisboa',
            'logo_path' => "logos/{$user->id}/logo.png",
        ]);
        $profile->weekDays()->attach(1, ['open_time' => '09:00', 'close_time' => '18:00']);
        $profile->weekDays()->attach(3, ['open_time' => '09:00', 'close_time' => '13:00']);
        $linkedin = SocialPlatform::where('name', 'LinkedIn')->firstOrFail();
        $profile->socialPlatforms()->attach($linkedin->id, ['url' => 'https://www.linkedin.com/company/santos']);
        Portfolio::create(['profile_id' => $profile->id, 'image_path' => "portfolios/{$user->id}/1.jpg"]);
        Portfolio::create(['profile_id' => $profile->id, 'image_path' => "portfolios/{$user->id}/2.jpg"]);

        $response = $this->show($user->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Utilizador obtido com sucesso.');

        $mondayIndex = collect($response->json('data.member_profile.business_hours'))->search(fn (array $hour) => $hour['week_day_id'] === 1);
        $this->assertNotFalse($mondayIndex);
        $response->assertJsonPath("data.member_profile.business_hours.{$mondayIndex}.week_day", 'Segunda-feira');

        $data = $response->json('data');
        $this->assertEqualsCanonicalizing(self::ACCOUNT_KEYS, array_keys($data));
        $this->assertSame($user->id, $data['id']);
        $this->assertSame('maria@santos.pt', $data['email']);
        $this->assertSame('912345678', $data['phone']);
        $this->assertSame($user->created_at->toIso8601String(), $data['created_at']);
        $this->assertSame('Member', data_get($data, 'role.name'));
        $this->assertSame($user->role_id, data_get($data, 'role.id'));
        $this->assertSame('Pending', data_get($data, 'account_status.name'));
        $this->assertSame($user->account_status_id, data_get($data, 'account_status.id'));
        $this->assertNull($data['inactive_reason']);

        $memberProfile = $data['member_profile'];
        $this->assertEqualsCanonicalizing(self::PROFILE_KEYS, array_keys($memberProfile));
        $this->assertSame($profile->id, $memberProfile['id']);
        $this->assertSame('Maria Santos', $memberProfile['name']);
        $this->assertSame('Santos Contabilidade', $memberProfile['business_name']);
        $this->assertSame('Igreja Evangelica de Lisboa', $memberProfile['congregation']);
        $this->assertSame('Diaconisa', $memberProfile['role_in_congregation']);
        $this->assertSame('Contabilidade para pequenas empresas.', $memberProfile['description']);
        $this->assertSame('https://santos.pt', $memberProfile['website_url']);
        $this->assertSame('geral@santos.pt | 213 456 789', $memberProfile['commercial_contacts']);
        $this->assertSame('Av. da Liberdade 100, 1250-146 Lisboa', $memberProfile['address']);
        $this->assertSame('maria@santos.pt', $memberProfile['email']);
        $this->assertSame('912345678', $memberProfile['phone']);
        $this->assertSame(['id' => $sector->id, 'name' => $sector->name], $memberProfile['sector']);
        $this->assertSame(['id' => $location->id, 'name' => $location->name], $memberProfile['location']);
        $this->assertStringContainsString('/storage/logos/', $memberProfile['logo_url']);

        $this->assertCount(2, $memberProfile['business_hours']);
        $this->assertSame([1, 3], collect($memberProfile['business_hours'])->pluck('week_day_id')->sort()->values()->all());
        $this->assertSame(['09:00', '09:00'], collect($memberProfile['business_hours'])->pluck('open_time')->all());

        $this->assertSame('https://www.linkedin.com/company/santos', data_get($memberProfile, 'social_links.0.url'));
        $this->assertSame('LinkedIn', data_get($memberProfile, 'social_links.0.platform'));

        $this->assertCount(2, $memberProfile['portfolio']);
        foreach ($memberProfile['portfolio'] as $image) {
            $this->assertStringContainsString('/storage/portfolios/', $image['image_url']);
            $this->assertArrayNotHasKey('image_path', $image);
        }
    }

    #[Test]
    public function detail_does_not_expose_billing_stripe_or_internal_fields(): void
    {
        $user = User::factory()->withBilling()->create([
            'stripe_id' => 'cus_test_123',
            'pm_type' => 'visa',
            'pm_last_four' => '4242',
            'trial_ends_at' => now()->addDays(30),
            'grace_ends_at' => now()->addDays(7),
            'stripe_checkout_session_id' => 'cs_test_123',
        ]);
        MemberProfile::factory()->for($user)->create(['logo_path' => "logos/{$user->id}/logo.png"]);

        $data = $this->show($user->id)->assertOk()->json('data');

        foreach ([
            'billing_name', 'nif', 'billing_address', 'billing_postal_code', 'billing_city',
            'stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at', 'grace_ends_at', 'stripe_checkout_session_id',
            'password', 'remember_token', 'role_id', 'account_status_id', 'email_verified_at',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }

        foreach (['user_id', 'logo_path', 'deleted_at'] as $key) {
            $this->assertArrayNotHasKey($key, $data['member_profile']);
        }
    }

    #[Test]
    public function blocked_member_shows_inactive_status_and_reason(): void
    {
        $user = User::factory()->inactive(InactiveReason::Blocked)->create();

        $this->show($user->id)
            ->assertOk()
            ->assertJsonPath('data.account_status.name', 'Inactive')
            ->assertJsonPath('data.inactive_reason', 'blocked');
    }

    #[Test]
    public function other_admin_without_profile_has_null_member_profile(): void
    {
        $otherAdmin = User::factory()->admin()->create();

        $response = $this->show($otherAdmin->id)
            ->assertOk()
            ->assertJsonPath('data.role.name', 'Admin');

        $this->assertArrayHasKey('member_profile', $response->json('data'));
        $this->assertNull($response->json('data.member_profile'));
    }

    #[Test]
    public function admin_can_view_own_account(): void
    {
        $this->show($this->admin->id)
            ->assertOk()
            ->assertJsonPath('data.id', $this->admin->id)
            ->assertJsonPath('data.email', $this->admin->email);
    }

    #[Test]
    public function unknown_uuid_returns_generic_404(): void
    {
        $this->show(User::factory()->create()->id)->assertOk();

        $this->show((string) Str::uuid())
            ->assertNotFound()
            ->assertExactJson(self::NOT_FOUND_BODY);
    }

    #[Test]
    public function soft_deleted_user_returns_generic_404(): void
    {
        $this->show(User::factory()->create()->id)->assertOk();

        $deleted = User::factory()->create();
        $deleted->delete();

        $this->show($deleted->id)
            ->assertNotFound()
            ->assertExactJson(self::NOT_FOUND_BODY);
    }

    #[Test]
    public function id_that_is_not_a_uuid_returns_generic_404(): void
    {
        $this->show(User::factory()->create()->id)->assertOk();

        foreach (['nao-e-uuid', '123'] as $id) {
            $this->show($id)
                ->assertNotFound()
                ->assertExactJson(self::NOT_FOUND_BODY);
        }
    }

    #[Test]
    public function soft_deleted_profile_is_returned_as_null(): void
    {
        $user = User::factory()->create();
        MemberProfile::factory()->for($user)->create()->delete();

        $response = $this->show($user->id)->assertOk();

        $this->assertArrayHasKey('member_profile', $response->json('data'));
        $this->assertNull($response->json('data.member_profile'));
    }

    #[Test]
    public function guest_cannot_view_user_detail(): void
    {
        $user = User::factory()->create();
        $this->app['auth']->forgetGuards();

        $this->show($user->id)
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Não autenticado.',
            ]);
    }

    #[Test]
    public function member_cannot_view_user_detail(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->show($user->id)
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'Não tem permissão para realizar esta ação.',
            ]);
    }

    #[Test]
    public function admin_with_inactive_account_cannot_view_user_detail(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->inactive()->create());

        $this->show($user->id)
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'A conta não está ativa e não pode editar dados.',
            ]);
    }

    #[Test]
    public function number_of_queries_does_not_grow_with_hours_links_and_images(): void
    {
        $empty = User::factory()->create();
        MemberProfile::factory()->for($empty)->create();

        $full = User::factory()->create();
        $profile = MemberProfile::factory()->for($full)->create();
        foreach ([1, 2, 3] as $day) {
            $profile->weekDays()->attach($day, ['open_time' => '09:00', 'close_time' => '18:00']);
        }
        SocialPlatform::whereIn('name', ['LinkedIn', 'Instagram'])->get()
            ->each(fn (SocialPlatform $platform) => $profile->socialPlatforms()->attach($platform->id, ['url' => 'https://example.com/'.$platform->name]));
        for ($i = 1; $i <= 4; $i++) {
            Portfolio::create(['profile_id' => $profile->id, 'image_path' => "portfolios/{$full->id}/{$i}.jpg"]);
        }
        $this->admin->load(['role', 'accountStatus']);

        $this->assertSame(
            $this->countQueries(fn () => $this->show($empty->id)->assertOk()),
            $this->countQueries(fn () => $this->show($full->id)
                ->assertOk()
                ->assertJsonCount(3, 'data.member_profile.business_hours')
                ->assertJsonCount(2, 'data.member_profile.social_links')
                ->assertJsonCount(4, 'data.member_profile.portfolio')),
        );
    }

    private function show(string $id): TestResponse
    {
        return $this->getJson("/api/admin/users/{$id}");
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
