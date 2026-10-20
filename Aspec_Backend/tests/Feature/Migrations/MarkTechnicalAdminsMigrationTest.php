<?php

namespace Tests\Feature\Migrations;

use App\Models\MemberProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Migração de dados que marca como isentos os administradores técnicos que já existiam antes da
 * coluna users.subscription_exempt: Admin, conta não apagada e sem nenhuma linha em member_profiles
 * (nem apagada), que é exatamente o que o aspec:create-admin criava. Os admins promovidos têm
 * perfil e passam a pagar.
 */
class MarkTechnicalAdminsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_21_000001_mark_existing_technical_admins_as_subscription_exempt.php';

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }

    /**
     * Repõe todas as marcas a false (como antes da migração) e corre o up().
     */
    private function runMigration(): void
    {
        DB::table('users')->update(['subscription_exempt' => false]);

        $this->migration()->up();
    }

    private function isExempt(User $user): bool
    {
        return (bool) DB::table('users')->where('id', $user->id)->value('subscription_exempt');
    }

    #[Test]
    public function users_table_has_the_subscription_exempt_column_defaulting_to_false(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'subscription_exempt'));

        $member = User::factory()->create();

        $this->assertFalse($this->isExempt($member));
    }

    #[Test]
    public function admin_without_member_profile_is_marked_exempt(): void
    {
        $admin = User::factory()->admin()->create();

        $this->runMigration();

        $this->assertTrue($this->isExempt($admin));
    }

    #[Test]
    public function admin_with_member_profile_stays_paying(): void
    {
        $admin = User::factory()->admin()->create();
        MemberProfile::factory()->create(['user_id' => $admin->id]);

        $this->runMigration();

        $this->assertFalse($this->isExempt($admin));
    }

    #[Test]
    public function admin_with_soft_deleted_member_profile_stays_paying(): void
    {
        $admin = User::factory()->admin()->create();
        MemberProfile::factory()->create(['user_id' => $admin->id])->delete();

        $this->runMigration();

        $this->assertFalse($this->isExempt($admin));
    }

    #[Test]
    public function member_without_member_profile_stays_paying(): void
    {
        $member = User::factory()->create();

        $this->runMigration();

        $this->assertFalse($this->isExempt($member));
    }

    #[Test]
    public function soft_deleted_admin_without_member_profile_stays_paying(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->delete();

        $this->runMigration();

        $this->assertFalse($this->isExempt($admin));
    }

    #[Test]
    public function running_the_migration_twice_changes_nothing(): void
    {
        $technicalAdmin = User::factory()->admin()->create();
        $payingAdmin = User::factory()->admin()->create();
        MemberProfile::factory()->create(['user_id' => $payingAdmin->id]);
        $member = User::factory()->create();

        $this->runMigration();
        $this->migration()->up();

        $this->assertTrue($this->isExempt($technicalAdmin));
        $this->assertFalse($this->isExempt($payingAdmin));
        $this->assertFalse($this->isExempt($member));
        $this->assertSame(1, DB::table('users')->where('subscription_exempt', true)->count());
    }

    /**
     * Base de dados sem seed: sem o papel Admin não há ninguém a marcar e a migração não falha.
     */
    #[Test]
    public function database_without_the_admin_role_is_left_unchanged(): void
    {
        $member = User::factory()->create();
        Role::where('name', 'Admin')->delete();

        $this->runMigration();

        $this->assertFalse($this->isExempt($member));
        $this->assertSame(0, DB::table('users')->where('subscription_exempt', true)->count());
    }

    #[Test]
    public function down_does_not_fail_and_keeps_the_marks(): void
    {
        $admin = User::factory()->admin()->create();
        $this->runMigration();

        $this->migration()->down();

        $this->assertTrue($this->isExempt($admin));
    }
}
