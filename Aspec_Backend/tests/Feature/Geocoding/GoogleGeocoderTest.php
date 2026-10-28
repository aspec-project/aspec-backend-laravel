<?php

namespace Tests\Feature\Geocoding;

use App\Contracts\Geocoder;
use App\Exceptions\GeocodingUnavailable;
use App\Services\Geocoding\Data\GeocodingResult;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Serviço de geocodificação com a Google.
 * Nunca há pedidos reais: todas as respostas são simuladas com Http::fake() e a chave é fictícia.
 */
class GoogleGeocoderTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-google-key-7f3a9c';

    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    private const URL_PATTERN = 'maps.googleapis.com/maps/api/geocode/json*';

    private const COIMBRA_ADDRESS = 'Rua do Brasil nº 25, 3º Esq, Coimbra';

    private const COIMBRA_PLACE_ID = 'ChIJTesteCoimbraRuaBrasil25_Aspec';

    private const COIMBRA_DISPLAY_NAME = 'R. do Brasil 25, 3030-175 Coimbra, Portugal';

    /** @var list<MessageLogged> */
    private array $logged = [];

    /** Opções do Guzzle (timeouts) de cada pedido simulado. */
    private array $sentOptions = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        config([
            'logging.default' => 'null',
            'services.geocoding.driver' => 'google',
            'services.google_maps.geocoding_key' => self::KEY,
            'services.google_maps.cache_days' => 30,
            'services.google_maps.timeout' => 5,
        ]);
        Event::listen(MessageLogged::class, fn (MessageLogged $event) => $this->logged[] = $event);
    }

    private static function coimbraRooftop(array $overrides = [], array $without = []): array
    {
        $result = array_merge([
            'place_id' => self::COIMBRA_PLACE_ID,
            'formatted_address' => self::COIMBRA_DISPLAY_NAME,
            'types' => ['street_address'],
            'geometry' => ['location' => ['lat' => 40.2085123, 'lng' => -8.4198765], 'location_type' => 'ROOFTOP'],
            'address_components' => [
                ['long_name' => '25', 'short_name' => '25', 'types' => ['street_number']],
                ['long_name' => 'Rua do Brasil', 'short_name' => 'R. do Brasil', 'types' => ['route']],
                ['long_name' => 'Coimbra', 'short_name' => 'Coimbra', 'types' => ['locality', 'political']],
                ['long_name' => 'Portugal', 'short_name' => 'PT', 'types' => ['country', 'political']],
            ],
        ], $overrides);

        foreach ($without as $field) {
            unset($result[$field]);
        }

        return ['status' => 'OK', 'results' => [$result]];
    }

    private static function pontaDelgadaApproximate(array $overrides = []): array
    {
        return ['status' => 'OK', 'results' => [array_merge([
            'place_id' => 'ChIJTestePontaDelgadaCidade_Aspec',
            'formatted_address' => 'Ponta Delgada, Portugal',
            'partial_match' => true,
            'types' => ['locality', 'political'],
            'geometry' => ['location' => ['lat' => 37.7412345, 'lng' => -25.6756789], 'location_type' => 'APPROXIMATE'],
            'address_components' => [
                ['long_name' => 'Ponta Delgada', 'short_name' => 'Ponta Delgada', 'types' => ['locality', 'political']],
                ['long_name' => 'Portugal', 'short_name' => 'PT', 'types' => ['country', 'political']],
            ],
        ], $overrides)]];
    }

    private static function coimbraWithLocationType(?string $locationType): array
    {
        $geometry = ['location' => ['lat' => 40.2085123, 'lng' => -8.4198765]];

        if ($locationType !== null) {
            $geometry['location_type'] = $locationType;
        }

        return self::coimbraRooftop(['geometry' => $geometry]);
    }

    private function fakeGoogle(array|string $body, int $status = 200, array $headers = []): void
    {
        Http::fake([self::URL_PATTERN => Http::response($body, $status, $headers)]);
    }

    private function geocoder(): Geocoder
    {
        return app(Geocoder::class);
    }

    private function locateCoimbra(): ?GeocodingResult
    {
        return $this->geocoder()->locate(self::COIMBRA_ADDRESS, 'Coimbra');
    }

    private function locateExpectingFailure(string $address = self::COIMBRA_ADDRESS, ?string $district = 'Coimbra'): GeocodingUnavailable
    {
        try {
            $this->geocoder()->locate($address, $district);
        } catch (GeocodingUnavailable $e) {
            return $e;
        }

        $this->fail('Era esperada uma GeocodingUnavailable.');
    }

    private function queryOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    private static function coimbraLocateCacheKey(): string
    {
        return 'geocoding:google:locate:'.sha1(mb_strtolower('Rua do Brasil 25, Coimbra|Coimbra'));
    }

    /** Texto de todos os registos de log, com as exceções do contexto (e a cadeia previous) convertidas em texto. */
    private function loggedText(): string
    {
        return implode("\n", array_map(function (MessageLogged $event) {
            $context = $event->context;
            array_walk_recursive($context, function (&$value) {
                if ($value instanceof Throwable) {
                    $value = (string) $value;
                } elseif (is_object($value)) {
                    $value = $value::class;
                }
            });

            return $event->message.' '.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, $this->logged));
    }

    /** @return list<MessageLogged> */
    private function warnings(): array
    {
        return array_values(array_filter($this->logged, fn (MessageLogged $event) => $event->level === 'warning'));
    }

    private function assertWarningWithStatus(string $status): void
    {
        $this->assertContains(
            ['status' => $status],
            array_map(fn (MessageLogged $event) => $event->context, $this->warnings()),
        );
    }

    private function assertKeyNotLogged(): void
    {
        $this->assertStringNotContainsString(self::KEY, $this->loggedText());
        $this->assertStringNotContainsString('maps.googleapis.com', $this->loggedText());
    }

    private function fakeConnectionFailureWithTheKey(): void
    {
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out after 5000 milliseconds for '.self::ENDPOINT.'?address=Rua&key='.self::KEY
        ));
    }

    #[Test]
    public function locate_sends_the_cleaned_address_with_the_google_parameters(): void
    {
        $this->fakeGoogle(self::coimbraRooftop());

        $this->locateCoimbra();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);
            ksort($query);

            return $request->method() === 'GET'
                && strtok($request->url(), '?') === self::ENDPOINT
                && $query === [
                    'address' => 'Rua do Brasil 25, Coimbra, Coimbra',
                    'components' => 'country:PT',
                    'key' => self::KEY,
                    'language' => 'pt-PT',
                    'region' => 'pt',
                ];
        });
    }

    #[Test]
    public function a_rooftop_result_is_verified_and_has_the_place_id_without_coordinates(): void
    {
        $this->fakeGoogle(self::coimbraRooftop());

        $result = $this->locateCoimbra();

        $this->assertInstanceOf(GeocodingResult::class, $result);
        $this->assertSame(self::COIMBRA_PLACE_ID, $result->placeId);
        $this->assertSame(self::COIMBRA_DISPLAY_NAME, $result->displayName);
        $this->assertFalse($result->approximate);
        $this->assertNull($result->latitude);
        $this->assertNull($result->longitude);
    }

    #[Test]
    public function a_city_only_result_is_approximate(): void
    {
        $this->fakeGoogle(self::pontaDelgadaApproximate());

        $result = $this->geocoder()->locate('Rua da Sé 10, Ponta Delgada, Região Autónoma dos Açores', 'Açores');

        $this->assertInstanceOf(GeocodingResult::class, $result);
        $this->assertTrue($result->approximate);
        $this->assertSame('ChIJTestePontaDelgadaCidade_Aspec', $result->placeId);
        $this->assertNull($result->latitude);
        $this->assertNull($result->longitude);
    }

    public static function locationTypes(): array
    {
        return [
            'ROOFTOP' => ['ROOFTOP', false],
            'RANGE_INTERPOLATED' => ['RANGE_INTERPOLATED', false],
            'GEOMETRIC_CENTER' => ['GEOMETRIC_CENTER', true],
            'APPROXIMATE' => ['APPROXIMATE', true],
            'tipo desconhecido' => ['OUTRO', true],
            'sem location_type' => [null, true],
        ];
    }

    #[Test]
    #[DataProvider('locationTypes')]
    public function only_precise_location_types_are_verified(?string $locationType, bool $approximate): void
    {
        $this->fakeGoogle(self::coimbraWithLocationType($locationType));

        $this->assertSame($approximate, $this->locateCoimbra()?->approximate);
    }

    #[Test]
    public function a_partial_match_is_approximate_even_when_rooftop(): void
    {
        $this->fakeGoogle(self::coimbraRooftop(['partial_match' => true]));

        $this->assertTrue($this->locateCoimbra()?->approximate);
    }

    #[Test]
    public function zero_results_returns_null_and_is_not_cached(): void
    {
        $this->fakeGoogle(['status' => 'ZERO_RESULTS', 'results' => []]);

        $this->assertNull($this->locateCoimbra());
        $this->assertNull($this->locateCoimbra());

        Http::assertSentCount(2);
    }

    #[Test]
    public function invalid_request_returns_null_and_logs_only_the_status(): void
    {
        $this->fakeGoogle(['status' => 'INVALID_REQUEST', 'results' => []]);

        $this->assertNull($this->locateCoimbra());

        $this->assertWarningWithStatus('INVALID_REQUEST');
    }

    public static function unavailableStatuses(): array
    {
        return [
            'OVER_QUERY_LIMIT' => [['status' => 'OVER_QUERY_LIMIT', 'results' => []], 'OVER_QUERY_LIMIT'],
            'OVER_DAILY_LIMIT' => [['status' => 'OVER_DAILY_LIMIT', 'results' => []], 'OVER_DAILY_LIMIT'],
            'REQUEST_DENIED' => [['status' => 'REQUEST_DENIED', 'results' => []], 'REQUEST_DENIED'],
            'UNKNOWN_ERROR' => [['status' => 'UNKNOWN_ERROR', 'results' => []], false],
            'estado desconhecido' => [['status' => 'FOO', 'results' => []], null],
            'sem status' => [['results' => []], null],
        ];
    }

    /**
     * @param  string|false|null  $warning  Estado esperado no warning, false quando não pode haver warning, null quando não se verifica.
     */
    #[Test]
    #[DataProvider('unavailableStatuses')]
    public function google_error_statuses_throw_geocoding_unavailable(array $body, string|false|null $warning): void
    {
        $this->fakeGoogle($body);

        $this->assertInstanceOf(GeocodingUnavailable::class, $this->locateExpectingFailure());

        if (is_string($warning)) {
            $this->assertWarningWithStatus($warning);
        } elseif ($warning === false) {
            $this->assertSame([], $this->warnings());
        }
    }

    public static function httpErrors(): array
    {
        return [
            'HTTP 500' => [500, []],
            'HTTP 403' => [403, []],
            'HTTP 429' => [429, []],
            'redirecionamento 302' => [302, ['Location' => 'https://outro.example/']],
        ];
    }

    #[Test]
    #[DataProvider('httpErrors')]
    public function http_errors_throw_geocoding_unavailable_without_retrying_or_following_redirects(int $status, array $headers): void
    {
        $this->fakeGoogle('', $status, $headers);

        $exception = $this->locateExpectingFailure();

        $this->assertSame($status, $exception->httpStatus);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'outro.example'));
    }

    public static function invalidResponses(): array
    {
        return [
            'corpo que não é JSON' => ['texto'],
            'OK sem resultados' => [['status' => 'OK', 'results' => []]],
            'sem place_id' => [self::coimbraRooftop([], ['place_id'])],
            'place_id com caracteres proibidos' => [self::coimbraRooftop(['place_id' => 'abc/../x?y'])],
            'place_id com 513 caracteres' => [self::coimbraRooftop(['place_id' => str_repeat('a', 513)])],
            'formatted_address vazio' => [self::coimbraRooftop(['formatted_address' => ''])],
            'formatted_address numérico' => [self::coimbraRooftop(['formatted_address' => 25])],
        ];
    }

    #[Test]
    #[DataProvider('invalidResponses')]
    public function invalid_responses_throw_geocoding_unavailable(array|string $body): void
    {
        $this->fakeGoogle($body);

        $this->assertInstanceOf(GeocodingUnavailable::class, $this->locateExpectingFailure());
    }

    #[Test]
    public function addresses_with_only_markers_make_no_requests(): void
    {
        $this->fakeGoogle(self::coimbraRooftop());

        $this->assertNull($this->geocoder()->locate('r/c', 'Lisboa'));
        $this->assertNull($this->geocoder()->geocode('r/c'));

        Http::assertNothingSent();
    }

    #[Test]
    public function the_cache_keeps_the_place_id_and_address_but_never_coordinates(): void
    {
        $this->fakeGoogle(self::coimbraRooftop());

        $this->locateCoimbra();

        $cached = Cache::get(self::coimbraLocateCacheKey());
        $this->assertSame([
            'latitude' => null,
            'longitude' => null,
            'display_name' => self::COIMBRA_DISPLAY_NAME,
            'approximate' => false,
            'place_id' => self::COIMBRA_PLACE_ID,
        ], $cached);
        $this->assertStringNotContainsString('40.208', json_encode($cached));
        $this->assertStringNotContainsString('-8.419', json_encode($cached));

        $this->assertSame(self::COIMBRA_PLACE_ID, $this->locateCoimbra()?->placeId);
        Http::assertSentCount(1);
    }

    #[Test]
    public function the_cache_expires_after_thirty_days(): void
    {
        $this->freezeTime();
        $this->fakeGoogle(self::coimbraRooftop());

        $this->locateCoimbra();
        $this->travel(30)->days();
        $this->travel(-1)->minutes();
        $this->locateCoimbra();

        Http::assertSentCount(1);

        $this->travel(2)->minutes();
        $this->locateCoimbra();

        Http::assertSentCount(2);
    }

    #[Test]
    public function the_cache_never_lasts_more_than_thirty_days_even_if_configured(): void
    {
        config(['services.google_maps.cache_days' => 90]);
        $this->freezeTime();
        $this->fakeGoogle(self::coimbraRooftop());

        $this->locateCoimbra();
        $this->travel(31)->days();
        $this->locateCoimbra();

        Http::assertSentCount(2);
    }

    #[Test]
    public function google_and_nominatim_never_read_each_other_cache(): void
    {
        $locateHash = sha1(mb_strtolower('Rua do Brasil 25, Coimbra|Coimbra'));
        Cache::put('geocoding:locate:'.$locateHash, [
            'latitude' => 40.2085123,
            'longitude' => -8.4198765,
            'display_name' => '25, Rua do Brasil, Alta, Coimbra, 3030-175, Portugal',
            'approximate' => false,
        ], now()->addDay());
        $this->fakeGoogle(self::coimbraRooftop());

        $this->assertSame(self::COIMBRA_PLACE_ID, $this->locateCoimbra()?->placeId);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::ENDPOINT));

        config([
            'services.geocoding.driver' => 'nominatim',
            'services.nominatim.base_url' => 'https://nominatim.test',
            'services.nominatim.contact_email' => null,
        ]);
        Cache::forget('geocoding:locate:'.$locateHash);
        Http::fake(['nominatim.test/*' => Http::response([[
            'lat' => '40.2085123',
            'lon' => '-8.4198765',
            'display_name' => '25, Rua do Brasil, Alta, Coimbra, 3030-175, Portugal',
            'address' => [
                'house_number' => '25',
                'road' => 'Rua do Brasil',
                'city' => 'Coimbra',
                'ISO3166-2-lvl6' => 'PT-06',
                'country_code' => 'pt',
            ],
        ]], 200)]);

        $this->assertTrue(Cache::has(self::coimbraLocateCacheKey()));
        $this->assertNull($this->locateCoimbra()?->placeId);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://nominatim.test/'));
    }

    #[Test]
    public function a_connection_failure_never_exposes_the_key(): void
    {
        $this->fakeConnectionFailureWithTheKey();

        $exception = $this->locateExpectingFailure();

        $this->assertSame('Sem resposta da Google.', $exception->getMessage());
        $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
        $this->assertNull($exception->getPrevious());
        $this->assertKeyNotLogged();
    }

    #[Test]
    public function an_http_error_never_exposes_the_key_or_the_body(): void
    {
        $this->fakeGoogle('Internal error for /maps/api/geocode/json?key='.self::KEY, 500);

        $exception = $this->locateExpectingFailure();

        $this->assertSame('A Google respondeu com erro.', $exception->getMessage());
        $this->assertSame(500, $exception->httpStatus);
        $this->assertNull($exception->getPrevious());
        $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
        $this->assertKeyNotLogged();
        $this->assertStringNotContainsString('Internal error', $this->loggedText());
    }

    #[Test]
    public function a_guzzle_exception_never_exposes_the_key(): void
    {
        Http::fake(fn (Request $request) => throw new TransferException('Falha para '.self::ENDPOINT.'?key='.self::KEY, $request->toPsrRequest()));

        $exception = $this->locateExpectingFailure();

        $this->assertSame('Sem resposta da Google.', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
        $this->assertKeyNotLogged();
    }

    #[Test]
    public function google_error_statuses_log_only_the_status(): void
    {
        $this->fakeGoogle([
            'status' => 'REQUEST_DENIED',
            'error_message' => 'The provided API key is invalid: '.self::KEY,
            'results' => [],
        ]);

        $this->locateExpectingFailure();

        $text = $this->loggedText();
        $this->assertStringNotContainsString(self::KEY, $text);
        $this->assertStringNotContainsString('Rua do Brasil', $text);
        $this->assertStringNotContainsString('The provided API key is invalid', $text);
        $this->assertCount(1, $this->logged);
        $this->assertSame('warning', $this->logged[0]->level);
        $this->assertSame(['status' => 'REQUEST_DENIED'], $this->logged[0]->context);
    }

    #[Test]
    public function the_exception_is_safe_for_the_default_exception_report(): void
    {
        $this->fakeConnectionFailureWithTheKey();
        $exception = $this->locateExpectingFailure();
        $this->assertSame('Sem resposta da Google.', $exception->getMessage());

        report($exception);

        $this->assertNotEmpty($this->logged);
        $this->assertStringNotContainsString(self::KEY, $this->loggedText());
    }

    #[Test]
    public function geocode_cleans_the_address_makes_one_request_and_caches_the_result(): void
    {
        $this->fakeGoogle(self::coimbraRooftop());

        $result = $this->geocoder()->geocode(self::COIMBRA_ADDRESS);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => ($this->queryOf($request)['address'] ?? null) === 'Rua do Brasil 25, Coimbra');
        $this->assertSame(self::COIMBRA_PLACE_ID, $result?->placeId);
        $this->assertNull($result->latitude);
        $this->assertNull($result->longitude);
        $this->assertTrue(Cache::has('geocoding:google:'.sha1(mb_strtolower('Rua do Brasil 25, Coimbra'))));
    }

    #[Test]
    public function google_does_not_use_the_nominatim_one_request_per_second_limit(): void
    {
        $this->fakeGoogle(self::coimbraRooftop());

        $this->assertNotNull($this->locateCoimbra());
        $this->assertNotNull($this->geocoder()->locate('Rua Augusta 1', 'Lisboa'));

        Http::assertSentCount(2);
        $this->assertSame(0, RateLimiter::attempts('nominatim'));
    }

    public static function timeouts(): array
    {
        return [
            'timeout por omissão' => [5, 5, 3],
            'timeout curto' => [2, 2, 2],
        ];
    }

    #[Test]
    #[DataProvider('timeouts')]
    public function requests_use_the_configured_timeouts(int $configured, int $timeout, int $connectTimeout): void
    {
        config(['services.google_maps.timeout' => $configured]);
        Http::fake(function (Request $request, array $options) {
            $this->sentOptions[] = $options;

            return Http::response(self::coimbraRooftop(), 200);
        });

        $this->locateCoimbra();

        $this->assertSame([$timeout], array_column($this->sentOptions, 'timeout'));
        $this->assertSame([$connectTimeout], array_column($this->sentOptions, 'connect_timeout'));
    }
}
