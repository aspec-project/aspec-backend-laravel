<?php

namespace Tests\Feature\Member;

use App\Models\AccountStatus;
use App\Models\MemberProfile;
use App\Models\Portfolio;
use App\Models\Role;
use App\Models\SocialPlatform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberProfileShowTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/member-profile';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function makeUser(string $role, string $status): User
    {
        return User::factory()->create([
            'role_id' => Role::where('name', $role)->value('id'),
            'account_status_id' => AccountStatus::where('name', $status)->value('id'),
        ]);
    }

    private function memberWithProfile(string $status = 'Active', array $profile = []): User
    {
        $user = $this->makeUser('Member', $status);
        MemberProfile::factory()->create(['user_id' => $user->id, ...$profile]);

        return $user;
    }

    #[Test]
    public function active_member_gets_own_profile_fields(): void
    {
        $user = $this->memberWithProfile(profile: [
            'name' => 'Ana Silva',
            'business_name' => 'Silva Lda',
            'congregation' => 'Igreja Central',
            'role_in_congregation' => 'Diácona',
            'description' => 'Serviços de contabilidade.',
            'website_url' => 'https://silva.pt',
            'commercial_contacts' => 'geral@silva.pt',
            'address' => 'Rua Direita 1, Lisboa',
        ]);
        $profile = $user->memberProfile;
        Sanctum::actingAs($user);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Perfil obtido com sucesso.')
            ->assertJsonPath('data.id', $profile->id)
            ->assertJsonPath('data.name', 'Ana Silva')
            ->assertJsonPath('data.business_name', 'Silva Lda')
            ->assertJsonPath('data.congregation', 'Igreja Central')
            ->assertJsonPath('data.role_in_congregation', 'Diácona')
            ->assertJsonPath('data.description', 'Serviços de contabilidade.')
            ->assertJsonPath('data.website_url', 'https://silva.pt')
            ->assertJsonPath('data.commercial_contacts', 'geral@silva.pt')
            ->assertJsonPath('data.address', 'Rua Direita 1, Lisboa')
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.phone', $user->phone)
            ->assertJsonPath('data.sector.id', $profile->sector_id)
            ->assertJsonPath('data.sector.name', $profile->sector->name)
            ->assertJsonPath('data.location.id', $profile->location_id)
            ->assertJsonPath('data.location.name', $profile->location->name);
    }

    #[Test]
    public function response_does_not_expose_internal_or_sensitive_fields(): void
    {
        Sanctum::actingAs($this->memberWithProfile(profile: ['logo_path' => 'logos/x/logo.png']));

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.role_id')
            ->assertJsonMissingPath('data.account_status_id')
            ->assertJsonMissingPath('data.trial_ends_at')
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.logo_path');
    }

    #[Test]
    public function logo_url_is_public_url_of_logo_path(): void
    {
        $user = $this->memberWithProfile();
        $path = "logos/{$user->id}/logo.png";
        $user->memberProfile->update(['logo_path' => $path]);
        Sanctum::actingAs($user);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.logo_url', Storage::disk('public')->url($path));
    }

    #[Test]
    public function profile_without_logo_or_relations_returns_null_logo_and_empty_lists(): void
    {
        Sanctum::actingAs($this->memberWithProfile(profile: ['logo_path' => null]));

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.business_hours', [])
            ->assertJsonPath('data.social_links', [])
            ->assertJsonPath('data.portfolio', []);
    }

    #[Test]
    public function business_hours_are_ordered_by_week_day_and_formatted_as_hours_and_minutes(): void
    {
        $user = $this->memberWithProfile();
        $user->memberProfile->weekDays()->attach([
            5 => ['open_time' => '14:00:00', 'close_time' => '20:30:00'],
            1 => ['open_time' => '09:00:00', 'close_time' => '18:00:00'],
        ]);
        Sanctum::actingAs($user);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(2, 'data.business_hours')
            ->assertJsonPath('data.business_hours.0', [
                'week_day_id' => 1,
                'week_day' => 'Segunda-feira',
                'open_time' => '09:00',
                'close_time' => '18:00',
            ])
            ->assertJsonPath('data.business_hours.1', [
                'week_day_id' => 5,
                'week_day' => 'Sexta-feira',
                'open_time' => '14:00',
                'close_time' => '20:30',
            ]);
    }

    #[Test]
    public function social_links_include_platform_id_name_and_url(): void
    {
        $user = $this->memberWithProfile();
        $linkedin = SocialPlatform::where('name', 'LinkedIn')->firstOrFail();
        $user->memberProfile->socialPlatforms()->attach($linkedin->id, ['url' => 'https://linkedin.com/in/ana']);
        Sanctum::actingAs($user);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data.social_links')
            ->assertJsonPath('data.social_links.0', [
                'platform_id' => $linkedin->id,
                'platform' => 'LinkedIn',
                'url' => 'https://linkedin.com/in/ana',
            ]);
    }

    #[Test]
    public function portfolio_items_include_id_and_public_image_url(): void
    {
        $user = $this->memberWithProfile();
        $path = "portfolios/{$user->id}/foto.jpg";
        $image = Portfolio::create(['profile_id' => $user->memberProfile->id, 'image_path' => $path]);
        Sanctum::actingAs($user);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data.portfolio')
            ->assertJsonPath('data.portfolio.0', [
                'id' => $image->id,
                'image_url' => Storage::disk('public')->url($path),
            ])
            ->assertJsonMissingPath('data.portfolio.0.image_path');
    }

    #[Test]
    public function member_only_gets_own_profile(): void
    {
        $other = $this->memberWithProfile(profile: ['business_name' => 'Outra Empresa']);
        $other->memberProfile->socialPlatforms()->attach(
            SocialPlatform::where('name', 'Instagram')->value('id'),
            ['url' => 'https://instagram.com/outra'],
        );
        $user = $this->memberWithProfile(profile: ['business_name' => 'Minha Empresa']);
        Sanctum::actingAs($user);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.id', $user->memberProfile->id)
            ->assertJsonPath('data.business_name', 'Minha Empresa')
            ->assertJsonPath('data.social_links', []);
    }

    #[Test]
    public function unauthenticated_request_is_rejected(): void
    {
        $this->getJson(self::URL)
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Não autenticado.']);
    }

    #[Test]
    public function pending_member_cannot_get_profile(): void
    {
        Sanctum::actingAs($this->memberWithProfile('Pending'));

        $this->getJson(self::URL)
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'A conta não está ativa e não pode editar dados.')
            ->assertJsonMissingPath('data.id');
    }

    #[Test]
    public function inactive_member_cannot_get_profile(): void
    {
        Sanctum::actingAs($this->memberWithProfile('Inactive'));

        $this->getJson(self::URL)
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('data.id');
    }

    #[Test]
    public function admin_without_profile_gets_not_found(): void
    {
        Sanctum::actingAs($this->makeUser('Admin', 'Active'));

        $this->getJson(self::URL)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Perfil de membro não encontrado.']);
    }
}
