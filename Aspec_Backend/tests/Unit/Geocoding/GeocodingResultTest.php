<?php

namespace Tests\Unit\Geocoding;

use App\Services\Geocoding\Data\GeocodingResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GeocodingResultTest extends TestCase
{
    #[Test]
    public function nominatim_results_have_no_place_id_and_are_not_approximate_by_default(): void
    {
        $result = new GeocodingResult(38.7, -9.1, 'X');

        $this->assertObjectHasProperty('placeId', $result);
        $this->assertNull($result->placeId);
        $this->assertFalse($result->approximate);
    }

    #[Test]
    public function google_results_have_no_coordinates_and_keep_the_place_id_in_the_array(): void
    {
        $result = new GeocodingResult(null, null, 'X', true, 'ChIJ1');

        $this->assertSame([
            'latitude' => null,
            'longitude' => null,
            'display_name' => 'X',
            'approximate' => true,
            'place_id' => 'ChIJ1',
        ], $result->toArray());
    }

    #[Test]
    public function old_cache_entries_without_place_id_still_work(): void
    {
        $result = GeocodingResult::fromArray(['latitude' => 38.7, 'longitude' => -9.1, 'display_name' => 'X']);

        $this->assertSame(38.7, $result->latitude);
        $this->assertSame(-9.1, $result->longitude);
        $this->assertSame('X', $result->displayName);
        $this->assertFalse($result->approximate);
        $this->assertObjectHasProperty('placeId', $result);
        $this->assertNull($result->placeId);
    }

    #[Test]
    public function google_results_survive_the_round_trip_through_the_array(): void
    {
        $original = new GeocodingResult(null, null, 'X', true, 'ChIJ1');

        $this->assertEquals($original, GeocodingResult::fromArray($original->toArray()));
    }
}
