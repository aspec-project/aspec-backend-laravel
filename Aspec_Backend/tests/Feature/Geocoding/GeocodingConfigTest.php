<?php

namespace Tests\Feature\Geocoding;

use App\Contracts\Geocoder;
use App\Services\Geocoding\NominatimGeocoder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Configuração do Google Maps (ASPEC-412): os testes nunca usam a chave real do Google, mesmo
 * que o .env local a tenha, e o fornecedor por omissão continua a ser o Nominatim.
 */
class GeocodingConfigTest extends TestCase
{
    #[Test]
    public function tests_run_with_the_nominatim_driver_and_without_a_google_key(): void
    {
        $this->assertSame('nominatim', config('services.geocoding.driver'));
        $this->assertEmpty(config('services.google_maps.geocoding_key'));
        $this->assertInstanceOf(NominatimGeocoder::class, app(Geocoder::class));
    }

    #[Test]
    public function google_maps_timeout_has_a_default(): void
    {
        $this->assertSame(5, config('services.google_maps.timeout'));
    }
}
