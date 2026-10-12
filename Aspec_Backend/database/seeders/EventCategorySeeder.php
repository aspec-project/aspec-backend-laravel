<?php

namespace Database\Seeders;

use App\Models\EventCategory;
use Illuminate\Database\Seeder;

class EventCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Cores da paleta do frontend.
        $categories = [
            'Jantares de Networking' => '#8A7043',
            'Pequenos-almoços de Negócios' => '#C5A46B',
            'Formações' => '#315A79',
            'Reuniões' => '#0D1F35',
        ];

        foreach ($categories as $name => $color) {
            // Procura só pelo nome: o seeder pode correr várias vezes sem duplicar
            // e a cor só é usada ao criar, por isso não apaga uma cor alterada pelo admin.
            EventCategory::firstOrCreate(['name' => $name], ['color' => $color]);
        }
    }
}
