<?php

namespace Tests\Feature\Models;

use App\Enums\InactiveReason;
use App\Models\AccountStatus;
use App\Models\MemberProfile;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_COLUMNS = [
        'grace_ends_at',
        'inactive_reason',
        'stripe_checkout_session_id',
        'billing_name',
        'nif',
        'billing_address',
        'billing_postal_code',
        'billing_city',
    ];

    private const NIF = '245678901';

    private function billingValues(): array
    {
        return [
            'billing_name' => 'Silva Contabilidade Lda',
            'nif' => self::NIF,
            'billing_address' => 'Rua da Faturação 10',
            'billing_postal_code' => '1000-001',
            'billing_city' => 'Lisboa',
        ];
    }

    private function activeMemberWithBilling(): User
    {
        $user = User::factory()->create();
        MemberProfile::factory()->create(['user_id' => $user->id]);
        $user->forceFill([
            ...$this->billingValues(),
            'grace_ends_at' => now()->addDays(7),
            'stripe_checkout_session_id' => 'cs_test_secret_session',
        ])->save();

        return $user;
    }

    #[Test]
    public function users_table_has_the_account_state_and_billing_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', self::NEW_COLUMNS));
    }

    #[Test]
    public function new_columns_are_nullable_and_empty_by_default(): void
    {
        $user = User::factory()->create()->fresh();

        foreach (self::NEW_COLUMNS as $column) {
            $this->assertArrayHasKey($column, $user->getAttributes());
            $this->assertNull($user->getAttribute($column), "{$column} devia ser null");
        }
    }

    #[Test]
    public function trial_and_grace_dates_are_cast_to_carbon(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'trial_ends_at' => '2026-11-07 10:00:00',
            'grace_ends_at' => '2026-11-14 10:00:00',
        ])->save();

        $fresh = $user->fresh();
        $this->assertInstanceOf(CarbonInterface::class, $fresh->trial_ends_at);
        $this->assertInstanceOf(CarbonInterface::class, $fresh->grace_ends_at);
    }

    #[Test]
    public function inactive_reason_is_cast_to_the_enum(): void
    {
        $user = User::factory()->inactive()->create();
        $user->forceFill(['inactive_reason' => 'unpaid'])->save();

        $this->assertSame(InactiveReason::Unpaid, $user->fresh()->inactive_reason);
    }

    #[Test]
    public function inactive_reason_enum_has_the_four_reasons(): void
    {
        $this->assertSame('rejected', InactiveReason::Rejected->value);
        $this->assertSame('blocked', InactiveReason::Blocked->value);
        $this->assertSame('unpaid', InactiveReason::Unpaid->value);
        $this->assertSame('deleted', InactiveReason::Deleted->value);
        $this->assertCount(4, InactiveReason::cases());
    }

    #[Test]
    public function payment_state_fields_are_ignored_on_create(): void
    {
        $user = User::create([
            'email' => 'novo@example.com',
            'password' => 'Password1!',
            'phone' => '912345678',
            'role_id' => Role::where('name', 'Member')->value('id'),
            'account_status_id' => AccountStatus::where('name', 'Active')->value('id'),
            'trial_ends_at' => now()->addDays(30),
            'grace_ends_at' => now()->addDays(7),
            'inactive_reason' => 'blocked',
            'stripe_checkout_session_id' => 'cs_test_x',
        ]);

        $fresh = $user->fresh();
        $this->assertNull($fresh->trial_ends_at);
        $this->assertNull($fresh->grace_ends_at);
        $this->assertNull($fresh->inactive_reason);
        $this->assertNull($fresh->stripe_checkout_session_id);
    }

    #[Test]
    public function payment_state_fields_are_ignored_on_update(): void
    {
        $user = User::factory()->create();

        $user->update([
            'trial_ends_at' => now()->addDays(30),
            'grace_ends_at' => now()->addDays(7),
            'inactive_reason' => 'blocked',
            'stripe_checkout_session_id' => 'cs_test_x',
        ]);

        $fresh = $user->fresh();
        $this->assertNull($fresh->trial_ends_at);
        $this->assertNull($fresh->grace_ends_at);
        $this->assertNull($fresh->inactive_reason);
        $this->assertNull($fresh->stripe_checkout_session_id);
    }

    #[Test]
    public function billing_fields_can_be_mass_assigned(): void
    {
        $user = User::factory()->create();

        $user->update(['nif' => '123456789', 'billing_city' => 'Lisboa']);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'nif' => '123456789',
            'billing_city' => 'Lisboa',
        ]);
    }

    #[Test]
    public function account_state_and_billing_fields_are_hidden_from_serialization(): void
    {
        $user = User::factory()->inactive()->create();
        $user->forceFill([
            ...$this->billingValues(),
            'grace_ends_at' => now()->addDays(7),
            'inactive_reason' => 'unpaid',
            'stripe_checkout_session_id' => 'cs_test_secret_session',
        ])->save();
        $fresh = $user->fresh();

        $array = $fresh->toArray();
        foreach (self::NEW_COLUMNS as $column) {
            $this->assertArrayNotHasKey($column, $array);
        }

        $json = $fresh->toJson();
        foreach (self::NEW_COLUMNS as $column) {
            $this->assertStringNotContainsString("\"{$column}\"", $json);
        }
        $this->assertStringNotContainsString(self::NIF, $json);
        $this->assertStringNotContainsString('cs_test_secret_session', $json);
    }

    #[Test]
    public function auth_me_does_not_expose_billing_or_account_state(): void
    {
        $user = $this->activeMemberWithBilling();
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonMissingPath('data.nif')
            ->assertJsonMissingPath('data.billing_address')
            ->assertJsonMissingPath('data.inactive_reason')
            ->assertJsonMissingPath('data.grace_ends_at')
            ->assertDontSee(self::NIF)
            ->assertDontSee('cs_test_secret_session');
    }

    #[Test]
    public function member_profile_does_not_expose_billing_or_account_state(): void
    {
        $user = $this->activeMemberWithBilling();
        Sanctum::actingAs($user);

        $this->getJson('/api/member-profile')
            ->assertOk()
            ->assertJsonMissingPath('data.nif')
            ->assertJsonMissingPath('data.billing_address')
            ->assertJsonMissingPath('data.inactive_reason')
            ->assertJsonMissingPath('data.grace_ends_at')
            ->assertDontSee(self::NIF)
            ->assertDontSee('cs_test_secret_session');
    }

    #[Test]
    public function stripe_customer_fields_are_ignored_on_create(): void
    {
        $user = User::create([
            'email' => 'cliente@example.com',
            'password' => 'Password1!',
            'phone' => '912345678',
            'role_id' => Role::where('name', 'Member')->value('id'),
            'account_status_id' => AccountStatus::where('name', 'Active')->value('id'),
            'stripe_id' => 'cus_x',
            'pm_type' => 'visa',
            'pm_last_four' => '4242',
        ]);

        $fresh = $user->fresh();
        $this->assertNull($fresh->stripe_id);
        $this->assertNull($fresh->pm_type);
        $this->assertNull($fresh->pm_last_four);
    }

    #[Test]
    public function stripe_customer_fields_are_hidden_from_serialization(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['stripe_id' => 'cus_test_hidden', 'pm_type' => 'visa', 'pm_last_four' => '4242'])->save();

        $array = $user->fresh()->toArray();

        $this->assertArrayNotHasKey('stripe_id', $array);
        $this->assertArrayNotHasKey('pm_type', $array);
        $this->assertArrayNotHasKey('pm_last_four', $array);
    }

    #[Test]
    public function auth_me_does_not_expose_the_stripe_customer(): void
    {
        $user = $this->activeMemberWithBilling();
        $user->forceFill(['stripe_id' => 'cus_test_hidden', 'pm_type' => 'visa', 'pm_last_four' => '4242'])->save();
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonMissingPath('data.stripe_id')
            ->assertJsonMissingPath('data.pm_type')
            ->assertJsonMissingPath('data.pm_last_four')
            ->assertDontSee('cus_');
    }

    #[Test]
    public function factory_approved_state_creates_an_approved_account(): void
    {
        $user = User::factory()->approved()->create();

        $this->assertSame(AccountStatus::where('name', 'Approved')->value('id'), $user->account_status_id);
        $this->assertNull($user->fresh()->inactive_reason);
    }

    #[Test]
    public function factory_inactive_state_with_reason_sets_the_reason(): void
    {
        $user = User::factory()->inactive(InactiveReason::Unpaid)->create();

        $fresh = $user->fresh();
        $this->assertSame(AccountStatus::where('name', 'Inactive')->value('id'), $fresh->account_status_id);
        $this->assertSame(InactiveReason::Unpaid, $fresh->inactive_reason);
    }

    #[Test]
    public function factory_inactive_state_without_reason_keeps_reason_null(): void
    {
        $user = User::factory()->inactive()->create();

        $fresh = $user->fresh();
        $this->assertSame(AccountStatus::where('name', 'Inactive')->value('id'), $fresh->account_status_id);
        $this->assertArrayHasKey('inactive_reason', $fresh->getAttributes());
        $this->assertNull($fresh->inactive_reason);
    }


    #[Test]
    public function deactivate_sets_the_requested_reason_and_clears_grace_period(): void
    {
        $user = User::factory()
            ->approved()
            ->create([
                'grace_ends_at' => now()->addDays(7),
            ]);

        $user->deactivate(InactiveReason::Unpaid->value);

        $freshUser = $user->fresh();

        $this->assertSame(
            InactiveReason::Unpaid,
            $freshUser->inactive_reason
        );

        $this->assertNull($freshUser->grace_ends_at);

        $this->assertSame(
            'Inactive',
            $freshUser->accountStatus->name
        );
    }

    #[Test]
    public function deactivate_revokes_tokens_and_deletes_sessions(): void
    {
        $user = User::factory()->create();

        $user->createToken('deactivate-test');

        DB::table(config('session.table'))->insert([
            'id' => 'deactivate-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'deactivate-test',
        ]);

        $this->assertDatabaseHas(config('session.table'), [
            'id' => 'deactivate-session',
            'user_id' => $user->id,
        ]);

        $user->deactivate(InactiveReason::Blocked->value);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'deactivate-test',
        ]);

        $this->assertDatabaseMissing(config('session.table'), [
            'id' => 'deactivate-session',
        ]);
    }

    #[Test]
    public function deactivate_preserves_an_existing_blocked_reason(): void
    {
        $user = User::factory()
            ->inactive(InactiveReason::Blocked)
            ->create();

        $user->deactivate(InactiveReason::Rejected->value);

        $this->assertSame(
            InactiveReason::Blocked,
            $user->fresh()->inactive_reason
        );
    }

    #[Test]
    public function deactivate_preserves_an_existing_deleted_reason(): void
    {
        $user = User::factory()
            ->inactive(InactiveReason::Deleted)
            ->create();

        $user->deactivate(InactiveReason::Rejected->value);

        $this->assertSame(
            InactiveReason::Deleted,
            $user->fresh()->inactive_reason
        );
    }

    #[Test]
    public function deactivate_rejects_an_invalid_reason(): void
    {
        $user = User::factory()->create();

        $this->expectException(\ValueError::class);

        $user->deactivate('invalid-reason');
    }

    #[Test]
    public function delete_password_reset_tokens_uses_given_email_or_current_email(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Password::broker()->createToken($user);
        Password::broker()->createToken($other);
        DB::table('password_reset_tokens')->insert([
            'email' => 'antigo@exemplo.pt',
            'token' => Hash::make('token'),
            'created_at' => now(),
        ]);

        $user->deletePasswordResetTokens('antigo@exemplo.pt');

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'antigo@exemplo.pt']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);

        $user->deletePasswordResetTokens();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $other->email]);
    }
}
