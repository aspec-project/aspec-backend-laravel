<?php

namespace Tests\Feature\Migrations;

use App\Models\Location;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Migração que troca o texto livre commercial_contacts por commercial_email + commercial_phone.
 *
 * Os dados antigos são descartados no up() (não há produção e extrair contactos de texto livre
 * seria adivinhar); o down() devolve a estrutura antiga com email e telefone juntos por "\n".
 */
class ReplaceCommercialContactsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_20_000000_replace_commercial_contacts_on_member_profiles_table.php';

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }

    /**
     * Insere pela query builder (não pelo modelo) para o teste não depender do $fillable.
     */
    private function insertProfile(?string $email, ?string $phone, bool $trashed = false): string
    {
        $id = (string) Str::uuid();

        DB::table('member_profiles')->insert([
            'id' => $id,
            'user_id' => User::factory()->create()->id,
            'sector_id' => Sector::query()->value('id'),
            'location_id' => Location::query()->value('id'),
            'name' => 'Membro Migracao',
            'business_name' => 'Empresa Migracao',
            'congregation' => 'Congregacao',
            'role_in_congregation' => 'Membro',
            'commercial_email' => $email,
            'commercial_phone' => $phone,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $trashed ? now() : null,
        ]);

        return $id;
    }

    private function commercialContactsOf(string $id): ?string
    {
        return DB::table('member_profiles')->where('id', $id)->value('commercial_contacts');
    }

    #[Test]
    public function up_adds_commercial_email_and_phone_and_drops_commercial_contacts(): void
    {
        $this->assertTrue(Schema::hasColumns('member_profiles', ['commercial_email', 'commercial_phone']));
        $this->assertFalse(Schema::hasColumn('member_profiles', 'commercial_contacts'));
    }

    #[Test]
    public function down_joins_email_and_phone_into_commercial_contacts(): void
    {
        $both = $this->insertProfile('geral@silva.pt', '+351213456789');
        $emailOnly = $this->insertProfile('geral@so-email.pt', null);
        $phoneOnly = $this->insertProfile(null, '+351912345678');
        $none = $this->insertProfile(null, null);
        $trashed = $this->insertProfile('apagado@silva.pt', '+351221234567', trashed: true);

        $this->migration()->down();

        $this->assertTrue(Schema::hasColumn('member_profiles', 'commercial_contacts'));
        $this->assertFalse(Schema::hasColumn('member_profiles', 'commercial_email'));
        $this->assertFalse(Schema::hasColumn('member_profiles', 'commercial_phone'));

        $this->assertSame("geral@silva.pt\n+351213456789", $this->commercialContactsOf($both));
        $this->assertSame('geral@so-email.pt', $this->commercialContactsOf($emailOnly));
        $this->assertSame('+351912345678', $this->commercialContactsOf($phoneOnly));
        $this->assertNull($this->commercialContactsOf($none));
        $this->assertSame("apagado@silva.pt\n+351221234567", $this->commercialContactsOf($trashed));
    }

    #[Test]
    public function down_followed_by_up_restores_the_new_columns(): void
    {
        $this->insertProfile('geral@silva.pt', '+351213456789');
        $migration = $this->migration();

        $migration->down();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('member_profiles', ['commercial_email', 'commercial_phone']));
        $this->assertFalse(Schema::hasColumn('member_profiles', 'commercial_contacts'));
    }
}
