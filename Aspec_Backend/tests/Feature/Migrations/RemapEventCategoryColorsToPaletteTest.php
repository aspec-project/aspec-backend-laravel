<?php

namespace Tests\Feature\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Migração de dados que passa as categorias existentes para a paleta fixa: as cinco cores antigas
 * vão para o destino combinado e as restantes para a primeira cor livre, sem nunca escrever uma cor
 * já em uso, porque event_categories.color tem índice único.
 */
class RemapEventCategoryColorsToPaletteTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_09_000000_remap_event_category_colors_to_palette.php';

    private const PALETTE = [
        '#0D1F35', '#1E449A', '#2A6E9E', '#0A4251', '#1C747E',
        '#0B4D3F', '#0E784F', '#2B4A12', '#53731A', '#5E6013',
        '#7A6238', '#94591A', '#5A3A14', '#3A2E1C', '#38207E',
        '#6548B8', '#763B82', '#686693', '#5B6670', '#383B41',
    ];

    private const FIRST_FREE = '#0D1F35';

    private const SECOND_FREE = '#1E449A';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('event_categories')->delete();
    }

    private function migration(): Migration
    {
        $this->assertFileExists(base_path(self::MIGRATION));

        return require base_path(self::MIGRATION);
    }

    private function category(string $color, string $createdAt = '2026-01-01 10:00:00'): string
    {
        $id = (string) Str::uuid();

        DB::table('event_categories')->insert([
            'id' => $id,
            'name' => 'Categoria '.$id,
            'color' => $color,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $id;
    }

    private function colorOf(string $id): string
    {
        return DB::table('event_categories')->where('id', $id)->value('color');
    }

    #[Test]
    public function the_five_former_seeded_colors_move_to_their_destinations(): void
    {
        $expected = [
            '#B84A00' => '#94591A',
            '#2E7D32' => '#0E784F',
            '#6A1B9A' => '#763B82',
            '#C62828' => '#1C747E',
            '#1565C0' => '#1E449A',
        ];

        $ids = array_map(fn (string $former): string => $this->category($former), array_combine(array_keys($expected), array_keys($expected)));

        $this->migration()->up();

        foreach ($expected as $former => $destination) {
            $this->assertSame($destination, $this->colorOf($ids[$former]), $former);
        }
    }

    #[Test]
    public function unknown_colors_receive_the_first_free_color_by_creation_date(): void
    {
        $newer = $this->category('#ABCDEF', '2026-03-01 10:00:00');
        $older = $this->category('#123456', '2026-01-01 10:00:00');

        $this->migration()->up();

        $this->assertSame(self::FIRST_FREE, $this->colorOf($older));
        $this->assertSame(self::SECOND_FREE, $this->colorOf($newer));
    }

    #[Test]
    public function colors_already_in_the_palette_are_left_untouched(): void
    {
        $id = $this->category('#6548B8');

        $this->migration()->up();

        $this->assertSame('#6548B8', $this->colorOf($id));
    }

    #[Test]
    public function an_occupied_destination_falls_back_to_the_next_free_color_without_duplicates(): void
    {
        $former = $this->category('#B84A00', '2026-01-01 10:00:00');
        $holder = $this->category('#94591A', '2026-02-01 10:00:00');

        $this->migration()->up();

        $this->assertSame('#94591A', $this->colorOf($holder));
        $this->assertSame(self::FIRST_FREE, $this->colorOf($former));
    }

    #[Test]
    public function lowercase_colors_are_compared_ignoring_case(): void
    {
        $formerLowercase = $this->category('#b84a00');
        $holderLowercase = $this->category('#0e784f', '2026-02-01 10:00:00');
        $formerWithOccupiedDestination = $this->category('#2E7D32', '2026-03-01 10:00:00');

        $this->migration()->up();

        $this->assertSame('#94591A', $this->colorOf($formerLowercase));
        $this->assertSame('#0E784F', strtoupper($this->colorOf($holderLowercase)));
        $this->assertSame(self::FIRST_FREE, $this->colorOf($formerWithOccupiedDestination));
    }

    #[Test]
    public function running_the_migration_twice_changes_nothing(): void
    {
        $this->category('#B84A00');
        $this->category('#123456', '2026-02-01 10:00:00');
        $this->category('#6548B8', '2026-03-01 10:00:00');

        $this->migration()->up();
        $afterFirstRun = DB::table('event_categories')->orderBy('id')->pluck('color', 'id')->all();

        $this->migration()->up();

        $this->assertSame($afterFirstRun, DB::table('event_categories')->orderBy('id')->pluck('color', 'id')->all());
    }

    /**
     * Com mais categorias do que cores, a migração não pode fazer falhar o deploy: a excedente
     * fica como está e fica registado um aviso.
     */
    #[Test]
    public function a_category_without_any_free_color_stays_as_it_is_and_logs_a_warning(): void
    {
        Log::spy();

        foreach (array_slice(self::PALETTE, 0, 19) as $color) {
            $this->category($color);
        }

        $lastFree = self::PALETTE[19];
        $served = $this->category('#123456', '2026-02-01 10:00:00');
        $excess = $this->category('#ABCDEF', '2026-03-01 10:00:00');

        $this->migration()->up();

        $this->assertSame($lastFree, $this->colorOf($served));
        $this->assertSame('#ABCDEF', $this->colorOf($excess));
        $this->assertSame(21, DB::table('event_categories')->distinct()->count('color'));
        Log::shouldHaveReceived('warning');
    }

    #[Test]
    public function the_result_does_not_depend_on_the_current_configuration(): void
    {
        config(['event_categories.palette' => ['#FFFFFF' => 'Branco']]);
        $id = $this->category('#123456');

        $this->migration()->up();

        $this->assertSame(self::FIRST_FREE, $this->colorOf($id));
    }

    #[Test]
    public function down_does_not_fail_and_keeps_the_colors(): void
    {
        $id = $this->category('#B84A00');
        $this->migration()->up();

        $this->migration()->down();

        $this->assertSame('#94591A', $this->colorOf($id));
    }
}
