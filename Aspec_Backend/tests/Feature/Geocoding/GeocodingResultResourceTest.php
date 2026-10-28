<?php

namespace Tests\Feature\Geocoding;

use App\Http\Resources\GeocodingResultResource;
use App\Services\Geocoding\Data\GeocodingResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeocodingResultResourceTest extends TestCase
{
    #[Test]
    public function nominatim_result_has_coordinates_osm_attribution_and_no_place_id(): void
    {
        $data = (new GeocodingResultResource(new GeocodingResult(38.7, -9.1, 'X')))->resolve();

        $this->assertSame([
            'latitude' => 38.7,
            'longitude' => -9.1,
            'display_name' => 'X',
            'attribution' => '© OpenStreetMap contributors',
            'approximate' => false,
            'place_id' => null,
        ], $data);
    }

    #[Test]
    public function google_result_has_place_id_google_attribution_and_no_coordinates(): void
    {
        $data = (new GeocodingResultResource(new GeocodingResult(null, null, 'Y', true, 'ChIJ1')))->resolve();

        $this->assertSame([
            'latitude' => null,
            'longitude' => null,
            'display_name' => 'Y',
            'attribution' => 'Google Maps',
            'approximate' => true,
            'place_id' => 'ChIJ1',
        ], $data);
    }

    #[Test]
    public function attribution_constants_have_the_provider_texts(): void
    {
        $this->assertSame('© OpenStreetMap contributors', GeocodingResultResource::OSM_ATTRIBUTION);
        $this->assertSame('Google Maps', GeocodingResultResource::GOOGLE_ATTRIBUTION);
    }
}
