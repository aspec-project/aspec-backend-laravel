<?php

namespace Tests\Feature\Member;

use App\Models\AccountStatus;
use App\Models\MemberProfile;
use App\Models\Role;
use App\Models\SocialPlatform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberProfileUpdateTest extends TestCase
{
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
            'description', 'website_url', 'commercial_contacts', 'address', 'logo_path',
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
            ->assertJsonPath('data.phone', '939999999');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'novo@exemplo.pt', 'phone' => '939999999']);
    }

    #[Test]
    public function nullable_fields_sent_as_null_or_empty_string_become_null(): void
    {
        $user = $this->memberWithProfile(profile: [
            'description' => 'Texto',
            'website_url' => 'https://silva.pt',
            'commercial_contacts' => 'geral@silva.pt',
            'address' => 'Rua Direita 1',
        ]);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, [
            'description' => null,
            'website_url' => '',
            'commercial_contacts' => null,
            'address' => '',
        ])
            ->assertOk()
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.website_url', null);

        $this->assertDatabaseHas('member_profiles', [
            'id' => $user->memberProfile->id,
            'description' => null,
            'website_url' => null,
            'commercial_contacts' => null,
            'address' => null,
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
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Os dados enviados são inválidos.')
            ->assertJsonValidationErrors([
                'name', 'business_name', 'congregation', 'role_in_congregation',
                'sector_id', 'location_id', 'phone',
            ]);

        $this->assertDatabaseHas('member_profiles', [
            'id' => $user->memberProfile->id,
            'name' => 'Ana',
            'business_name' => 'Silva Lda',
        ]);
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
                'password' => 'NewSecret456',
                'password_confirmation' => 'NewSecret456',
            ])->assertUnprocessable()->assertJsonValidationErrors(['current_password']);
        }
        $this->failEmailChange(2);

        $this->putJson(self::URL, ['email' => 'novo@exemplo.pt', 'current_password' => 'password'])
            ->assertTooManyRequests();
        $this->putJson('/api/account/password', [
            'current_password' => 'password',
            'password' => 'NewSecret456',
            'password_confirmation' => 'NewSecret456',
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
    public function update_is_limited_to_ten_requests_per_minute(): void
    {
        Sanctum::actingAs($this->memberWithProfile());

        for ($i = 0; $i < 10; $i++) {
            $this->putJson(self::URL, ['business_name' => "Empresa $i"])->assertOk();
        }

        $this->putJson(self::URL, ['business_name' => 'Empresa 11'])->assertTooManyRequests();
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
}
