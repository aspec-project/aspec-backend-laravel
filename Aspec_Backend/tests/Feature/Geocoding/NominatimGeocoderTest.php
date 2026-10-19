<?php

namespace Tests\Feature\Geocoding;

use App\Contracts\Geocoder;
use App\Exceptions\GeocodingUnavailable;
use App\Services\Geocoding\Data\GeocodingResult;
use App\Services\Geocoding\NominatimGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Serviço de geocodificação com o Nominatim.
 * Nunca há pedidos reais: todas as respostas são simuladas com Http::fake().
 */
class NominatimGeocoderTest extends TestCase
{
    use RefreshDatabase;

    private const URL_PATTERN = 'nominatim.test/*';

    private const DISPLAY_NAME = 'Rua Augusta, Baixa, Santa Maria Maior, Lisboa, 1100-053, Portugal';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.nominatim.base_url' => 'https://nominatim.test',
            'services.nominatim.contact_email' => null,
        ]);
    }

    /** Ocorrência como o Nominatim a devolve com format=jsonv2 (lat/lon em texto). */
    private static function place(array $overrides = []): array
    {
        return array_merge([
            'place_id' => 123,
            'licence' => 'Data © OpenStreetMap contributors, ODbL 1.0.',
            'osm_type' => 'way',
            'osm_id' => 456,
            'lat' => '38.70845912345',
            'lon' => '-9.13652841234',
            'display_name' => self::DISPLAY_NAME,
            'boundingbox' => ['38.7', '38.8', '-9.2', '-9.1'],
        ], $overrides);
    }

    private function fakeFound(): void
    {
        Http::fake([self::URL_PATTERN => Http::response([self::place()], 200)]);
    }

    private function geocoder(): Geocoder
    {
        return app(Geocoder::class);
    }

    private function geocodeExpectingFailure(string $query): GeocodingUnavailable
    {
        try {
            $this->geocoder()->geocode($query);
        } catch (GeocodingUnavailable $e) {
            return $e;
        }

        $this->fail('Era esperada uma GeocodingUnavailable.');
    }

    /** Parâmetros da query string do pedido, já descodificados. */
    private function queryOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    private function assertLisbonResult(?GeocodingResult $result): void
    {
        $this->assertInstanceOf(GeocodingResult::class, $result);
        $this->assertSame(38.7084591, $result->latitude);
        $this->assertSame(-9.1365284, $result->longitude);
        $this->assertSame(self::DISPLAY_NAME, $result->displayName);
    }

    #[Test]
    public function geocode_sends_the_expected_request_and_returns_the_coordinates(): void
    {
        $this->fakeFound();

        $result = $this->geocoder()->geocode('Rua Augusta 1, Lisboa');

        $this->assertLisbonResult($result);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);
            ksort($query);

            return $request->method() === 'GET'
                && strtok($request->url(), '?') === 'https://nominatim.test/search'
                && $query === [
                    'accept-language' => 'pt-PT',
                    'addressdetails' => '0',
                    'countrycodes' => 'pt',
                    'format' => 'jsonv2',
                    'limit' => '1',
                    'q' => 'Rua Augusta 1, Lisboa',
                ]
                && $request->header('User-Agent') === [config('app.name').'/1.0'];
        });
    }

    #[Test]
    public function geocode_returns_null_when_the_address_is_not_found(): void
    {
        Http::fake([self::URL_PATTERN => Http::response([], 200)]);

        $this->assertNull($this->geocoder()->geocode('Rua Inexistente 99'));
    }

    #[Test]
    public function geocode_uses_the_cache_for_the_same_normalised_address(): void
    {
        $this->freezeTime();
        $this->fakeFound();

        $first = $this->geocoder()->geocode('Rua Augusta 1, Lisboa');
        $second = $this->geocoder()->geocode('  rua   augusta 1,  LISBOA ');

        $this->assertLisbonResult($first);
        $this->assertEquals($first, $second);
        Http::assertSentCount(1);
    }

    #[Test]
    public function cached_results_survive_cache_serialization(): void
    {
        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');
        $this->fakeFound();

        $this->geocoder()->geocode('Rua Augusta 1, Lisboa');
        $cached = $this->geocoder()->geocode('Rua Augusta 1, Lisboa');

        $this->assertLisbonResult($cached);
        Http::assertSentCount(1);
    }

    #[Test]
    public function not_found_results_are_not_cached(): void
    {
        $this->freezeTime();
        Http::fake([self::URL_PATTERN => Http::sequence()
            ->push([], 200)
            ->push([self::place()], 200)]);

        $first = $this->geocoder()->geocode('Rua Inexistente 99');
        $this->travel(1)->seconds();
        $second = $this->geocoder()->geocode('Rua Inexistente 99');

        $this->assertNull($first);
        $this->assertLisbonResult($second);
        Http::assertSentCount(2);
    }

    #[Test]
    public function server_errors_throw_geocoding_unavailable_and_are_not_cached(): void
    {
        $this->freezeTime();
        Http::fake([self::URL_PATTERN => Http::sequence()
            ->push('', 500)
            ->push('', 429)
            ->push([self::place()], 200)]);

        $this->assertSame(500, $this->geocodeExpectingFailure('Rua Augusta 1, Lisboa')->httpStatus);
        $this->travel(1)->seconds();
        $this->assertSame(429, $this->geocodeExpectingFailure('Rua Augusta 1, Lisboa')->httpStatus);
        $this->travel(1)->seconds();
        $this->assertLisbonResult($this->geocoder()->geocode('Rua Augusta 1, Lisboa'));
        Http::assertSentCount(3);
    }

    #[Test]
    public function connection_failures_throw_geocoding_unavailable(): void
    {
        Http::fake([self::URL_PATTERN => Http::failedConnection()]);

        $this->assertNull($this->geocodeExpectingFailure('Rua Augusta 1, Lisboa')->httpStatus);
    }

    public static function invalidResponses(): array
    {
        $withoutLon = self::place();
        unset($withoutLon['lon']);
        $withoutDisplayName = self::place();
        unset($withoutDisplayName['display_name']);

        return [
            'corpo em html' => ['<html>'],
            'objeto em vez de lista' => [['error' => 'x']],
            'lat não numérica' => [[self::place(['lat' => 'abc'])]],
            'lon em falta' => [[$withoutLon]],
            'lat fora do intervalo' => [[self::place(['lat' => '91'])]],
            'lon fora do intervalo' => [[self::place(['lon' => '-181'])]],
            'display_name em falta' => [[$withoutDisplayName]],
        ];
    }

    #[Test]
    #[DataProvider('invalidResponses')]
    public function invalid_responses_throw_geocoding_unavailable(string|array $body): void
    {
        Http::fake([self::URL_PATTERN => Http::response($body, 200)]);

        $this->assertInstanceOf(GeocodingUnavailable::class, $this->geocodeExpectingFailure('Rua Augusta 1, Lisboa'));
    }

    #[Test]
    public function only_one_request_per_second_reaches_nominatim(): void
    {
        $this->freezeTime();
        $this->fakeFound();

        $this->assertLisbonResult($this->geocoder()->geocode('Morada A'));
        $this->geocodeExpectingFailure('Morada B');
        Http::assertSentCount(1);

        $this->travel(1)->seconds();
        $this->assertLisbonResult($this->geocoder()->geocode('Morada B'));
        Http::assertSentCount(2);

        $this->assertLisbonResult($this->geocoder()->geocode('Morada A'));
        Http::assertSentCount(2);
    }

    #[Test]
    public function contact_email_is_sent_when_configured_and_the_service_is_bound(): void
    {
        config(['services.nominatim.contact_email' => 'geral@aspec.test']);
        $this->fakeFound();

        $this->assertInstanceOf(NominatimGeocoder::class, $this->geocoder());
        $this->geocoder()->geocode('Rua Augusta 1, Lisboa');

        Http::assertSent(fn (Request $request) => ($this->queryOf($request)['email'] ?? null) === 'geral@aspec.test'
            && $request->header('User-Agent') === [config('app.name').'/1.0 (geral@aspec.test)']);
    }
}
