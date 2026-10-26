<?php

namespace Tests\Feature\Geocoding;

use App\Contracts\Geocoder;
use App\Exceptions\GeocodingUnavailable;
use App\Services\Geocoding\Data\GeocodingResult;
use App\Services\Geocoding\NominatimGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
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

    /** Opções do Guzzle (timeouts) de cada pedido feito pelo fakeSlowResponses(). */
    private array $sentOptions = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
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
            'address' => [
                'road' => 'Rua Augusta',
                'city' => 'Lisboa',
                'ISO3166-2-lvl6' => 'PT-11',
                'postcode' => '1100-053',
                'country_code' => 'pt',
            ],
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

    private function assertLisbonResult(?GeocodingResult $result, bool $approximate = false): void
    {
        $this->assertInstanceOf(GeocodingResult::class, $result);
        $this->assertSame(38.7084591, $result->latitude);
        $this->assertSame(-9.1365284, $result->longitude);
        $this->assertSame(self::DISPLAY_NAME, $result->displayName);
        $this->assertSame($approximate, $result->approximate);
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
                    'addressdetails' => '1',
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
        $this->assertLisbonResult($second, approximate: true);
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

        $this->assertLisbonResult($this->geocoder()->geocode('Morada A'), approximate: true);
        $this->geocodeExpectingFailure('Morada B');
        Http::assertSentCount(1);

        $this->travel(1)->seconds();
        $this->assertLisbonResult($this->geocoder()->geocode('Morada B'), approximate: true);
        Http::assertSentCount(2);

        $this->assertLisbonResult($this->geocoder()->geocode('Morada A'), approximate: true);
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

    private static function occurrence(string $lat, string $lon, string $displayName, array $address): array
    {
        return [
            'lat' => $lat,
            'lon' => $lon,
            'display_name' => $displayName,
            'address' => $address + ['country_code' => 'pt'],
        ];
    }

    private static function coimbra(): array
    {
        return self::occurrence('40.2085123', '-8.4198765', '25, Rua do Brasil, Alta, Coimbra, 3030-175, Portugal', [
            'house_number' => '25',
            'road' => 'Rua do Brasil',
            'city' => 'Coimbra',
            'ISO3166-2-lvl6' => 'PT-06',
            'postcode' => '3030-175',
        ]);
    }

    private static function ernestoDoCanto(): array
    {
        return self::occurrence('37.7432100', '-25.6654300', 'Rua Ernesto do Canto, Ponta Delgada, Açores, 9500-312, Portugal', [
            'road' => 'Rua Ernesto do Canto',
            'city' => 'Ponta Delgada',
            'ISO3166-2-lvl4' => 'PT-20',
        ]);
    }

    private static function guine(): array
    {
        return self::occurrence('37.7412345', '-25.6712345', 'Rua da Guiné, Ponta Delgada, Açores, 9500-000, Portugal', [
            'road' => 'Rua da Guiné',
            'city' => 'Ponta Delgada',
            'ISO3166-2-lvl4' => 'PT-20',
        ]);
    }

    private static function boavista(): array
    {
        return self::occurrence('41.1590000', '-8.6550000', 'Avenida da Boavista, Porto, 4100-119, Portugal', [
            'road' => 'Avenida da Boavista',
            'city' => 'Porto',
            'ISO3166-2-lvl6' => 'PT-13',
        ]);
    }

    private static function ruaNova(): array
    {
        return self::occurrence('38.7110000', '-9.1450000', 'Rua Nova, Bairro Alto, Lisboa, 1200-001, Portugal', [
            'road' => 'Rua Nova',
            'city' => 'Lisboa',
            'ISO3166-2-lvl6' => 'PT-11',
        ]);
    }

    private static function prata(): array
    {
        return self::occurrence('38.7105000', '-9.1360000', 'Rua da Prata, Baixa, Lisboa, 1100-420, Portugal', [
            'road' => 'Rua da Prata',
            'city' => 'Lisboa',
            'ISO3166-2-lvl6' => 'PT-11',
        ]);
    }

    /** Valores de q enviados ao Nominatim, pela ordem dos pedidos. */
    private function sentQueries(): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => $this->queryOf($pair[0])['q'] ?? null)
            ->values()
            ->all();
    }

    private function locateExpectingFailure(string $address, ?string $district): GeocodingUnavailable
    {
        try {
            $this->geocoder()->locate($address, $district);
        } catch (GeocodingUnavailable $e) {
            return $e;
        }

        $this->fail('Era esperada uma GeocodingUnavailable.');
    }

    private function fakeEdificioAtlanticoCascade(): void
    {
        Http::fake([self::URL_PATTERN => Http::sequence()
            ->push([], 200)
            ->push([], 200)
            ->push([self::ruaNova()], 200)]);
    }

    private function fakeSeCascade(): void
    {
        Http::fake([self::URL_PATTERN => Http::sequence()
            ->push([], 200)
            ->push([self::guine()], 200)]);
    }

    #[Test]
    public function geocode_cleans_the_address_and_makes_a_single_request(): void
    {
        Http::fake([self::URL_PATTERN => Http::response([self::coimbra()], 200)]);

        $result = $this->geocoder()->geocode('Rua do Brasil nº 25, 3º Esq, Coimbra, Coimbra');

        $this->assertSame(['Rua do Brasil 25, Coimbra, Coimbra'], $this->sentQueries());
        $this->assertInstanceOf(GeocodingResult::class, $result);
        $this->assertSame(40.2085123, $result->latitude);
        $this->assertSame(-8.4198765, $result->longitude);
        $this->assertFalse($result->approximate);
    }

    #[Test]
    public function geocode_does_not_try_alternative_searches(): void
    {
        Http::fake([self::URL_PATTERN => Http::response([], 200)]);

        $this->assertNull($this->geocoder()->geocode('Rua do Brasil nº 25, 3º Esq, Coimbra, Coimbra'));
        $this->assertSame(['Rua do Brasil 25, Coimbra, Coimbra'], $this->sentQueries());
        Sleep::assertNeverSlept();
    }

    #[Test]
    public function geocode_marks_a_different_street_as_approximate(): void
    {
        Http::fake([self::URL_PATTERN => Http::response([self::guine()], 200)]);

        $result = $this->geocoder()->geocode('Rua da Sé 10, Ponta Delgada');

        $this->assertTrue($result->approximate);
    }

    #[Test]
    public function locate_returns_a_verified_result_on_the_first_attempt(): void
    {
        $this->fakeFound();

        $result = $this->geocoder()->locate('Rua Augusta 100, 1100-053 Lisboa', 'Lisboa');

        $this->assertSame(['Rua Augusta 100, 1100-053 Lisboa, Lisboa'], $this->sentQueries());
        $this->assertLisbonResult($result);
        Sleep::assertNeverSlept();
    }

    #[Test]
    public function locate_verifies_a_street_without_the_house_number(): void
    {
        Http::fake([self::URL_PATTERN => Http::response([self::boavista()], 200)]);

        $result = $this->geocoder()->locate('Avenida da Boavista, 1277, Porto', 'Porto');

        $this->assertSame(['Avenida da Boavista, 1277, Porto, Porto'], $this->sentQueries());
        $this->assertFalse($result->approximate);
    }

    #[Test]
    public function locate_cleans_the_address_before_searching(): void
    {
        Http::fake([self::URL_PATTERN => Http::response([self::coimbra()], 200)]);

        $result = $this->geocoder()->locate('Rua do Brasil nº 25, 3º Esq, Coimbra', 'Coimbra');

        $this->assertSame(['Rua do Brasil 25, Coimbra, Coimbra'], $this->sentQueries());
        $this->assertFalse($result->approximate);
    }

    #[Test]
    public function locate_finds_an_azores_address_without_the_district(): void
    {
        Http::fake([self::URL_PATTERN => Http::sequence()
            ->push([], 200)
            ->push([self::ernestoDoCanto()], 200)]);

        $result = $this->geocoder()->locate('Rua Ernesto do Canto 10, Ponta Delgada', 'Açores');

        $this->assertSame(
            ['Rua Ernesto do Canto 10, Ponta Delgada, Açores', 'Rua Ernesto do Canto 10, Ponta Delgada'],
            $this->sentQueries()
        );
        $this->assertFalse($result->approximate);
        Sleep::assertSequence([Sleep::for(1)->second()]);
    }

    #[Test]
    public function locate_returns_another_street_as_approximate_without_repeating_searches(): void
    {
        $this->fakeSeCascade();

        $result = $this->geocoder()->locate('Rua da Sé 10, Ponta Delgada', 'Açores');

        Http::assertSentCount(2);
        $this->assertTrue($result->approximate);
        $this->assertSame('Rua da Guiné, Ponta Delgada, Açores, 9500-000, Portugal', $result->displayName);
    }

    #[Test]
    public function locate_tries_the_street_with_the_locality_as_the_third_attempt(): void
    {
        $this->fakeEdificioAtlanticoCascade();

        $result = $this->geocoder()->locate('Edifício Atlântico, Rua Nova 12, Bairro Alto, 1200-001 Lisboa', 'Lisboa');

        $this->assertSame([
            'Edifício Atlântico, Rua Nova 12, Bairro Alto, 1200-001 Lisboa, Lisboa',
            'Edifício Atlântico, Rua Nova 12, Bairro Alto, 1200-001 Lisboa',
            'Rua Nova 12, Lisboa',
        ], $this->sentQueries());
        Sleep::assertSequence([Sleep::for(1)->second(), Sleep::for(1)->second()]);
        $this->assertFalse($result->approximate);
    }

    #[Test]
    public function locate_prefers_a_verified_result_from_a_later_attempt(): void
    {
        Http::fake([self::URL_PATTERN => Http::sequence()
            ->push([self::prata()], 200)
            ->push([self::place()], 200)]);

        $result = $this->geocoder()->locate('Rua Augusta 100, 1100-053 Lisboa', 'Lisboa');

        Http::assertSentCount(2);
        $this->assertLisbonResult($result);
    }

    #[Test]
    public function locate_returns_the_first_approximate_result_when_none_is_verified(): void
    {
        Http::fake([self::URL_PATTERN => Http::sequence()
            ->push([self::place(['lat' => '38.7100000'])], 200)
            ->push([self::place(['lat' => '38.7200000'])], 200)]);

        $result = $this->geocoder()->locate('Rua Augusta 100', 'Porto');

        Http::assertSentCount(2);
        $this->assertSame(38.71, $result->latitude);
        $this->assertTrue($result->approximate);
    }

    #[Test]
    public function locate_returns_null_when_no_attempt_finds_the_address_and_does_not_cache_it(): void
    {
        $this->freezeTime();
        Http::fake([self::URL_PATTERN => Http::response([], 200)]);

        $this->assertNull($this->geocoder()->locate('Rua Augusta 100, 1100-053 Lisboa', 'Lisboa'));
        Http::assertSentCount(3);

        $this->travel(1)->seconds();
        $this->assertNull($this->geocoder()->locate('Rua Augusta 100, 1100-053 Lisboa', 'Lisboa'));
        Http::assertSentCount(6);
    }

    #[Test]
    public function an_error_stops_the_cascade_and_nothing_is_cached(): void
    {
        $this->freezeTime();
        Http::fake([self::URL_PATTERN => Http::sequence()
            ->push([], 200)
            ->push('', 500)
            ->push([self::place()], 200)]);

        $this->assertSame(500, $this->locateExpectingFailure('Rua Augusta 100, 1100-053 Lisboa', 'Lisboa')->httpStatus);
        Http::assertSentCount(2);

        $this->travel(1)->seconds();
        $this->assertLisbonResult($this->geocoder()->locate('Rua Augusta 100, 1100-053 Lisboa', 'Lisboa'));
        Http::assertSentCount(3);
    }

    #[Test]
    public function a_connection_failure_on_the_first_attempt_stops_the_cascade(): void
    {
        Http::fake([self::URL_PATTERN => Http::failedConnection()]);

        $this->assertNull($this->locateExpectingFailure('Rua Augusta 100, 1100-053 Lisboa', 'Lisboa')->httpStatus);
        Http::assertSentCount(1);
    }

    #[Test]
    public function locate_uses_the_cache_for_the_same_cleaned_address_and_district(): void
    {
        $this->freezeTime();
        $this->fakeFound();

        $first = $this->geocoder()->locate('Rua Augusta 100, 1100-053 Lisboa', 'Lisboa');
        $second = $this->geocoder()->locate('  rua augusta 100,  1100-053 LISBOA ', 'lisboa');

        $this->assertLisbonResult($first);
        $this->assertEquals($first, $second);
        Http::assertSentCount(1);
    }

    #[Test]
    public function approximate_results_are_cached_too(): void
    {
        $this->freezeTime();
        $this->fakeSeCascade();

        $first = $this->geocoder()->locate('Rua da Sé 10, Ponta Delgada', 'Açores');
        $second = $this->geocoder()->locate('Rua da Sé 10, Ponta Delgada', 'Açores');

        $this->assertTrue($first->approximate);
        $this->assertEquals($first, $second);
        Http::assertSentCount(2);
    }

    #[Test]
    public function approximate_flag_survives_cache_serialization(): void
    {
        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');
        $this->fakeSeCascade();

        $this->geocoder()->locate('Rua da Sé 10, Ponta Delgada', 'Açores');
        $cached = $this->geocoder()->locate('Rua da Sé 10, Ponta Delgada', 'Açores');

        $this->assertTrue($cached->approximate);
        Http::assertSentCount(2);
    }

    #[Test]
    public function the_wait_between_attempts_keeps_one_request_per_second(): void
    {
        $this->fakeEdificioAtlanticoCascade();

        $result = $this->geocoder()->locate('Edifício Atlântico, Rua Nova 12, Bairro Alto, 1200-001 Lisboa', 'Lisboa');

        $this->assertFalse($result->approximate);
        Http::assertSentCount(3);

        $this->locateExpectingFailure('Rua do Brasil 25, Coimbra', 'Coimbra');
        $this->geocodeExpectingFailure('Rua do Brasil 25, Coimbra');
        Http::assertSentCount(3);
    }

    #[Test]
    public function the_cascade_stops_when_another_request_takes_the_slot_during_the_wait(): void
    {
        Sleep::whenFakingSleep(fn () => RateLimiter::hit('nominatim', 1));
        Http::fake([self::URL_PATTERN => Http::sequence()
            ->push([], 200)
            ->push([self::ernestoDoCanto()], 200)]);

        $this->locateExpectingFailure('Rua Ernesto do Canto 10, Ponta Delgada', 'Açores');

        Http::assertSentCount(1);
    }

    #[Test]
    public function addresses_with_only_markers_make_no_requests(): void
    {
        $this->fakeFound();

        $this->assertNull($this->geocoder()->locate('r/c', 'Lisboa'));
        $this->assertNull($this->geocoder()->geocode('3º Esq'));

        Http::assertNothingSent();
        Sleep::assertNeverSlept();
    }

    #[Test]
    public function old_cache_entries_without_the_approximate_flag_still_work(): void
    {
        Cache::put('geocoding:'.sha1('rua augusta 1, lisboa'), [
            'latitude' => 38.7084591,
            'longitude' => -9.1365284,
            'display_name' => self::DISPLAY_NAME,
        ]);

        $this->assertLisbonResult($this->geocoder()->geocode('Rua Augusta 1, Lisboa'));
        Http::assertNothingSent();
    }

    /**
     * Respostas por ordem; cada passo [atraso em ms, corpo] avança o relógio antes de responder, para simular um Nominatim lento.
     */
    private function fakeSlowResponses(array $steps): void
    {
        Http::fake(function (Request $request, array $options) use (&$steps) {
            $this->sentOptions[] = $options;
            [$delayMs, $body] = array_shift($steps);

            if ($delayMs > 0) {
                Carbon::setTestNow(now()->addMilliseconds($delayMs));
            }

            return Http::response($body, 200);
        });
    }

    private function assertPrataResult(?GeocodingResult $result): void
    {
        $this->assertInstanceOf(GeocodingResult::class, $result);
        $this->assertSame('Rua da Prata, Baixa, Lisboa, 1100-420, Portugal', $result->displayName);
        $this->assertTrue($result->approximate);
    }

    #[Test]
    public function alternative_attempts_use_shorter_timeouts(): void
    {
        config(['services.nominatim.timeout' => 5]);
        $this->fakeSlowResponses([[0, []], [0, []], [0, [self::ruaNova()]]]);

        $this->geocoder()->locate('Edifício Atlântico, Rua Nova 12, Bairro Alto, 1200-001 Lisboa', 'Lisboa');

        $this->assertSame([5, 2, 2], array_column($this->sentOptions, 'timeout'));
        $this->assertSame([3, 2, 2], array_column($this->sentOptions, 'connect_timeout'));
    }

    #[Test]
    public function geocode_keeps_the_configured_timeouts(): void
    {
        config(['services.nominatim.timeout' => 5]);
        $this->fakeSlowResponses([[0, [self::place()]]]);

        $this->geocoder()->geocode('Rua Augusta 1, Lisboa');

        $this->assertSame([5], array_column($this->sentOptions, 'timeout'));
        $this->assertSame([3], array_column($this->sentOptions, 'connect_timeout'));
    }

    #[Test]
    public function the_deadline_stops_the_cascade_and_returns_the_fallback_without_caching_it(): void
    {
        $this->freezeTime();
        $this->fakeSlowResponses([[5500, [self::prata()]], [0, [self::place()]]]);

        $this->assertPrataResult($this->geocoder()->locate('Rua Augusta 100, 1100-053 Lisboa', 'Lisboa'));
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();

        $this->travel(1)->seconds();
        $this->assertLisbonResult($this->geocoder()->locate('Rua Augusta 100, 1100-053 Lisboa', 'Lisboa'));
        Http::assertSentCount(2);
    }

    #[Test]
    public function the_deadline_without_a_fallback_throws_geocoding_unavailable_without_caching(): void
    {
        $this->freezeTime();
        $this->fakeSlowResponses([[5500, []], [0, [self::place()]]]);

        $this->assertNull($this->locateExpectingFailure('Rua Augusta 100, 1100-053 Lisboa', 'Lisboa')->httpStatus);
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();

        $this->travel(1)->seconds();
        $this->assertLisbonResult($this->geocoder()->locate('Rua Augusta 100, 1100-053 Lisboa', 'Lisboa'));
        Http::assertSentCount(2);
    }

    #[Test]
    public function an_attempt_only_starts_while_it_fits_in_the_deadline(): void
    {
        $this->freezeTime();
        $this->fakeSlowResponses([[5000, []], [500, []], [0, [self::ruaNova()]]]);

        $this->locateExpectingFailure('Edifício Atlântico, Rua Nova 12, Bairro Alto, 1200-001 Lisboa', 'Lisboa');

        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(1)->second()]);
    }

    #[Test]
    public function the_fallback_is_kept_when_another_request_takes_the_slot_during_the_wait(): void
    {
        $this->freezeTime();
        Sleep::whenFakingSleep(fn () => RateLimiter::hit('nominatim', 1));
        Http::fake([self::URL_PATTERN => Http::sequence()
            ->push([self::prata()], 200)
            ->push([self::place()], 200)]);

        $this->assertPrataResult($this->geocoder()->locate('Rua Augusta 100, 1100-053 Lisboa', 'Lisboa'));
        Http::assertSentCount(1);

        $this->travel(1)->seconds();
        $this->geocoder()->locate('Rua Augusta 100, 1100-053 Lisboa', 'Lisboa');
        Http::assertSentCount(2);
    }

    public static function failuresOnTheSecondAttempt(): array
    {
        return [
            'erro 500' => ['500'],
            'sem ligação' => ['connection'],
            'formato inválido' => ['html'],
        ];
    }

    #[Test]
    #[DataProvider('failuresOnTheSecondAttempt')]
    public function the_fallback_is_kept_when_the_second_attempt_fails(string $failure): void
    {
        $this->freezeTime();
        $sequence = Http::sequence()->push([self::prata()], 200);

        match ($failure) {
            '500' => $sequence->push('', 500),
            'connection' => $sequence->pushFailedConnection(),
            'html' => $sequence->push('<html>', 200),
        };

        $sequence->push([self::place()], 200);
        Http::fake([self::URL_PATTERN => $sequence]);

        $this->assertPrataResult($this->geocoder()->locate('Rua Augusta 100, 1100-053 Lisboa', 'Lisboa'));
        Http::assertSentCount(2);

        $this->travel(1)->seconds();
        $this->assertLisbonResult($this->geocoder()->locate('Rua Augusta 100, 1100-053 Lisboa', 'Lisboa'));
        Http::assertSentCount(3);
    }
}
