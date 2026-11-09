<?php

namespace Database\Seeders;

use App\Models\EventCategory;
use Illuminate\Database\Seeder;

/**
 * Categorias de eventos reais da ASPEC, cada uma com uma cor distinta da paleta (config/event_categories.php).
 */
class EventCategorySeeder extends Seeder
{
    /** @var array<string, string> Nome da categoria => cor. */
    private const CATEGORIES = [
        'Pequeno-almoço' => '#94591A',
        'Almoço' => '#0E784F',
        'Jantar' => '#763B82',
        'Workshop' => '#1C747E',
        'Formação' => '#1E449A',
    ];

    /**
     * Cria as categorias que faltam; não altera as existentes e salta (com aviso) as que tenham
     * a cor já usada por outra categoria, para respeitar o índice único da cor.
     */
    public function run(): void
    {
        foreach (self::CATEGORIES as $name => $color) {
            if (EventCategory::where('name', $name)->exists()) {
                continue;
            }

            if (EventCategory::where('color', $color)->exists()) {
                $this->command?->warn("A categoria \"{$name}\" não foi criada: a cor {$color} já é usada por outra categoria.");

                continue;
            }

            EventCategory::create(['name' => $name, 'color' => $color]);
        }
    }
}
