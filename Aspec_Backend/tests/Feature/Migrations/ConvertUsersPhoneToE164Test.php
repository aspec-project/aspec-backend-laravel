<?php

namespace Tests\Feature\Migrations;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Migração de dados que converte os telefones portugueses guardados em 9 dígitos (formato da
 * antiga regra só para Portugal) para E.164 com +351.
 */
class ConvertUsersPhoneToE164Test extends TestCase
{
    use RefreshDatabase;

    /**
     * A migração já correu sobre a tabela vazia no RefreshDatabase; aqui volta a correr-se
     * depois de criar as linhas. Procura-se pelo nome porque o timestamp do ficheiro não é fixo.
     */
    private function migration(): Migration
    {
        $files = glob(database_path('migrations/*_convert_users_phone_to_e164.php'));

        $this->assertCount(1, $files, 'Falta a migração *_convert_users_phone_to_e164.php.');

        return require $files[0];
    }

    private function userWithPhone(?string $phone): User
    {
        return User::factory()->create(['phone' => $phone]);
    }

    private function storedPhone(User $user): ?string
    {
        return DB::table('users')->where('id', $user->id)->value('phone');
    }

    public static function portuguesePhones(): array
    {
        return [
            'telemóvel' => ['912345678', '+351912345678'],
            'fixo' => ['212345678', '+351212345678'],
            'nómada 30' => ['301234567', '+351301234567'],
        ];
    }

    #[Test]
    #[DataProvider('portuguesePhones')]
    public function up_converts_nine_digit_portuguese_phones_to_e164(string $old, string $expected): void
    {
        $user = $this->userWithPhone($old);

        $this->migration()->up();

        $this->assertSame($expected, $this->storedPhone($user));
    }

    #[Test]
    public function up_also_converts_soft_deleted_users(): void
    {
        $user = $this->userWithPhone('912345678');
        $user->delete();

        $this->migration()->up();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertSame('+351912345678', $this->storedPhone($user));
    }

    public static function untouchedPhones(): array
    {
        return [
            'marcador de anonimização' => ['000000000'],
            'já em E.164 estrangeiro' => ['+447911123456'],
            'já em E.164 português' => ['+351912345678'],
            'outro formato' => ['912 345 678'],
        ];
    }

    #[Test]
    #[DataProvider('untouchedPhones')]
    public function up_leaves_other_values_unchanged(string $phone): void
    {
        $user = $this->userWithPhone($phone);

        $this->migration()->up();

        $this->assertSame($phone, $this->storedPhone($user));
    }

    #[Test]
    public function up_leaves_null_phone_unchanged(): void
    {
        $user = $this->userWithPhone(null);

        $this->migration()->up();

        $this->assertNull($this->storedPhone($user));
    }

    #[Test]
    public function anonymised_user_keeps_the_marker(): void
    {
        $user = $this->userWithPhone('912345678');
        $user->anonymizeAndDelete();

        $this->migration()->up();

        $this->assertSame('000000000', $this->storedPhone($user));
    }

    #[Test]
    public function running_up_twice_gives_the_same_result(): void
    {
        $portuguese = $this->userWithPhone('912345678');
        $foreign = $this->userWithPhone('+447911123456');
        $anonymised = $this->userWithPhone('000000000');

        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $this->assertSame('+351912345678', $this->storedPhone($portuguese));
        $this->assertSame('+447911123456', $this->storedPhone($foreign));
        $this->assertSame('000000000', $this->storedPhone($anonymised));
    }

    #[Test]
    public function up_converts_every_row_across_chunks(): void
    {
        $users = User::factory()->count(250)->create(['phone' => '912345678']);

        $this->migration()->up();

        $this->assertSame(0, DB::table('users')->where('phone', '912345678')->count());
        $this->assertSame($users->count(), DB::table('users')->where('phone', '+351912345678')->count());
    }

    #[Test]
    public function down_reverts_only_portuguese_phones(): void
    {
        $portuguese = $this->userWithPhone('+351912345678');
        $landline = $this->userWithPhone('+351212345678');
        $foreign = $this->userWithPhone('+447911123456');
        $anonymised = $this->userWithPhone('000000000');

        $this->migration()->down();

        $this->assertSame('912345678', $this->storedPhone($portuguese));
        $this->assertSame('212345678', $this->storedPhone($landline));
        $this->assertSame('+447911123456', $this->storedPhone($foreign));
        $this->assertSame('000000000', $this->storedPhone($anonymised));
    }

    #[Test]
    public function down_after_up_restores_the_original_portuguese_phone(): void
    {
        $user = $this->userWithPhone('912345678');
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $this->assertSame('912345678', $this->storedPhone($user));
    }
}
