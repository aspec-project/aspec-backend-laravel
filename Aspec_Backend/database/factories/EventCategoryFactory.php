<?php

namespace Database\Factories;

use App\Models\EventCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Categoria de eventos com nome único e cor #RRGGBB válida.
 *
 * @extends Factory<EventCategory>
 */
class EventCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // unique() evita colidir com o índice unique do nome entre categorias da mesma execução.
            'name' => ucfirst(fake()->unique()->words(2, true)),
            // hexColor() devolve #rrggbb (minúsculas), aceite pela validação #RRGGBB da API.
            'color' => fake()->hexColor(),
        ];
    }
}
