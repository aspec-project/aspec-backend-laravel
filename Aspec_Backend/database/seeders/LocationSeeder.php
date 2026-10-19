<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

/**
 * Distritos de Portugal continental e regiões autónomas (núcleos regionais da ASPEC).
 */
class LocationSeeder extends Seeder
{
    /** @var list<string> */
    private const LOCATIONS = [
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
        'Viseu',
        'Açores',
        'Madeira',
    ];

    /**
     * Cria os distritos e as regiões autónomas que ainda não existem, sem alterar os existentes.
     */
    public function run(): void
    {
        foreach (self::LOCATIONS as $name) {
            Location::firstOrCreate(['name' => $name]);
        }
    }
}
