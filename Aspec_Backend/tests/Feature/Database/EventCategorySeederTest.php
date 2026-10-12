<?php

namespace Tests\Feature\Database;

use App\Models\EventCategory;
use Database\Seeders\EventCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventCategorySeederTest extends TestCase
{
    use RefreshDatabase;

    private const HEX_COLOR = '/^#[0-9A-Fa-f]{6}$/';

    // T1 — o seed base (DatabaseSeeder) cria as quatro categorias iniciais.
    #[Test]
    public function seeder_creates_the_four_event_categories(): void
    {
        $this->assertEqualsCanonicalizing(
            ['Jantares de Networking', 'Pequenos-almoços de Negócios', 'Formações', 'Reuniões'],
            EventCategory::pluck('name')->all()
        );
    }

    // T2 — o seeder é idempotente: correr outra vez não duplica categorias.
    #[Test]
    public function running_the_seeder_again_does_not_duplicate_categories(): void
    {
        $this->seed(EventCategorySeeder::class);

        $this->assertSame(4, EventCategory::count());
    }

    // T3 — cada categoria tem cor #RRGGBB e o seeder não sobrescreve uma cor alterada pelo admin.
    #[Test]
    public function seeded_categories_have_a_valid_hex_color_that_the_seeder_does_not_overwrite(): void
    {
        foreach (EventCategory::all() as $category) {
            $this->assertMatchesRegularExpression(self::HEX_COLOR, (string) $category->color, "Cor inválida em {$category->name}.");
        }

        $training = EventCategory::where('name', 'Formações')->firstOrFail();
        $training->update(['color' => '#000000']);

        $this->seed(EventCategorySeeder::class);

        $this->assertSame('#000000', $training->fresh()->color);
    }
}
