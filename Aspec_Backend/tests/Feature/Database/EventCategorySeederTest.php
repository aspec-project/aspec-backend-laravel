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

    private const SITE_PALETTE = ['#0D1F35', '#315A79', '#8A7043', '#C5A46B'];

    // T1 — o seed base (DatabaseSeeder) cria as cinco categorias reais.
    #[Test]
    public function seeder_creates_the_five_real_event_categories(): void
    {
        $this->assertEqualsCanonicalizing(
            ['Pequeno-almoço', 'Almoço', 'Jantar', 'Workshop', 'Formação'],
            EventCategory::pluck('name')->all()
        );
    }

    // T2 — o seeder é idempotente: correr outra vez não duplica categorias.
    #[Test]
    public function running_the_seeder_again_does_not_duplicate_categories(): void
    {
        $this->seed(EventCategorySeeder::class);

        $this->assertSame(5, EventCategory::count());
    }

    // T3 — cada categoria tem cor #RRGGBB e o seeder não sobrescreve uma cor alterada pelo admin.
    #[Test]
    public function seeded_categories_have_a_valid_hex_color_that_the_seeder_does_not_overwrite(): void
    {
        foreach (EventCategory::all() as $category) {
            $this->assertMatchesRegularExpression(self::HEX_COLOR, (string) $category->color, "Cor inválida em {$category->name}.");
        }

        $training = EventCategory::where('name', 'Formação')->firstOrFail();
        $training->update(['color' => '#000000']);

        $this->seed(EventCategorySeeder::class);

        $this->assertSame('#000000', $training->fresh()->color);
    }

    #[Test]
    public function seeded_categories_have_the_planned_colors(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'Pequeno-almoço' => '#B84A00',
                'Almoço' => '#2E7D32',
                'Jantar' => '#6A1B9A',
                'Workshop' => '#C62828',
                'Formação' => '#1565C0',
            ],
            EventCategory::pluck('color', 'name')->all()
        );
    }

    #[Test]
    public function seeded_categories_respect_the_category_rules_and_avoid_the_site_palette(): void
    {
        $categories = EventCategory::all();

        foreach ($categories as $category) {
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $category->color, $category->name);
            $this->assertNotContains($category->color, self::SITE_PALETTE, $category->name);
            $this->assertLessThanOrEqual(30, mb_strlen($category->name), $category->name);
            $this->assertNotContains($category->name, ['Online', 'Reuniões']);
        }

        $this->assertSame(5, $categories->pluck('color')->unique()->count());
    }

    #[Test]
    public function seeder_skips_a_category_whose_color_is_already_used_and_warns(): void
    {
        EventCategory::where('name', 'Almoço')->delete();
        EventCategory::create(['name' => 'Brunch', 'color' => '#2E7D32']);

        $this->artisan('db:seed', ['--class' => EventCategorySeeder::class])
            ->expectsOutputToContain('A categoria "Almoço" não foi criada: a cor #2E7D32 já é usada por outra categoria.')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['Pequeno-almoço', 'Jantar', 'Workshop', 'Formação', 'Brunch'],
            EventCategory::pluck('name')->all()
        );
        $this->assertSame('#2E7D32', EventCategory::where('name', 'Brunch')->firstOrFail()->color);
    }

    #[Test]
    public function seeder_does_not_recreate_a_seeded_category_renamed_by_an_admin(): void
    {
        EventCategory::where('name', 'Jantar')->firstOrFail()->update(['name' => 'Jantares']);

        $this->artisan('db:seed', ['--class' => EventCategorySeeder::class])
            ->expectsOutputToContain('A categoria "Jantar" não foi criada')
            ->assertSuccessful();

        $this->assertSame(5, EventCategory::count());
        $this->assertFalse(EventCategory::where('name', 'Jantar')->exists());
        $this->assertSame('#6A1B9A', EventCategory::where('name', 'Jantares')->firstOrFail()->color);
    }
}
