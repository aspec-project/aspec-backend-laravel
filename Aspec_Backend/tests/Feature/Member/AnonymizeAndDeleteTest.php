<?php

namespace Tests\Feature\Member;

use App\Models\AccountStatus;
use App\Models\MemberProfile;
use App\Models\Portfolio;
use App\Models\SocialPlatform;
use App\Models\User;
use App\Models\WeekDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnonymizeAndDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function memberWithFullProfile(): User
    {
        $user = User::factory()->create();
        $profile = MemberProfile::factory()->create([
            'user_id' => $user->id,
            'logo_path' => "logos/{$user->id}/logo.png",
        ]);

        $profile->weekDays()->attach(WeekDay::find(1), ['open_time' => '09:00', 'close_time' => '18:00']);
        $profile->socialPlatforms()->attach(SocialPlatform::first(), ['url' => 'https://example.com/perfil']);
        Portfolio::create(['profile_id' => $profile->id, 'image_path' => "portfolios/{$user->id}/a.jpg"]);

        Storage::disk('public')->put("logos/{$user->id}/logo.png", 'conteudo');
        Storage::disk('public')->put("portfolios/{$user->id}/a.jpg", 'conteudo');

        return $user;
    }

    #[Test]
    public function business_hours_social_links_and_portfolio_are_hard_deleted(): void
    {
        $user = $this->memberWithFullProfile();
        $profileId = $user->memberProfile->id;

        $user->anonymizeAndDelete();

        $this->assertDatabaseMissing('business_hours', ['profile_id' => $profileId]);
        $this->assertDatabaseMissing('social_links', ['profile_id' => $profileId]);
        $this->assertDatabaseMissing('portfolios', ['profile_id' => $profileId]);
    }

    #[Test]
    public function logo_and_portfolio_folders_are_removed(): void
    {
        $user = $this->memberWithFullProfile();

        $user->anonymizeAndDelete();

        Storage::disk('public')->assertMissing("logos/{$user->id}/logo.png");
        Storage::disk('public')->assertMissing("portfolios/{$user->id}/a.jpg");
        $this->assertFalse(Storage::disk('public')->directoryExists("logos/{$user->id}"));
        $this->assertFalse(Storage::disk('public')->directoryExists("portfolios/{$user->id}"));
    }

    #[Test]
    public function other_members_files_are_kept(): void
    {
        $user = $this->memberWithFullProfile();
        $other = $this->memberWithFullProfile();

        $user->anonymizeAndDelete();

        Storage::disk('public')->assertExists("logos/{$other->id}/logo.png");
        Storage::disk('public')->assertExists("portfolios/{$other->id}/a.jpg");
    }

    #[Test]
    public function profile_is_anonymized_and_soft_deleted(): void
    {
        $user = $this->memberWithFullProfile();
        $profile = $user->memberProfile;

        $user->anonymizeAndDelete();

        $this->assertSoftDeleted('member_profiles', [
            'id' => $profile->id,
            'name' => 'Utilizador Anónimo',
            'business_name' => 'Empresa Removida',
            'logo_path' => null,
            'description' => null,
            'website_url' => null,
            'commercial_contacts' => null,
            'address' => null,
        ]);
    }

    #[Test]
    public function user_is_anonymized_and_soft_deleted(): void
    {
        $user = $this->memberWithFullProfile();
        $originalEmail = $user->email;

        $user->anonymizeAndDelete();

        $this->assertSoftDeleted('users', ['id' => $user->id, 'phone' => '000000000']);

        $fresh = User::withTrashed()->find($user->id);
        $this->assertNotSame($originalEmail, $fresh->email);
        $this->assertMatchesRegularExpression('/^deleted_.+@aspec\.local$/', $fresh->email);
        // A factory cria os users com a password "password".
        $this->assertFalse(Hash::check('password', $fresh->password));
    }

    #[Test]
    public function user_without_profile_is_anonymized_without_exception(): void
    {
        $admin = User::factory()->admin()->create();
        $originalEmail = $admin->email;

        $admin->anonymizeAndDelete();

        $this->assertSoftDeleted('users', ['id' => $admin->id, 'phone' => '000000000']);
        $this->assertNotSame($originalEmail, User::withTrashed()->find($admin->id)->email);
    }

    #[Test]
    public function user_without_profile_has_logo_folder_removed(): void
    {
        $admin = User::factory()->admin()->create();
        Storage::disk('public')->put("logos/{$admin->id}/logo.png", 'conteudo');

        $admin->anonymizeAndDelete();

        Storage::disk('public')->assertMissing("logos/{$admin->id}/logo.png");
    }

    #[Test]
    public function all_tokens_are_revoked(): void
    {
        $user = $this->memberWithFullProfile();
        $other = $this->memberWithFullProfile();
        $user->createToken('web');
        $user->createToken('mobile');
        $other->createToken('web');

        $user->anonymizeAndDelete();

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $other->id]);
    }

    #[Test]
    public function account_fields_are_cleared_and_status_is_inactive(): void
    {
        $user = $this->memberWithFullProfile();
        $user->forceFill(['remember_token' => 'abc123', 'trial_ends_at' => now()->addDays(30)])->save();

        $user->anonymizeAndDelete();

        $fresh = User::withTrashed()->find($user->id);
        $this->assertNull($fresh->remember_token);
        $this->assertNull($fresh->email_verified_at);
        $this->assertNull($fresh->trial_ends_at);
        $this->assertSame(AccountStatus::where('name', 'Inactive')->value('id'), $fresh->account_status_id);
    }

    #[Test]
    public function already_soft_deleted_profile_is_anonymized(): void
    {
        $user = $this->memberWithFullProfile();
        $profile = $user->memberProfile;
        $profile->delete();

        $user->refresh()->anonymizeAndDelete();

        $this->assertSoftDeleted('member_profiles', ['id' => $profile->id, 'name' => 'Utilizador Anónimo']);
        $this->assertDatabaseMissing('business_hours', ['profile_id' => $profile->id]);
        $this->assertDatabaseMissing('social_links', ['profile_id' => $profile->id]);
        $this->assertDatabaseMissing('portfolios', ['profile_id' => $profile->id]);
    }

    #[Test]
    public function files_are_kept_when_an_outer_transaction_rolls_back(): void
    {
        $user = $this->memberWithFullProfile();

        DB::beginTransaction();
        $user->anonymizeAndDelete();
        DB::rollBack();

        Storage::disk('public')->assertExists("logos/{$user->id}/logo.png");
        Storage::disk('public')->assertExists("portfolios/{$user->id}/a.jpg");
        $this->assertNotSoftDeleted('users', ['id' => $user->id]);
    }

    #[Test]
    public function other_members_data_is_untouched(): void
    {
        $user = $this->memberWithFullProfile();
        $other = $this->memberWithFullProfile();
        $otherProfile = $other->memberProfile;

        $user->anonymizeAndDelete();

        $this->assertNotSoftDeleted('users', ['id' => $other->id, 'email' => $other->email]);
        $this->assertNotSoftDeleted('member_profiles', ['id' => $otherProfile->id, 'name' => $otherProfile->name]);
        $this->assertSame(1, $otherProfile->weekDays()->count());
        $this->assertSame(1, $otherProfile->socialPlatforms()->count());
        $this->assertSame(1, $otherProfile->portfolios()->count());
    }
}
