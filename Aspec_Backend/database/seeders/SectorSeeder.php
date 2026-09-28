<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SectorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sectors = [
            'Agricultura e Pescas',
            'Arquitetura e Design',
            'Comércio Retalhista',
            'Construção Civil e Obras Públicas',
            'Consultoria de Gestão e Negócios',
            'Contabilidade e Finanças',
            'Educação e Formação',
            'Engenharia e Indústria',
            'Estética e Bem-Estar',
            'Imobiliário',
            'Marketing, Comunicação e Publicidade',
            'Restauração e Hotelaria',
            'Saúde e Medicina',
            'Serviços Jurídicos e Advocacia',
            'Tecnologias de Informação (TI)',
            'Transportes e Logística',
            'Turismo e Lazer'
        ];

        // Ordenar alfabeticamente para manter a base de dados organizada
        sort($sectors);

        foreach ($sectors as $sectorName) {
            Sector::firstOrCreate([
                'name' => $sectorName
            ], [
                'id' => Str::uuid(), 
            ]);
        }
    }
}
