<?php

namespace Tests\Unit\Geocoding;

use App\Services\Geocoding\ResultVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ResultVerifierTest extends TestCase
{
    private function isApproximate(array $details, ?string $street, ?string $district): bool
    {
        return (new ResultVerifier)->isApproximate($details, $street, $district);
    }

    public static function matchingResults(): array
    {
        return [
            'Coimbra no continente' => [['road' => 'Rua do Brasil', 'ISO3166-2-lvl6' => 'PT-06'], 'Rua do Brasil 25', 'Coimbra'],
            'Açores na região autónoma' => [['road' => 'Rua Ernesto do Canto', 'ISO3166-2-lvl4' => 'PT-20'], 'Rua Ernesto do Canto 10', 'Açores'],
            'Madeira na região autónoma' => [['road' => 'Avenida Arriaga', 'ISO3166-2-lvl4' => 'PT-30'], 'Avenida Arriaga 1', 'Madeira'],
            'abreviatura Av.' => [['road' => 'Avenida da Boavista', 'ISO3166-2-lvl6' => 'PT-13'], 'Av. da Boavista 1277', 'Porto'],
            'abreviatura Dr.' => [['road' => 'Rua Doutor Aristides da Mota'], 'Rua Dr. Aristides da Mota 5', null],
            'sem acento na rua pedida' => [['road' => 'Rua da Sé'], 'Rua da Se 10', null],
            'sem rua pedida e distrito certo' => [['city' => 'Lisboa', 'ISO3166-2-lvl6' => 'PT-11'], null, 'Lisboa'],
        ];
    }

    #[Test]
    #[DataProvider('matchingResults')]
    public function matching_results_are_not_approximate(array $details, ?string $street, ?string $district): void
    {
        $this->assertFalse($this->isApproximate($details, $street, $district));
    }

    public static function approximateResults(): array
    {
        return [
            'outra rua nos Açores' => [['road' => 'Rua da Guiné', 'ISO3166-2-lvl4' => 'PT-20'], 'Rua da Sé 10', 'Açores'],
            'outra rua com palavras em comum' => [['road' => 'Rua Senhora da Rosa'], 'Rua da Sé 10', null],
            'outro distrito' => [['road' => 'Rua Augusta', 'ISO3166-2-lvl6' => 'PT-11'], 'Rua Augusta 100', 'Porto'],
            'resultado sem rua' => [['city' => 'Lisboa', 'ISO3166-2-lvl6' => 'PT-11'], 'Rua Augusta 100', null],
            'sem detalhes' => [[], 'Rua Augusta 100', null],
        ];
    }

    #[Test]
    #[DataProvider('approximateResults')]
    public function different_street_or_district_is_approximate(array $details, ?string $street, ?string $district): void
    {
        $this->assertTrue($this->isApproximate($details, $street, $district));
    }

    #[Test]
    public function district_is_not_checked_when_unknown_or_without_iso_codes(): void
    {
        $lisbonCodes = ['road' => 'Rua Augusta', 'ISO3166-2-lvl6' => 'PT-11'];
        $withoutCodes = ['road' => 'Rua Augusta', 'city' => 'Lisboa'];

        $this->assertFalse($this->isApproximate($lisbonCodes, 'Rua Augusta 100', 'Núcleo Teste'));
        $this->assertFalse($this->isApproximate($lisbonCodes, 'Rua Augusta 100', null));
        $this->assertFalse($this->isApproximate($withoutCodes, 'Rua Augusta 100', 'Porto'));

        $this->assertTrue($this->isApproximate(['road' => 'Rua da Prata', 'ISO3166-2-lvl6' => 'PT-11'], 'Rua Augusta 100', 'Núcleo Teste'));
        $this->assertTrue($this->isApproximate(['road' => 'Rua da Prata', 'city' => 'Lisboa'], 'Rua Augusta 100', 'Porto'));
    }

    #[Test]
    public function street_without_significant_words_is_not_checked(): void
    {
        $this->assertFalse($this->isApproximate(['road' => 'Rua Augusta', 'ISO3166-2-lvl6' => 'PT-11'], 'Rua A', 'Lisboa'));
    }

    public static function districtSpellings(): array
    {
        return [
            'açores' => ['açores', 'ISO3166-2-lvl4', 'PT-20'],
            'AÇORES' => ['AÇORES', 'ISO3166-2-lvl4', 'PT-20'],
            'Acores' => ['Acores', 'ISO3166-2-lvl4', 'PT-20'],
            'Bragança' => ['Bragança', 'ISO3166-2-lvl6', 'PT-04'],
            'braganca' => ['braganca', 'ISO3166-2-lvl6', 'PT-04'],
        ];
    }

    #[Test]
    #[DataProvider('districtSpellings')]
    public function district_name_is_compared_without_accents_or_case(string $district, string $level, string $code): void
    {
        $this->assertFalse($this->isApproximate([$level => $code], null, $district));
        $this->assertTrue($this->isApproximate([$level => 'PT-11'], null, $district));
    }
}
