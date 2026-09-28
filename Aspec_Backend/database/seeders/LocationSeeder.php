<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Location;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $districts = [
            'Aveiro',
            'Beja',
            'Braga',
            'Bragança',
            'Castelo Branco',
            'Coimbra',
            'Évora',
            'Faro',
            'Guarda',
            'Leiria',
            'Lisboa',
            'Portalegre',
            'Porto',
            'Santarém',
            'Setúbal',
            'Viana do Castelo',
            'Vila Real',
            'Viseu'
        ];

        sort($districts);

        foreach ($districts as $district) {
            Location::firstOrCreate(
                ['name' => $district]
            );
        }
    }
}
