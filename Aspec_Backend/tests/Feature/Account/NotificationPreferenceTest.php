<?php

namespace Tests\Feature\Account;

use App\Enums\InactiveReason;
use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Preferências dos emails de eventos (anúncio de novos eventos e agenda semanal).
 * São opt-out: comunicações de serviço da associação aos membros, ligadas por omissão.
 */
class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/account/notification-preferences';

    private function activeMember(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        MemberProfile::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    /**
     * @return array{notify_new_events: bool, notify_weekly_agenda: bool}
     */
    private function storedPreferences(User $user): array
    {
        $row = DB::table('users')->where('id', $user->id)->first(['notify_new_events', 'notify_weekly_agenda']);

        return [
            'notify_new_events' => (bool) $row->notify_new_events,
            'notify_weekly_agenda' => (bool) $row->notify_weekly_agenda,
        ];
    }

    #[Test]
    public function new_user_gets_both_preferences_enabled_with_exact_contract(): void
    {
        Sanctum::actingAs($this->activeMember());

        $this->getJson(self::URL)
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Preferências de notificação obtidas com sucesso.',
                'data' => [
                    'notify_new_events' => true,
                    'notify_weekly_agenda' => true,
                ],
            ]);
    }

    #[Test]
    public function update_stores_and_returns_the_new_preferences(): void
    {
        $user = $this->activeMember();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['notify_new_events' => false, 'notify_weekly_agenda' => true])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Preferências de notificação atualizadas com sucesso.',
                'data' => [
                    'notify_new_events' => false,
                    'notify_weekly_agenda' => true,
                ],
            ]);

        $this->assertSame(
            ['notify_new_events' => false, 'notify_weekly_agenda' => true],
            $this->storedPreferences($user)
        );
    }

    #[Test]
    public function get_after_update_reflects_the_change(): void
    {
        Sanctum::actingAs($this->activeMember());

        $this->putJson(self::URL, ['notify_new_events' => false, 'notify_weekly_agenda' => false])->assertOk();

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.notify_new_events', false)
            ->assertJsonPath('data.notify_weekly_agenda', false);
    }

    #[Test]
    public function preferences_can_be_turned_back_on(): void
    {
        $user = $this->activeMember(['notify_new_events' => false, 'notify_weekly_agenda' => false]);
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['notify_new_events' => true, 'notify_weekly_agenda' => true])
            ->assertOk()
            ->assertJsonPath('data.notify_new_events', true)
            ->assertJsonPath('data.notify_weekly_agenda', true);

        $this->assertSame(
            ['notify_new_events' => true, 'notify_weekly_agenda' => true],
            $this->storedPreferences($user)
        );
    }

    #[Test]
    public function boolean_like_values_are_accepted(): void
    {
        $user = $this->activeMember();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['notify_new_events' => 0, 'notify_weekly_agenda' => '1'])->assertOk();

        $this->assertSame(
            ['notify_new_events' => false, 'notify_weekly_agenda' => true],
            $this->storedPreferences($user)
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'notify_new_events em falta' => [['notify_weekly_agenda' => false], 'notify_new_events'],
            'notify_weekly_agenda em falta' => [['notify_new_events' => false], 'notify_weekly_agenda'],
            'notify_new_events nulo' => [['notify_new_events' => null, 'notify_weekly_agenda' => false], 'notify_new_events'],
            'notify_new_events texto' => [['notify_new_events' => 'talvez', 'notify_weekly_agenda' => false], 'notify_new_events'],
            'notify_weekly_agenda texto' => [['notify_new_events' => false, 'notify_weekly_agenda' => 'talvez'], 'notify_weekly_agenda'],
            'notify_new_events array' => [['notify_new_events' => [true], 'notify_weekly_agenda' => false], 'notify_new_events'],
            'notify_weekly_agenda array' => [['notify_new_events' => false, 'notify_weekly_agenda' => [false]], 'notify_weekly_agenda'],
            'notify_new_events número 2' => [['notify_new_events' => 2, 'notify_weekly_agenda' => false], 'notify_new_events'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPayloads')]
    public function invalid_payload_returns_422_and_leaves_preferences_unchanged(array $payload, string $field): void
    {
        $user = $this->activeMember();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, $payload)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors'])
            ->assertJsonValidationErrors([$field]);

        $this->assertSame(
            ['notify_new_events' => true, 'notify_weekly_agenda' => true],
            $this->storedPreferences($user)
        );
    }

    #[Test]
    public function empty_body_reports_both_fields(): void
    {
        Sanctum::actingAs($this->activeMember());

        $this->putJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notify_new_events', 'notify_weekly_agenda']);
    }

    #[Test]
    public function validation_messages_use_portuguese_attribute_names(): void
    {
        Sanctum::actingAs($this->activeMember());

        $response = $this->putJson(self::URL, ['notify_new_events' => 'talvez', 'notify_weekly_agenda' => 'talvez'])
            ->assertUnprocessable();

        $this->assertStringContainsString('anúncio de novos eventos', $response->json('errors.notify_new_events.0'));
        $this->assertStringContainsString('agenda semanal', $response->json('errors.notify_weekly_agenda.0'));
    }

    #[Test]
    public function guest_gets_401_on_get_and_put(): void
    {
        $this->getJson(self::URL)->assertUnauthorized()->assertJsonPath('success', false);
        $this->putJson(self::URL, ['notify_new_events' => false, 'notify_weekly_agenda' => false])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonActiveStates(): array
    {
        return [
            'Pending' => ['pending'],
            'Approved' => ['approved'],
            'Inactive' => ['inactive'],
        ];
    }

    #[Test]
    #[DataProvider('nonActiveStates')]
    public function non_active_account_gets_403_and_preferences_stay_unchanged(string $state): void
    {
        $factory = User::factory();
        $factory = $state === 'inactive' ? $factory->inactive(InactiveReason::Unpaid) : $factory->{$state}();
        $user = $factory->create();
        MemberProfile::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->getJson(self::URL)->assertForbidden()->assertJsonPath('success', false);
        $this->putJson(self::URL, ['notify_new_events' => false, 'notify_weekly_agenda' => false])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertSame(
            ['notify_new_events' => true, 'notify_weekly_agenda' => true],
            $this->storedPreferences($user)
        );
    }

    #[Test]
    public function active_admin_can_manage_own_preferences(): void
    {
        $admin = User::factory()->admin()->create();
        MemberProfile::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        $this->putJson(self::URL, ['notify_new_events' => false, 'notify_weekly_agenda' => true])->assertOk();

        $this->assertSame(
            ['notify_new_events' => false, 'notify_weekly_agenda' => true],
            $this->storedPreferences($admin)
        );
    }

    #[Test]
    public function update_only_changes_the_authenticated_user(): void
    {
        $user = $this->activeMember();
        $other = $this->activeMember();
        Sanctum::actingAs($user);

        $this->putJson(self::URL, [
            'notify_new_events' => false,
            'notify_weekly_agenda' => false,
            'user_id' => $other->id,
        ])->assertOk();

        $this->assertSame(
            ['notify_new_events' => true, 'notify_weekly_agenda' => true],
            $this->storedPreferences($other)
        );
        $this->assertSame(
            ['notify_new_events' => false, 'notify_weekly_agenda' => false],
            $this->storedPreferences($user)
        );
    }

    #[Test]
    public function get_shows_only_the_authenticated_users_preferences(): void
    {
        $this->activeMember(['notify_new_events' => false, 'notify_weekly_agenda' => false]);
        Sanctum::actingAs($this->activeMember());

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.notify_new_events', true)
            ->assertJsonPath('data.notify_weekly_agenda', true);
    }

    #[Test]
    public function users_table_has_the_new_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'notify_new_events',
            'notify_weekly_agenda',
            'weekly_agenda_sent_at',
        ]));
        $this->assertTrue(Schema::hasColumn('event_instances', 'announced_at'));
    }

    #[Test]
    public function rows_inserted_without_the_new_columns_default_to_enabled_and_never_sent(): void
    {
        $existing = User::factory()->create();
        $id = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $id,
            'email' => 'existente@example.test',
            'password' => 'hash',
            'phone' => '+351912345678',
            'role_id' => $existing->role_id,
            'account_status_id' => $existing->account_status_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('users')->where('id', $id)->first();

        $this->assertTrue((bool) $row->notify_new_events);
        $this->assertTrue((bool) $row->notify_weekly_agenda);
        $this->assertNull($row->weekly_agenda_sent_at);
    }
}
