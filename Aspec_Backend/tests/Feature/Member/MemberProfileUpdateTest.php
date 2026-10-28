<?php

namespace Tests\Feature\Member;

use App\Models\AccountStatus;
use App\Models\MemberProfile;
use App\Models\Role;
use App\Models\SocialPlatform;
use App\Models\User;
use App\Rules\InternationalPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FillsRateLimits;
use Tests\TestCase;

class MemberProfileUpdateTest extends TestCase
{
    use FillsRateLimits;
    use RefreshDatabase;

    private const URL = '/api/member-profile';

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

    private function platformId(string $name): string
    {
        return SocialPlatform::where('name', $name)->value('id');
    }

    #[Test]
    public function partial_update_changes_only_sent_field(): void
    {
        $user = $this->memberWithProfile(profile: ['business_name' => 'Antiga Lda']);
        $profile = $user->memberProfile;
        $profile->weekDays()->attach(1, ['open_time' => '09:00', 'close_time' => '18:00']);
        $profile->socialPlatforms()->attach($this->platformId('LinkedIn'), ['url' => 'https://linkedin.com/in/a']);
        $before = $profile->fresh()->only([
            'name', 'congregation', 'role_in_congregation', 'sector_id', 'location_id',
            'description', 'website_url', 'commercial_email', 'commercial_phone', 'address', 'logo_path',
        ]);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['business_name' => 'Nova Lda'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Perfil atualizado com sucesso.')
            ->assertJsonPath('data.id', $profile->id)
            ->assertJsonPath('data.business_name', 'Nova Lda')
            ->assertJsonPath('data.name', $before['name'])
            ->assertJsonCount(1, 'data.business_hours')
            ->assertJsonCount(1, 'data.social_links');

        $fresh = $profile->fresh();
        $this->assertSame('Nova Lda', $fresh->business_name);
        foreach ($before as $field => $value) {
            $this->assertSame($value, $fresh->{$field}, "Campo {$field} foi alterado.");
        }
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email, 'phone' => $user->phone]);
    }

    #[Test]
    public function account_fields_email_and_phone_are_updated_on_users_table(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, [
            'email' => 'novo@exemplo.pt',
            'current_password' => 'password',
            'phone' => '939999999',
        ])
            ->assertOk()
            ->assertJsonPath('data.email', 'novo@exemplo.pt')
            ->assertJsonPath('data.phone', '+351939999999');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'novo@exemplo.pt', 'phone' => '+351939999999']);
    }

    #[Test]
    public function nullable_fields_sent_as_null_or_empty_string_become_null(): void
    {
        $user = $this->memberWithProfile(profile: [
            'description' => 'Texto',
            'website_url' => 'https://silva.pt',
        ]);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, [
            'description' => null,
            'website_url' => '',
        ])
            ->assertOk()
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.website_url', null);

        $this->assertDatabaseHas('member_profiles', [
            'id' => $user->memberProfile->id,
            'description' => null,
            'website_url' => null,
        ]);
    }

    #[Test]
    public function required_fields_sent_empty_are_rejected(): void
    {
        $user = $this->memberWithProfile(profile: ['name' => 'Ana', 'business_name' => 'Silva Lda']);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, [
            'name' => '',
            'business_name' => null,
            'congregation' => '',
            'role_in_congregation' => null,
            'sector_id' => null,
            'location_id' => '',
            'phone' => '',
            'address' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Os dados enviados são inválidos.')
            ->assertJsonValidationErrors([
                'name', 'business_name', 'congregation', 'role_in_congregation',
                'sector_id', 'location_id', 'phone', 'address',
            ]);

        $this->assertDatabaseHas('member_profiles', [
            'id' => $user->memberProfile->id,
            'name' => 'Ana',
            'business_name' => 'Silva Lda',
        ]);
    }

    #[Test]
    public function address_sent_as_null_is_rejected_and_kept(): void
    {
        $user = $this->memberWithProfile(profile: ['address' => 'Rua Direita 1']);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['address' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['address' => 'O campo morada é obrigatório.']);

        $this->assertDatabaseHas('member_profiles', ['id' => $user->memberProfile->id, 'address' => 'Rua Direita 1']);
    }

    private const INVALID_PHONE_MESSAGE = 'O campo telefone tem de ser um número de telefone válido. Números estrangeiros precisam do indicativo do país (ex.: +44).';

    public static function invalidPhones(): array
    {
        return [
            'prefixo português não atribuído' => ['941234567'],
            'estrangeiro sem indicativo' => ['7911123456'],
            'curto' => ['12345'],
            'indicativo inexistente' => ['+999123456789'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPhones')]
    public function invalid_phone_is_rejected_and_kept(string $phone): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['phone' => $phone])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['phone' => self::INVALID_PHONE_MESSAGE]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'phone' => $user->phone]);
    }

    public static function maliciousPhones(): array
    {
        return [
            'html' => ['<b>912345678</b>'],
            'texto antes' => ['Tel: 912345678'],
            'extensão' => ['912345678 ext. 12'],
            'fórmula' => ['=912345678'],
            'script' => ['912345678<script>alert(1)</script>'],
        ];
    }

    #[Test]
    #[DataProvider('maliciousPhones')]
    public function phone_with_text_around_the_number_is_rejected_and_kept(string $phone): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['phone' => $phone])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => self::INVALID_PHONE_MESSAGE]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'phone' => $user->phone]);
    }

    #[Test]
    public function phone_sent_as_integer_is_rejected(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['phone' => 912345678])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'phone' => $user->phone]);
    }

    #[Test]
    public function phone_country_field_is_ignored(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['phone' => '7911123456', 'phone_country' => 'GB'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => self::INVALID_PHONE_MESSAGE]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'phone' => $user->phone]);
    }

    #[Test]
    public function phone_is_stored_normalised(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['phone' => '+351 912-345 678'])
            ->assertOk()
            ->assertJsonPath('data.phone', '+351912345678');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'phone' => '+351912345678']);
    }

    public static function acceptedPhones(): array
    {
        return [
            'português sem indicativo' => ['912345678', '+351912345678'],
            'português com parênteses e hífenes' => ['(+351) 912-345-678', '+351912345678'],
            'português com 00' => ['00351912345678', '+351912345678'],
            'fixo de Lisboa' => ['212345678', '+351212345678'],
            'Reino Unido' => ['+44 7911 123456', '+447911123456'],
            'Reino Unido com 00' => ['0044 7911 123456', '+447911123456'],
            'França' => ['+33 6 12 34 56 78', '+33612345678'],
            'Brasil' => ['+55 11 91234-5678', '+5511912345678'],
        ];
    }

    #[Test]
    #[DataProvider('acceptedPhones')]
    public function international_phone_is_accepted_and_stored_in_e164(string $input, string $expected): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['phone' => $input])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.phone', $expected);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'phone' => $expected]);
    }

    #[Test]
    public function get_profile_returns_the_phone_in_e164(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['phone' => '912345678'])->assertOk();

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.phone', '+351912345678');
    }

    #[Test]
    public function directory_shows_the_commercial_phone_in_e164_and_never_the_personal_one(): void
    {
        $owner = $this->memberWithProfile();
        Sanctum::actingAs($owner);
        $this->putJson(self::URL, ['phone' => '+44 7911 123456', 'commercial_phone' => '+44 20 7946 0958'])->assertOk();

        Sanctum::actingAs($this->memberWithProfile());

        $this->getJson('/api/members/'.$owner->memberProfile->id)
            ->assertOk()
            ->assertJsonPath('data.members_only.commercial_phone', '+442079460958')
            ->assertJsonMissingPath('data.members_only.phone')
            ->assertDontSee('+447911123456');
    }

    #[Test]
    public function non_existent_sector_and_location_are_rejected(): void
    {
        Sanctum::actingAs($this->memberWithProfile());

        $this->putJson(self::URL, [
            'sector_id' => (string) Str::uuid(),
            'location_id' => 'nao-e-uuid',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sector_id', 'location_id']);
    }

    #[Test]
    public function website_url_with_javascript_scheme_is_rejected(): void
    {
        $user = $this->memberWithProfile(profile: ['website_url' => 'https://silva.pt']);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['website_url' => 'javascript:alert(1)'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['website_url']);

        $this->assertDatabaseHas('member_profiles', ['id' => $user->memberProfile->id, 'website_url' => 'https://silva.pt']);
    }

    #[Test]
    public function website_url_with_ftp_scheme_is_rejected(): void
    {
        Sanctum::actingAs($this->memberWithProfile());

        $this->putJson(self::URL, ['website_url' => 'ftp://silva.pt'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['website_url']);
    }

    #[Test]
    public function business_hours_sync_creates_updates_and_removes_days(): void
    {
        $user = $this->memberWithProfile();
        $profile = $user->memberProfile;
        $profile->weekDays()->attach([
            1 => ['open_time' => '09:00', 'close_time' => '18:00'],
            2 => ['open_time' => '09:00', 'close_time' => '18:00'],
        ]);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, [
            'business_hours' => [
                ['week_day_id' => 1, 'open_time' => '10:00', 'close_time' => '19:30'],
                ['week_day_id' => 3, 'open_time' => '08:00', 'close_time' => '12:00'],
            ],
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data.business_hours')
            ->assertJsonPath('data.business_hours.0', [
                'week_day_id' => 1,
                'week_day' => 'Segunda-feira',
                'open_time' => '10:00',
                'close_time' => '19:30',
            ])
            ->assertJsonPath('data.business_hours.1', [
                'week_day_id' => 3,
                'week_day' => 'Quarta-feira',
                'open_time' => '08:00',
                'close_time' => '12:00',
            ]);

        $this->assertDatabaseCount('business_hours', 2);
        $this->assertDatabaseHas('business_hours', ['profile_id' => $profile->id, 'week_day_id' => 1]);
        $this->assertDatabaseHas('business_hours', ['profile_id' => $profile->id, 'week_day_id' => 3]);
        $this->assertDatabaseMissing('business_hours', ['profile_id' => $profile->id, 'week_day_id' => 2]);
    }

    #[Test]
    public function synced_pivot_rows_have_uuid_ids(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, [
            'business_hours' => [
                ['week_day_id' => 1, 'open_time' => '09:00', 'close_time' => '18:00'],
            ],
            'social_links' => [
                ['platform_id' => $this->platformId('LinkedIn'), 'url' => 'https://linkedin.com/in/ana'],
            ],
        ])->assertOk();

        $hourId = DB::table('business_hours')->where('profile_id', $user->memberProfile->id)->value('id');
        $linkId = DB::table('social_links')->where('profile_id', $user->memberProfile->id)->value('id');
        $this->assertTrue(Str::isUuid($hourId), 'business_hours.id não é UUID.');
        $this->assertTrue(Str::isUuid($linkId), 'social_links.id não é UUID.');
    }

    #[Test]
    public function empty_business_hours_list_removes_all_days(): void
    {
        $user = $this->memberWithProfile();
        $user->memberProfile->weekDays()->attach([
            1 => ['open_time' => '09:00', 'close_time' => '18:00'],
            2 => ['open_time' => '09:00', 'close_time' => '18:00'],
        ]);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['business_hours' => []])
            ->assertOk()
            ->assertJsonPath('data.business_hours', []);

        $this->assertDatabaseMissing('business_hours', ['profile_id' => $user->memberProfile->id]);
    }

    #[Test]
    public function missing_list_keys_leave_business_hours_and_social_links_untouched(): void
    {
        $user = $this->memberWithProfile();
        $profile = $user->memberProfile;
        $profile->weekDays()->attach(1, ['open_time' => '09:00', 'close_time' => '18:00']);
        $profile->socialPlatforms()->attach($this->platformId('Instagram'), ['url' => 'https://instagram.com/ana']);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['name' => 'Ana Silva'])->assertOk();

        $this->assertDatabaseHas('business_hours', ['profile_id' => $profile->id, 'week_day_id' => 1]);
        $this->assertDatabaseHas('social_links', [
            'profile_id' => $profile->id,
            'platform_id' => $this->platformId('Instagram'),
            'url' => 'https://instagram.com/ana',
        ]);
    }

    #[Test]
    public function close_time_equal_to_open_time_is_rejected(): void
    {
        Sanctum::actingAs($this->memberWithProfile());

        $this->putJson(self::URL, [
            'business_hours' => [['week_day_id' => 1, 'open_time' => '09:00', 'close_time' => '09:00']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['business_hours.0.close_time']);
    }

    #[Test]
    public function close_time_before_open_time_is_rejected(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, [
            'business_hours' => [['week_day_id' => 1, 'open_time' => '18:00', 'close_time' => '09:00']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['business_hours.0.close_time']);

        $this->assertDatabaseMissing('business_hours', ['profile_id' => $user->memberProfile->id]);
    }

    #[Test]
    public function business_hours_not_in_hours_and_minutes_format_are_rejected(): void
    {
        Sanctum::actingAs($this->memberWithProfile());

        $this->putJson(self::URL, [
            'business_hours' => [['week_day_id' => 1, 'open_time' => '9h', 'close_time' => '18h']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['business_hours.0.open_time', 'business_hours.0.close_time']);
    }

    #[Test]
    public function repeated_week_day_is_rejected(): void
    {
        Sanctum::actingAs($this->memberWithProfile());

        $this->putJson(self::URL, [
            'business_hours' => [
                ['week_day_id' => 2, 'open_time' => '09:00', 'close_time' => '12:00'],
                ['week_day_id' => 2, 'open_time' => '14:00', 'close_time' => '18:00'],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['business_hours.0.week_day_id', 'business_hours.1.week_day_id']);
    }

    #[Test]
    public function week_day_outside_one_to_seven_is_rejected(): void
    {
        Sanctum::actingAs($this->memberWithProfile());

        $this->putJson(self::URL, [
            'business_hours' => [['week_day_id' => 8, 'open_time' => '09:00', 'close_time' => '18:00']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['business_hours.0.week_day_id']);
    }

    #[Test]
    public function social_links_sync_creates_updates_and_removes_platforms(): void
    {
        $user = $this->memberWithProfile();
        $profile = $user->memberProfile;
        $profile->socialPlatforms()->attach([
            $this->platformId('LinkedIn') => ['url' => 'https://linkedin.com/in/antigo'],
            $this->platformId('Instagram') => ['url' => 'https://instagram.com/ana'],
        ]);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, [
            'social_links' => [
                ['platform_id' => $this->platformId('LinkedIn'), 'url' => 'https://linkedin.com/in/novo'],
                ['platform_id' => $this->platformId('Facebook'), 'url' => 'https://facebook.com/ana'],
            ],
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data.social_links');

        $this->assertDatabaseCount('social_links', 2);
        $this->assertDatabaseHas('social_links', [
            'profile_id' => $profile->id,
            'platform_id' => $this->platformId('LinkedIn'),
            'url' => 'https://linkedin.com/in/novo',
        ]);
        $this->assertDatabaseHas('social_links', [
            'profile_id' => $profile->id,
            'platform_id' => $this->platformId('Facebook'),
            'url' => 'https://facebook.com/ana',
        ]);
        $this->assertDatabaseMissing('social_links', [
            'profile_id' => $profile->id,
            'platform_id' => $this->platformId('Instagram'),
        ]);
    }

    #[Test]
    public function repeated_social_platform_is_rejected(): void
    {
        Sanctum::actingAs($this->memberWithProfile());
        $linkedin = $this->platformId('LinkedIn');

        $this->putJson(self::URL, [
            'social_links' => [
                ['platform_id' => $linkedin, 'url' => 'https://linkedin.com/in/a'],
                ['platform_id' => $linkedin, 'url' => 'https://linkedin.com/in/b'],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['social_links.0.platform_id', 'social_links.1.platform_id']);
    }

    #[Test]
    public function non_existent_social_platform_is_rejected(): void
    {
        Sanctum::actingAs($this->memberWithProfile());

        $this->putJson(self::URL, [
            'social_links' => [['platform_id' => (string) Str::uuid(), 'url' => 'https://exemplo.pt']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['social_links.0.platform_id']);
    }

    #[Test]
    public function social_link_url_must_be_http_or_https(): void
    {
        Sanctum::actingAs($this->memberWithProfile());

        $this->putJson(self::URL, [
            'social_links' => [['platform_id' => $this->platformId('LinkedIn'), 'url' => 'javascript:alert(1)']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['social_links.0.url']);
    }

    #[Test]
    public function email_change_without_current_password_is_rejected(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['email' => 'novo@exemplo.pt'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
    }

    #[Test]
    public function email_change_with_wrong_current_password_is_rejected(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => 'errada123'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.current_password.0', 'A password atual está incorreta.');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
    }

    #[Test]
    public function email_already_used_by_another_user_is_rejected(): void
    {
        $other = $this->makeUser('Member', 'Active');
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['email' => $other->email, 'current_password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
    }

    #[Test]
    public function own_current_email_is_accepted(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['email' => $user->email, 'current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    #[Test]
    public function invalid_email_format_is_rejected(): void
    {
        Sanctum::actingAs($this->memberWithProfile());

        $this->putJson(self::URL, ['email' => 'nao-e-email', 'current_password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function protected_account_and_profile_fields_are_ignored(): void
    {
        $user = $this->memberWithProfile(profile: ['logo_path' => null]);
        $profile = $user->memberProfile;
        $other = $this->makeUser('Member', 'Active');
        Sanctum::actingAs($user);

        $this->putJson(self::URL, [
            'business_name' => 'Nova Lda',
            'role_id' => Role::where('name', 'Admin')->value('id'),
            'account_status_id' => AccountStatus::where('name', 'Pending')->value('id'),
            'trial_ends_at' => '2099-01-01 00:00:00',
            'password' => 'nova-password-123',
            'user_id' => $other->id,
            'logo_path' => 'logos/hack.png',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(Role::where('name', 'Member')->value('id'), $fresh->role_id);
        $this->assertSame(AccountStatus::where('name', 'Active')->value('id'), $fresh->account_status_id);
        $this->assertNull($fresh->trial_ends_at);
        $this->assertTrue(Hash::check('password', $fresh->password));
        $this->assertDatabaseHas('member_profiles', [
            'id' => $profile->id,
            'user_id' => $user->id,
            'logo_path' => null,
            'business_name' => 'Nova Lda',
        ]);
    }

    #[Test]
    public function email_change_revokes_other_tokens_and_keeps_current(): void
    {
        $user = $this->memberWithProfile();
        $current = $user->createToken('current');
        $other = $user->createToken('other');

        $this->withToken($current->plainTextToken)
            ->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => 'password'])
            ->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
    }

    #[Test]
    public function update_without_email_change_keeps_all_tokens(): void
    {
        $user = $this->memberWithProfile();
        $current = $user->createToken('current');
        $user->createToken('other');

        $this->withToken($current->plainTextToken)
            ->putJson(self::URL, ['email' => $user->email, 'current_password' => 'password', 'phone' => '919999999'])
            ->assertOk();

        $this->assertSame(2, $user->tokens()->count());
    }

    #[Test]
    public function same_platform_with_different_case_is_rejected(): void
    {
        Sanctum::actingAs($this->memberWithProfile());
        $linkedin = $this->platformId('LinkedIn');

        $this->putJson(self::URL, [
            'social_links' => [
                ['platform_id' => $linkedin, 'url' => 'https://linkedin.com/in/a'],
                ['platform_id' => strtoupper($linkedin), 'url' => 'https://linkedin.com/in/b'],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['social_links.0.platform_id', 'social_links.1.platform_id']);
    }

    // Bloqueio de falhas do current_password (decisão 26), partilhado com PUT /api/account/password

    private function failEmailChange(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => "errada{$i}xx"])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['current_password']);
        }
    }

    #[Test]
    public function email_change_is_blocked_after_five_wrong_current_passwords(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->failEmailChange(5);

        $this->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => 'password'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertExactJson(['success' => false, 'message' => 'Demasiados pedidos. Tente novamente mais tarde.']);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
    }

    #[Test]
    public function four_wrong_current_passwords_do_not_block_email_change(): void
    {
        Sanctum::actingAs($this->memberWithProfile());

        $this->failEmailChange(4);

        $this->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => 'password'])->assertOk();
    }

    #[Test]
    public function successful_email_change_clears_the_failure_counter(): void
    {
        Sanctum::actingAs($this->memberWithProfile());

        $this->failEmailChange(4);
        $this->putJson(self::URL, ['email' => 'primeiro@exemplo.pt', 'current_password' => 'password'])->assertOk();

        $this->travel(61)->seconds();
        $this->failEmailChange(4);

        $this->putJson(self::URL, ['email' => 'segundo@exemplo.pt', 'current_password' => 'password'])->assertOk();
    }

    #[Test]
    public function email_change_errors_other_than_current_password_do_not_count(): void
    {
        $other = $this->makeUser('Member', 'Active');
        Sanctum::actingAs($this->memberWithProfile());

        for ($i = 0; $i < 5; $i++) {
            $this->putJson(self::URL, ['email' => $other->email, 'current_password' => 'password'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['email'])
                ->assertJsonMissingValidationErrors(['current_password']);
        }

        $this->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => 'password'])->assertOk();
    }

    #[Test]
    public function failure_counter_is_shared_with_password_change(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 3; $i++) {
            $this->putJson('/api/account/password', [
                'current_password' => "errada{$i}xx",
                'password' => 'NewSecret456!',
                'password_confirmation' => 'NewSecret456!',
            ])->assertUnprocessable()->assertJsonValidationErrors(['current_password']);
        }
        $this->failEmailChange(2);

        $this->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => 'password'])
            ->assertTooManyRequests();
        $this->putJson('/api/account/password', [
            'current_password' => 'password',
            'password' => 'NewSecret456!',
            'password_confirmation' => 'NewSecret456!',
        ])->assertTooManyRequests();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        $this->assertTrue(Hash::check('password', User::whereKey($user->id)->value('password')));
    }

    #[Test]
    public function email_change_block_expires_after_fifteen_minutes(): void
    {
        Sanctum::actingAs($this->memberWithProfile());

        $this->failEmailChange(5);
        $this->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => 'password'])->assertTooManyRequests();

        $this->travel(16)->minutes();

        $this->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => 'password'])->assertOk();
    }

    #[Test]
    public function array_current_password_on_email_change_is_rejected_and_not_counted(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 5; $i++) {
            $this->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => ["errada{$i}xx"]])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['current_password']);
        }

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        $this->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => 'password'])->assertOk();
    }

    public static function emptyCurrentPasswords(): array
    {
        return [
            'empty string' => [''],
            'null' => [null],
        ];
    }

    #[Test]
    #[DataProvider('emptyCurrentPasswords')]
    public function empty_current_password_without_email_does_not_clear_the_counter(?string $empty): void
    {
        Sanctum::actingAs($this->memberWithProfile());

        $this->failEmailChange(4);

        $this->assertLessThan(500, $this->putJson(self::URL, ['current_password' => $empty])->status());

        // Se o pedido vazio contar como falha, este já vem bloqueado; o que não pode é o contador ter sido limpo.
        $this->assertContains(
            $this->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => 'errada9xx'])->status(),
            [422, 429]
        );

        $this->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => 'password'])
            ->assertTooManyRequests();
    }

    // Assunção (release.md ambíguo): o bloqueio só se aplica a pedidos que tentam mudar o email.
    #[Test]
    public function profile_update_without_email_is_not_affected_by_block(): void
    {
        $user = $this->memberWithProfile(profile: ['business_name' => 'Antiga Lda']);
        Sanctum::actingAs($user);

        $this->failEmailChange(5);

        $this->putJson(self::URL, ['business_name' => 'Nova Lda'])
            ->assertOk()
            ->assertJsonPath('data.business_name', 'Nova Lda');
    }

    #[Test]
    public function update_is_limited_per_minute(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);
        $this->fillRateLimit('profile-update', 'minute:'.$user->id, 119);

        $this->putJson(self::URL, ['business_name' => 'Empresa 120'])->assertOk();
        $this->putJson(self::URL, ['business_name' => 'Empresa 121'])->assertTooManyRequests();
    }

    #[Test]
    public function patch_is_not_allowed(): void
    {
        Sanctum::actingAs($this->memberWithProfile());

        $this->patchJson(self::URL, ['business_name' => 'Nova Lda'])->assertMethodNotAllowed();
    }

    #[Test]
    public function unauthenticated_request_is_rejected(): void
    {
        $this->putJson(self::URL, ['business_name' => 'Nova Lda'])
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Não autenticado.']);
    }

    #[Test]
    public function pending_member_cannot_update_profile(): void
    {
        $user = $this->memberWithProfile('Pending', ['business_name' => 'Antiga Lda']);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['business_name' => 'Nova Lda'])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'A conta não está ativa e não pode editar dados.');

        $this->assertDatabaseHas('member_profiles', ['id' => $user->memberProfile->id, 'business_name' => 'Antiga Lda']);
    }

    #[Test]
    public function inactive_member_cannot_update_profile(): void
    {
        $user = $this->memberWithProfile('Inactive', ['business_name' => 'Antiga Lda']);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['business_name' => 'Nova Lda'])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('member_profiles', ['id' => $user->memberProfile->id, 'business_name' => 'Antiga Lda']);
    }

    #[Test]
    public function admin_without_profile_gets_not_found(): void
    {
        Sanctum::actingAs($this->makeUser('Admin', 'Active'));

        $this->putJson(self::URL, ['business_name' => 'Nova Lda'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Perfil de membro não encontrado.']);
    }

    private function createPasswordResetToken(User $user): void
    {
        Password::broker()->createToken($user);
    }

    #[Test]
    public function email_change_deletes_password_reset_tokens_of_the_old_email(): void
    {
        $user = $this->memberWithProfile();
        $oldEmail = $user->email;
        $this->createPasswordResetToken($user);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => 'password'])
            ->assertOk();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    #[Test]
    public function email_change_keeps_password_reset_tokens_of_other_users(): void
    {
        $user = $this->memberWithProfile();
        $other = $this->memberWithProfile();
        $this->createPasswordResetToken($user);
        $this->createPasswordResetToken($other);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => 'password'])
            ->assertOk();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $other->email]);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    #[Test]
    public function update_without_email_change_keeps_password_reset_tokens(): void
    {
        $user = $this->memberWithProfile();
        $this->createPasswordResetToken($user);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['phone' => '919999999'])->assertOk();
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);

        $this->putJson(self::URL, ['email' => $user->email, 'current_password' => 'password'])->assertOk();
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    #[Test]
    public function failed_email_change_keeps_password_reset_tokens(): void
    {
        $user = $this->memberWithProfile();
        $this->createPasswordResetToken($user);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => 'errada123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
    }

    private const COMMERCIAL_PHONE_MESSAGE = 'O campo telefone comercial tem de ser um número de telefone válido. Números estrangeiros precisam do indicativo do país (ex.: +44).';

    private function memberWithCommercialContacts(string $status = 'Active'): User
    {
        return $this->memberWithProfile($status, [
            'commercial_email' => 'antigo@silva.pt',
            'commercial_phone' => '+351213000000',
        ]);
    }

    private function assertCommercialContactsUnchanged(User $user): void
    {
        $this->assertDatabaseHas('member_profiles', [
            'id' => $user->memberProfile->id,
            'commercial_email' => 'antigo@silva.pt',
            'commercial_phone' => '+351213000000',
        ]);
    }

    #[Test]
    public function commercial_email_and_phone_are_saved_and_phone_is_stored_in_e164(): void
    {
        $user = $this->memberWithCommercialContacts();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, [
            'commercial_email' => 'geral@silva.pt',
            'commercial_phone' => '213 456 789',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Perfil atualizado com sucesso.')
            ->assertJsonPath('data.commercial_email', 'geral@silva.pt')
            ->assertJsonPath('data.commercial_phone', '+351213456789');

        $this->assertDatabaseHas('member_profiles', [
            'id' => $user->memberProfile->id,
            'commercial_email' => 'geral@silva.pt',
            'commercial_phone' => '+351213456789',
        ]);
    }

    #[Test]
    public function update_without_commercial_fields_keeps_them_unchanged(): void
    {
        $user = $this->memberWithCommercialContacts();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['name' => 'Novo Nome'])
            ->assertOk()
            ->assertJsonPath('data.commercial_email', 'antigo@silva.pt')
            ->assertJsonPath('data.commercial_phone', '+351213000000');

        $this->assertCommercialContactsUnchanged($user);
    }

    /**
     * @return array<string, array{string, ?string, string}>
     */
    public static function emptyCommercialFields(): array
    {
        return [
            'email vazio' => ['commercial_email', '', 'O campo email comercial é obrigatório.'],
            'email null' => ['commercial_email', null, 'O campo email comercial é obrigatório.'],
            'telefone vazio' => ['commercial_phone', '', 'O campo telefone comercial é obrigatório.'],
            'telefone null' => ['commercial_phone', null, 'O campo telefone comercial é obrigatório.'],
        ];
    }

    #[Test]
    #[DataProvider('emptyCommercialFields')]
    public function commercial_field_sent_empty_is_rejected_and_kept(string $field, ?string $value, string $message): void
    {
        $user = $this->memberWithCommercialContacts();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, [$field => $value])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors([$field => $message]);

        $this->assertCommercialContactsUnchanged($user);
    }

    #[Test]
    public function both_commercial_fields_sent_empty_return_both_errors(): void
    {
        Sanctum::actingAs($this->memberWithCommercialContacts());

        $this->putJson(self::URL, ['commercial_email' => '', 'commercial_phone' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'commercial_email' => 'O campo email comercial é obrigatório.',
                'commercial_phone' => 'O campo telefone comercial é obrigatório.',
            ]);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidCommercialEmails(): array
    {
        $longDomain = implode('.', array_fill(0, 4, str_repeat('a', 60)));

        return [
            'sem arroba' => ['sem-arroba'],
            'sem domínio' => ['a@'],
            '256 caracteres' => ['geral@'.$longDomain.'.abc.pt'],
            'array' => [['geral@silva.pt']],
        ];
    }

    #[Test]
    #[DataProvider('invalidCommercialEmails')]
    public function invalid_commercial_email_is_rejected_and_kept(mixed $email): void
    {
        $user = $this->memberWithCommercialContacts();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['commercial_email' => $email])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['commercial_email']);

        $this->assertCommercialContactsUnchanged($user);
    }

    #[Test]
    public function commercial_email_without_at_sign_has_the_email_message(): void
    {
        Sanctum::actingAs($this->memberWithCommercialContacts());

        $this->putJson(self::URL, ['commercial_email' => 'sem-arroba'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['commercial_email' => 'O campo email comercial tem de ser um endereço de email válido.']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCommercialPhones(): array
    {
        return [
            'prefixo português inexistente' => ['941234567'],
            'estrangeiro sem indicativo' => ['7911123456'],
            'letras' => ['abc'],
            'texto à volta do número' => ['Tel: 912345678'],
        ];
    }

    #[Test]
    #[DataProvider('invalidCommercialPhones')]
    public function invalid_commercial_phone_is_rejected_and_kept(string $phone): void
    {
        $user = $this->memberWithCommercialContacts();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['commercial_phone' => $phone])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['commercial_phone' => self::COMMERCIAL_PHONE_MESSAGE]);

        $this->assertCommercialContactsUnchanged($user);
    }

    #[Test]
    public function foreign_commercial_phone_with_country_code_is_stored_in_e164(): void
    {
        $user = $this->memberWithCommercialContacts();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['commercial_phone' => '+44 7911 123456'])
            ->assertOk()
            ->assertJsonPath('data.commercial_phone', '+447911123456');

        $this->assertDatabaseHas('member_profiles', ['id' => $user->memberProfile->id, 'commercial_phone' => '+447911123456']);
    }

    #[Test]
    public function commercial_contacts_may_repeat_the_personal_account_contacts(): void
    {
        $user = $this->memberWithCommercialContacts();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['commercial_email' => $user->email, 'commercial_phone' => $user->phone])
            ->assertOk()
            ->assertJsonPath('data.commercial_email', $user->email)
            ->assertJsonPath('data.commercial_phone', InternationalPhone::normalize($user->phone));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email, 'phone' => $user->phone]);
    }

    #[Test]
    public function commercial_email_shared_with_another_member_is_accepted(): void
    {
        $this->memberWithProfile(profile: ['commercial_email' => 'geral@empresa-partilhada.pt']);
        $user = $this->memberWithCommercialContacts();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['commercial_email' => 'geral@empresa-partilhada.pt'])->assertOk();

        $this->assertSame(2, MemberProfile::where('commercial_email', 'geral@empresa-partilhada.pt')->count());
    }

    #[Test]
    public function legacy_commercial_contacts_field_is_ignored(): void
    {
        $user = $this->memberWithCommercialContacts();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['business_name' => 'Nova Lda', 'commercial_contacts' => 'geral@silva.pt'])
            ->assertOk()
            ->assertJsonPath('data.business_name', 'Nova Lda')
            ->assertJsonMissingPath('data.commercial_contacts');

        $this->assertCommercialContactsUnchanged($user);
    }

    #[Test]
    public function commercial_email_change_needs_no_current_password_and_keeps_tokens_and_login_email(): void
    {
        $user = $this->memberWithCommercialContacts();
        $current = $user->createToken('current');
        $user->createToken('other');

        $this->withToken($current->plainTextToken)
            ->putJson(self::URL, ['commercial_email' => 'novo.comercial@silva.pt'])
            ->assertOk()
            ->assertJsonPath('data.commercial_email', 'novo.comercial@silva.pt')
            ->assertJsonPath('data.email', $user->email);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        $this->assertSame(2, $user->tokens()->count());
    }

    #[Test]
    public function changing_the_account_phone_keeps_the_commercial_phone(): void
    {
        $user = $this->memberWithCommercialContacts();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['phone' => '939999999'])->assertOk();

        $this->assertStringEndsWith('939999999', $user->fresh()->phone);
        $this->assertCommercialContactsUnchanged($user);
    }

    #[Test]
    public function changing_the_commercial_phone_keeps_the_account_phone(): void
    {
        $user = $this->memberWithCommercialContacts();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['commercial_phone' => '+351229999999'])
            ->assertOk()
            ->assertJsonPath('data.commercial_phone', '+351229999999')
            ->assertJsonPath('data.phone', $user->phone);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'phone' => $user->phone]);
    }

    #[Test]
    public function legacy_profile_without_commercial_contacts_can_update_other_fields(): void
    {
        $user = $this->memberWithProfile(profile: ['commercial_email' => null, 'commercial_phone' => null]);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['description' => 'Nova descrição.'])
            ->assertOk()
            ->assertJsonPath('data.description', 'Nova descrição.')
            ->assertJsonPath('data.commercial_email', null)
            ->assertJsonPath('data.commercial_phone', null);

        $this->assertDatabaseHas('member_profiles', [
            'id' => $user->memberProfile->id,
            'description' => 'Nova descrição.',
            'commercial_email' => null,
            'commercial_phone' => null,
        ]);
    }

    #[Test]
    public function inactive_member_cannot_change_commercial_contacts(): void
    {
        $user = $this->memberWithCommercialContacts('Inactive');
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['commercial_email' => 'novo@silva.pt', 'commercial_phone' => '+351229999999'])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertCommercialContactsUnchanged($user);
    }

    private const POSTAL_CODE_FORMAT_MESSAGE = 'O campo código postal tem de ter o formato 0000-000.';

    private function memberWithPostalAddress(string $status = 'Active'): User
    {
        return $this->memberWithProfile($status, [
            'address' => 'Rua Direita 1',
            'postal_code' => '1100-001',
            'city' => 'Lisboa',
        ]);
    }

    private function assertPostalAddressUnchanged(User $user): void
    {
        $this->assertDatabaseHas('member_profiles', [
            'id' => $user->memberProfile->id,
            'postal_code' => '1100-001',
            'city' => 'Lisboa',
        ]);
    }

    #[Test]
    public function postal_code_and_city_are_saved_and_returned(): void
    {
        $user = $this->memberWithPostalAddress();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, [
            'address' => 'Avenida da República 50, 3.º Esq.',
            'postal_code' => '1050-196',
            'city' => 'Lisboa',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Perfil atualizado com sucesso.')
            ->assertJsonPath('data.address', 'Avenida da República 50, 3.º Esq.')
            ->assertJsonPath('data.postal_code', '1050-196')
            ->assertJsonPath('data.city', 'Lisboa');

        $this->assertDatabaseHas('member_profiles', [
            'id' => $user->memberProfile->id,
            'address' => 'Avenida da República 50, 3.º Esq.',
            'postal_code' => '1050-196',
            'city' => 'Lisboa',
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function postalCodesWithoutHyphen(): array
    {
        return [
            'sete dígitos com espaços à volta' => [' 1000100 '],
            'sete dígitos' => ['1000100'],
            'espaço em vez de hífen' => ['1000 100'],
            'newline no fim' => ["1000-100\n"],
        ];
    }

    #[Test]
    #[DataProvider('postalCodesWithoutHyphen')]
    public function postal_code_is_stored_with_hyphen(string $postalCode): void
    {
        $user = $this->memberWithPostalAddress();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['postal_code' => $postalCode])
            ->assertOk()
            ->assertJsonPath('data.postal_code', '1000-100');

        $this->assertDatabaseHas('member_profiles', ['id' => $user->memberProfile->id, 'postal_code' => '1000-100']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidPostalCodes(): array
    {
        return [
            'só 4 dígitos' => ['1000'],
            'sufixo com 2 dígitos' => ['1000-10'],
            'prefixo com 5 dígitos' => ['10001-00'],
            'letras' => ['ABCD-EFG'],
            'oito dígitos' => ['10001000'],
            'estrangeiro' => ['SW1A 1AA'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPostalCodes')]
    public function invalid_postal_code_is_rejected_and_kept(mixed $postalCode): void
    {
        $user = $this->memberWithPostalAddress();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['postal_code' => $postalCode])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Os dados enviados são inválidos.')
            ->assertJsonValidationErrors(['postal_code' => self::POSTAL_CODE_FORMAT_MESSAGE]);

        $this->assertPostalAddressUnchanged($user);
    }

    #[Test]
    public function postal_code_sent_as_integer_is_rejected_and_kept(): void
    {
        $user = $this->memberWithPostalAddress();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['postal_code' => 1000100])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['postal_code']);

        $this->assertPostalAddressUnchanged($user);
    }

    /**
     * @return array<string, array{string, ?string, string}>
     */
    public static function emptyPostalAddressFields(): array
    {
        return [
            'código postal vazio' => ['postal_code', '', 'O campo código postal é obrigatório.'],
            'código postal null' => ['postal_code', null, 'O campo código postal é obrigatório.'],
            'localidade vazia' => ['city', '', 'O campo localidade é obrigatório.'],
            'localidade null' => ['city', null, 'O campo localidade é obrigatório.'],
            'localidade só com espaços' => ['city', '   ', 'O campo localidade é obrigatório.'],
        ];
    }

    #[Test]
    #[DataProvider('emptyPostalAddressFields')]
    public function postal_address_field_sent_empty_is_rejected_and_kept(string $field, ?string $value, string $message): void
    {
        $user = $this->memberWithPostalAddress();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, [$field => $value])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors([$field => $message]);

        $this->assertPostalAddressUnchanged($user);
    }

    #[Test]
    public function city_longer_than_one_hundred_characters_is_rejected_and_kept(): void
    {
        $user = $this->memberWithPostalAddress();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['city' => str_repeat('a', 101)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['city']);

        $this->assertPostalAddressUnchanged($user);
    }

    #[Test]
    public function city_with_one_hundred_characters_is_accepted(): void
    {
        $user = $this->memberWithPostalAddress();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['city' => str_repeat('a', 100)])
            ->assertOk()
            ->assertJsonPath('data.city', str_repeat('a', 100));
    }

    #[Test]
    public function update_without_postal_address_fields_keeps_them_unchanged(): void
    {
        $user = $this->memberWithPostalAddress();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['name' => 'Novo Nome'])
            ->assertOk()
            ->assertJsonPath('data.postal_code', '1100-001')
            ->assertJsonPath('data.city', 'Lisboa');

        $this->assertPostalAddressUnchanged($user);
    }

    #[Test]
    public function legacy_profile_without_postal_code_and_city_can_update_other_fields(): void
    {
        $user = $this->memberWithProfile(profile: ['postal_code' => null, 'city' => null]);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['name' => 'Novo Nome'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Novo Nome')
            ->assertJsonPath('data.postal_code', null)
            ->assertJsonPath('data.city', null);

        $this->assertDatabaseHas('member_profiles', [
            'id' => $user->memberProfile->id,
            'name' => 'Novo Nome',
            'postal_code' => null,
            'city' => null,
        ]);
    }

    #[Test]
    public function inactive_member_cannot_change_postal_code_or_city(): void
    {
        $user = $this->memberWithPostalAddress('Inactive');
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['postal_code' => '4000-001', 'city' => 'Porto'])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertPostalAddressUnchanged($user);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function websitesWithoutScheme(): array
    {
        return [
            'www' => ['www.empresa.pt', 'https://www.empresa.pt'],
            'domínio' => ['empresa.pt', 'https://empresa.pt'],
            'com caminho' => ['empresa.pt/servicos', 'https://empresa.pt/servicos'],
            'com porta' => ['empresa.pt:8080/x', 'https://empresa.pt:8080/x'],
            'espaços à volta' => ['  www.empresa.pt  ', 'https://www.empresa.pt'],
        ];
    }

    #[Test]
    #[DataProvider('websitesWithoutScheme')]
    public function website_without_scheme_is_stored_with_https(string $input, string $expected): void
    {
        $user = $this->memberWithProfile(profile: ['website_url' => 'https://antigo.pt']);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['website_url' => $input])
            ->assertOk()
            ->assertJsonPath('data.website_url', $expected);

        $this->assertDatabaseHas('member_profiles', ['id' => $user->memberProfile->id, 'website_url' => $expected]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function websitesWithHttpScheme(): array
    {
        return [
            'http' => ['http://empresa.pt'],
            'https' => ['https://empresa.pt'],
        ];
    }

    #[Test]
    #[DataProvider('websitesWithHttpScheme')]
    public function website_with_http_or_https_is_stored_unchanged(string $url): void
    {
        $user = $this->memberWithProfile(profile: ['website_url' => 'https://antigo.pt']);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['website_url' => $url])
            ->assertOk()
            ->assertJsonPath('data.website_url', $url);

        $this->assertDatabaseHas('member_profiles', ['id' => $user->memberProfile->id, 'website_url' => $url]);
    }

    #[Test]
    public function website_sent_as_null_is_cleared(): void
    {
        $user = $this->memberWithProfile(profile: ['website_url' => 'https://antigo.pt']);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['website_url' => null])
            ->assertOk()
            ->assertJsonPath('data.website_url', null);

        $this->assertDatabaseHas('member_profiles', ['id' => $user->memberProfile->id, 'website_url' => null]);
    }

    #[Test]
    public function social_link_without_scheme_is_stored_with_https(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $response = $this->putJson(self::URL, [
            'social_links' => [
                ['platform_id' => $this->platformId('LinkedIn'), 'url' => 'linkedin.com/in/x'],
                ['platform_id' => $this->platformId('Instagram'), 'url' => 'http://instagram.com/x'],
            ],
        ])->assertOk();

        $this->assertEqualsCanonicalizing(
            ['https://linkedin.com/in/x', 'http://instagram.com/x'],
            array_column($response->json('data.social_links'), 'url'),
        );
        $this->assertDatabaseHas('social_links', [
            'profile_id' => $user->memberProfile->id,
            'platform_id' => $this->platformId('LinkedIn'),
            'url' => 'https://linkedin.com/in/x',
        ]);
        $this->assertDatabaseHas('social_links', [
            'profile_id' => $user->memberProfile->id,
            'platform_id' => $this->platformId('Instagram'),
            'url' => 'http://instagram.com/x',
        ]);
    }

    /**
     * Esquemas que não são http/https nunca são escondidos atrás de https://, e links com utilizador
     * ou password antes do "@" (mostram um domínio e abrem outro) são recusados.
     *
     * @return array<string, array{string}>
     */
    public static function linksWithForbiddenScheme(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript com maiúsculas' => ['JavaScript:alert(1)'],
            'mailto' => ['mailto:a@b.pt'],
            'ftp' => ['ftp://x.pt'],
            'data' => ['data:text/html,x'],
            'relativo ao protocolo' => ['//x.pt'],
            'espaço no domínio' => ['empresa .pt'],
            'utilizador antes do @ sem esquema' => ['linkedin.com@evil.pt'],
            'utilizador antes do @ com esquema' => ['https://linkedin.com@evil.pt'],
            'utilizador e password antes do @' => ['https://user:pass@evil.pt'],
        ];
    }

    #[Test]
    #[DataProvider('linksWithForbiddenScheme')]
    public function website_with_forbidden_scheme_or_invalid_host_is_rejected_and_kept(string $url): void
    {
        $user = $this->memberWithProfile(profile: ['website_url' => 'https://silva.pt']);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['website_url' => $url])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['website_url']);

        $this->assertDatabaseHas('member_profiles', ['id' => $user->memberProfile->id, 'website_url' => 'https://silva.pt']);
    }

    #[Test]
    #[DataProvider('linksWithForbiddenScheme')]
    public function social_link_with_forbidden_scheme_or_invalid_host_is_rejected_and_kept(string $url): void
    {
        $user = $this->memberWithProfile();
        $user->memberProfile->socialPlatforms()->attach($this->platformId('LinkedIn'), ['url' => 'https://linkedin.com/in/antigo']);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, [
            'social_links' => [['platform_id' => $this->platformId('LinkedIn'), 'url' => $url]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['social_links.0.url']);

        $this->assertDatabaseHas('social_links', [
            'profile_id' => $user->memberProfile->id,
            'url' => 'https://linkedin.com/in/antigo',
        ]);
        $this->assertDatabaseCount('social_links', 1);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function malformedSocialLinks(): array
    {
        return [
            'lista de texto' => [['linkedin.com/in/x'], 'social_links.0.platform_id'],
            'item sem url' => [[['platform_id' => 'PLATFORM']], 'social_links.0.url'],
            'url em array' => [[['platform_id' => 'PLATFORM', 'url' => ['linkedin.com/in/x']]], 'social_links.0.url'],
            'url inteiro' => [[['platform_id' => 'PLATFORM', 'url' => 123]], 'social_links.0.url'],
            'texto em vez de lista' => ['linkedin.com/in/x', 'social_links'],
        ];
    }

    #[Test]
    #[DataProvider('malformedSocialLinks')]
    public function malformed_social_links_are_rejected_with_422_not_500(mixed $socialLinks, string $errorKey): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $platformId = $this->platformId('LinkedIn');
        if (is_array($socialLinks)) {
            array_walk_recursive($socialLinks, function (&$value) use ($platformId) {
                $value = $value === 'PLATFORM' ? $platformId : $value;
            });
        }

        $this->putJson(self::URL, ['social_links' => $socialLinks])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors([$errorKey]);

        $this->assertDatabaseMissing('social_links', ['profile_id' => $user->memberProfile->id]);
    }
}
