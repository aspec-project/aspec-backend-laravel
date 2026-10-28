<?php

namespace Tests\Feature\Admin;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Endpoint do backoffice para localizar moradas no mapa.
 * Nunca há pedidos reais: o Nominatim é simulado com Http::fake().
 */
class AdminGeocodeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/geocode';

    private const URL_PATTERN = 'nominatim.test/*';

    private const DISPLAY_NAME = 'Rua Augusta, Baixa, Santa Maria Maior, Lisboa, 1100-053, Portugal';

    private const NOT_FOUND_MESSAGE = 'Não foi possível encontrar esta morada no mapa. Verifique a morada e tente novamente.';

    private const UNAVAILABLE_MESSAGE = 'O serviço de localização não está disponível de momento. Tente novamente dentro de instantes.';

    private const GOOGLE_KEY = 'test-google-key-7f3a9c';

    private const GOOGLE_URL_PATTERN = 'maps.googleapis.com/maps/api/geocode/json*';

    private const COIMBRA_PLACE_ID = 'ChIJTesteCoimbraRuaBrasil25_Aspec';

    private const COIMBRA_FORMATTED_ADDRESS = 'R. do Brasil 25, 3030-175 Coimbra, Portugal';

    private const PONTA_DELGADA_PLACE_ID = 'ChIJTestePontaDelgadaCidade_Aspec';

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

    private function fakeFound(): void
    {
        Http::fake([self::URL_PATTERN => Http::response([[
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
        ]], 200)]);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function geocode(array $query): TestResponse
    {
        return $this->getJson(self::URL.'?'.http_build_query($query));
    }

    private function assertSentQuery(string $expected): void
    {
        Http::assertSent(function (Request $request) use ($expected) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['q'] ?? null) === $expected;
        });
    }

    #[Test]
    public function admin_gets_the_coordinates_of_an_address_with_its_district(): void
    {
        $this->actingAsAdmin();
        $this->fakeFound();
        $lisboa = Location::where('name', 'Lisboa')->firstOrFail();

        $this->geocode(['address' => 'Rua Augusta 1', 'location_id' => $lisboa->id])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Morada localizada com sucesso.',
                'data' => [
                    'latitude' => 38.7084591,
                    'longitude' => -9.1365284,
                    'display_name' => self::DISPLAY_NAME,
                    'attribution' => '© OpenStreetMap contributors',
                    'approximate' => false,
                    'place_id' => null,
                ],
            ]);

        $this->assertSentQuery('Rua Augusta 1, Lisboa');
    }

    #[Test]
    public function address_without_district_is_sent_as_typed_and_squished(): void
    {
        $this->actingAsAdmin();
        $this->fakeFound();

        $this->geocode(['address' => '  Rua   Augusta 1 '])->assertOk();

        $this->assertSentQuery('Rua Augusta 1');
    }

    #[Test]
    public function unknown_address_returns_404(): void
    {
        $this->actingAsAdmin();
        Http::fake([self::URL_PATTERN => Http::response([], 200)]);

        $this->geocode(['address' => 'Rua Inexistente 99'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => self::NOT_FOUND_MESSAGE]);
    }

    #[Test]
    public function nominatim_failure_returns_503(): void
    {
        $this->freezeTime();
        $this->actingAsAdmin();
        Http::fake([self::URL_PATTERN => Http::sequence()
            ->push('', 500)
            ->pushFailedConnection()]);

        $this->geocode(['address' => 'Rua Augusta 1'])
            ->assertServiceUnavailable()
            ->assertExactJson(['success' => false, 'message' => self::UNAVAILABLE_MESSAGE]);

        $this->travel(1)->seconds();
        $this->geocode(['address' => 'Rua Augusta 1'])
            ->assertServiceUnavailable()
            ->assertExactJson(['success' => false, 'message' => self::UNAVAILABLE_MESSAGE]);

        Http::assertSentCount(2);
    }

    #[Test]
    public function invalid_input_returns_422_without_calling_nominatim(): void
    {
        $this->actingAsAdmin();
        $this->fakeFound();

        $cases = [
            [[], 'address', 'O campo morada é obrigatório.'],
            [['address' => '   '], 'address', 'O campo morada é obrigatório.'],
            [['address' => str_repeat('a', 256)], 'address', 'O campo morada não pode ter mais de 255 caracteres.'],
            [['address' => 'Rua Augusta 1', 'location_id' => 'nao-e-uuid'], 'location_id', 'O campo distrito tem de ser um UUID válido.'],
            [['address' => 'Rua Augusta 1', 'location_id' => (string) Str::uuid()], 'location_id', 'O valor selecionado para distrito é inválido.'],
        ];

        foreach ($cases as [$query, $field, $message]) {
            $this->geocode($query)
                ->assertUnprocessable()
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'Os dados enviados são inválidos.')
                ->assertJsonPath("errors.{$field}.0", $message);
        }

        Http::assertNothingSent();

        $this->geocode(['address' => str_repeat('a', 255)])->assertOk();
    }

    #[Test]
    public function only_active_admins_can_geocode(): void
    {
        $this->fakeFound();

        $this->geocode(['address' => 'Rua Augusta 1'])
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Não autenticado.']);

        Sanctum::actingAs(User::factory()->create());
        $this->geocode(['address' => 'Rua Augusta 1'])
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Não tem permissão para realizar esta ação.']);

        Sanctum::actingAs(User::factory()->admin()->inactive()->create());
        $this->geocode(['address' => 'Rua Augusta 1'])
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'A conta não está ativa e não pode editar dados.']);

        Http::assertNothingSent();
    }

    #[Test]
    public function geocode_route_is_rate_limited_per_admin(): void
    {
        $this->freezeTime();
        $this->fakeFound();
        $this->actingAsAdmin();

        for ($i = 1; $i <= 30; $i++) {
            $this->geocode(['address' => 'Rua Augusta 1'])->assertOk();
        }

        Http::assertSentCount(1);

        $this->geocode(['address' => 'Rua Augusta 1'])
            ->assertTooManyRequests()
            ->assertJson(['success' => false, 'message' => 'Demasiados pedidos. Tente novamente mais tarde.']);

        $this->actingAsAdmin();
        $this->geocode(['address' => 'Rua Augusta 1'])->assertOk();
    }

    #[Test]
    public function response_exposes_only_the_documented_fields(): void
    {
        $this->actingAsAdmin();
        $this->fakeFound();

        $response = $this->geocode(['address' => 'Rua Augusta 1'])->assertOk();

        $this->assertSame(
            ['latitude', 'longitude', 'display_name', 'attribution', 'approximate', 'place_id'],
            array_keys($response->json('data'))
        );
        $this->assertNull($response->json('data.place_id'));
        $this->assertStringNotContainsString('osm_id', $response->getContent());
        $this->assertStringNotContainsString('licence', $response->getContent());
        $this->assertStringNotContainsString('boundingbox', $response->getContent());
    }

    private function sentQueries(): array
    {
        return Http::recorded()
            ->map(function (array $pair) {
                parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

                return $query['q'] ?? null;
            })
            ->values()
            ->all();
    }

    private function locationId(string $name): string
    {
        return Location::where('name', $name)->firstOrFail()->id;
    }

    #[Test]
    public function azores_address_returns_an_approximate_location_after_the_search_without_district(): void
    {
        $this->actingAsAdmin();
        Http::fake([self::URL_PATTERN => Http::sequence()
            ->push([], 200)
            ->push([[
                'lat' => '37.7412345',
                'lon' => '-25.6712345',
                'display_name' => 'Rua da Guiné, Ponta Delgada, Açores, 9500-000, Portugal',
                'address' => [
                    'road' => 'Rua da Guiné',
                    'city' => 'Ponta Delgada',
                    'ISO3166-2-lvl4' => 'PT-20',
                    'country_code' => 'pt',
                ],
            ]], 200)]);

        $this->geocode(['address' => 'Rua da Sé 10, Ponta Delgada', 'location_id' => $this->locationId('Açores')])
            ->assertOk()
            ->assertJsonPath('message', 'Foi encontrado um local aproximado. Confirme se corresponde à morada.')
            ->assertJsonPath('data.approximate', true)
            ->assertJsonPath('data.display_name', 'Rua da Guiné, Ponta Delgada, Açores, 9500-000, Portugal');

        $this->assertSame(
            ['Rua da Sé 10, Ponta Delgada, Açores', 'Rua da Sé 10, Ponta Delgada'],
            $this->sentQueries()
        );
    }

    #[Test]
    public function address_is_cleaned_before_searching(): void
    {
        $this->actingAsAdmin();
        Http::fake([self::URL_PATTERN => Http::response([[
            'lat' => '40.2085123',
            'lon' => '-8.4198765',
            'display_name' => '25, Rua do Brasil, Alta, Coimbra, 3030-175, Portugal',
            'address' => [
                'house_number' => '25',
                'road' => 'Rua do Brasil',
                'city' => 'Coimbra',
                'ISO3166-2-lvl6' => 'PT-06',
                'postcode' => '3030-175',
                'country_code' => 'pt',
            ],
        ]], 200)]);

        $this->geocode(['address' => 'Rua do Brasil nº 25, 3º Esq, Coimbra', 'location_id' => $this->locationId('Coimbra')])
            ->assertOk()
            ->assertJsonPath('message', 'Morada localizada com sucesso.')
            ->assertJsonPath('data.approximate', false);

        $this->assertSame(['Rua do Brasil 25, Coimbra, Coimbra'], $this->sentQueries());
    }

    #[Test]
    public function address_not_found_in_any_attempt_returns_404(): void
    {
        $this->actingAsAdmin();
        Http::fake([self::URL_PATTERN => Http::response([], 200)]);

        $this->geocode(['address' => 'Rua Augusta 100, 1100-053 Lisboa', 'location_id' => $this->locationId('Lisboa')])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => self::NOT_FOUND_MESSAGE]);

        Http::assertSentCount(3);
    }

    #[Test]
    public function nominatim_failure_during_the_cascade_returns_503(): void
    {
        $this->actingAsAdmin();
        Http::fake([self::URL_PATTERN => Http::sequence()
            ->push([], 200)
            ->push('', 500)]);

        $this->geocode(['address' => 'Rua Augusta 100, 1100-053 Lisboa', 'location_id' => $this->locationId('Lisboa')])
            ->assertServiceUnavailable()
            ->assertExactJson(['success' => false, 'message' => self::UNAVAILABLE_MESSAGE]);
    }

    #[Test]
    public function address_with_only_markers_returns_404_without_calling_nominatim(): void
    {
        $this->actingAsAdmin();
        $this->fakeFound();

        $this->geocode(['address' => 'r/c'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => self::NOT_FOUND_MESSAGE]);

        Http::assertNothingSent();
    }

    private static function prataOccurrence(): array
    {
        return [
            'lat' => '38.7105000',
            'lon' => '-9.1360000',
            'display_name' => 'Rua da Prata, Baixa, Lisboa, 1100-420, Portugal',
            'address' => [
                'road' => 'Rua da Prata',
                'city' => 'Lisboa',
                'ISO3166-2-lvl6' => 'PT-11',
                'country_code' => 'pt',
            ],
        ];
    }

    #[Test]
    public function approximate_location_is_returned_when_a_later_attempt_fails(): void
    {
        $this->freezeTime();
        $this->actingAsAdmin();
        Http::fake([self::URL_PATTERN => Http::sequence()
            ->push([self::prataOccurrence()], 200)
            ->push('', 500)]);

        $this->geocode(['address' => 'Rua Augusta 100, 1100-053 Lisboa', 'location_id' => $this->locationId('Lisboa')])
            ->assertOk()
            ->assertJsonPath('message', 'Foi encontrado um local aproximado. Confirme se corresponde à morada.')
            ->assertJsonPath('data.approximate', true);
    }

    #[Test]
    public function cascade_cut_by_the_deadline_without_a_location_returns_503(): void
    {
        $this->freezeTime();
        $this->actingAsAdmin();
        Http::fake(function () {
            Carbon::setTestNow(now()->addMilliseconds(5500));

            return Http::response([], 200);
        });

        $this->geocode(['address' => 'Rua Augusta 100, 1100-053 Lisboa', 'location_id' => $this->locationId('Lisboa')])
            ->assertServiceUnavailable()
            ->assertExactJson(['success' => false, 'message' => self::UNAVAILABLE_MESSAGE]);
    }

    private function useGoogle(): void
    {
        config([
            'services.geocoding.driver' => 'google',
            'services.google_maps.geocoding_key' => self::GOOGLE_KEY,
            'services.google_maps.cache_days' => 30,
            'services.google_maps.timeout' => 5,
        ]);
    }

    #[Test]
    public function google_driver_locates_the_address_through_the_endpoint(): void
    {
        $this->actingAsAdmin();
        $this->useGoogle();
        Http::fake(['maps.googleapis.com/maps/api/geocode/json*' => Http::response(['status' => 'OK', 'results' => [[
            'place_id' => 'ChIJTesteCoimbraRuaBrasil25_Aspec',
            'formatted_address' => 'R. do Brasil 25, 3030-175 Coimbra, Portugal',
            'types' => ['street_address'],
            'geometry' => ['location' => ['lat' => 40.2085123, 'lng' => -8.4198765], 'location_type' => 'ROOFTOP'],
        ]]], 200)]);

        $response = $this->geocode(['address' => 'Rua do Brasil nº 25, 3º Esq', 'location_id' => $this->locationId('Coimbra')])
            ->assertOk()
            ->assertJsonPath('message', 'Morada localizada com sucesso.');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://maps.googleapis.com/'));
        $this->assertStringNotContainsString(self::GOOGLE_KEY, $response->getContent());
    }

    #[Test]
    public function google_connection_failure_returns_503_without_exposing_the_key_even_in_debug(): void
    {
        config(['app.debug' => true, 'logging.default' => 'null']);
        $this->actingAsAdmin();
        $this->useGoogle();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode(array_map(
                fn ($value) => $value instanceof Throwable ? (string) $value : $value,
                $event->context
            ), JSON_UNESCAPED_SLASHES);
        });
        $requestedUrls = [];
        Http::fake(function (Request $request) use (&$requestedUrls) {
            $requestedUrls[] = $request->url();

            throw new ConnectionException('cURL error 28: Operation timed out for https://maps.googleapis.com/maps/api/geocode/json?key='.self::GOOGLE_KEY);
        });

        $response = $this->geocode(['address' => 'Rua do Brasil 25', 'location_id' => $this->locationId('Coimbra')])
            ->assertServiceUnavailable()
            ->assertExactJson(['success' => false, 'message' => self::UNAVAILABLE_MESSAGE]);

        $this->assertCount(1, $requestedUrls);
        $this->assertStringStartsWith('https://maps.googleapis.com/', $requestedUrls[0]);
        $this->assertStringNotContainsString(self::GOOGLE_KEY, $response->getContent());
        $this->assertStringNotContainsString(self::GOOGLE_KEY, implode("\n", $logged));
    }

    private static function googleCoimbraRooftop(): array
    {
        return ['status' => 'OK', 'results' => [[
            'place_id' => self::COIMBRA_PLACE_ID,
            'formatted_address' => self::COIMBRA_FORMATTED_ADDRESS,
            'types' => ['street_address'],
            'geometry' => ['location' => ['lat' => 40.2085123, 'lng' => -8.4198765], 'location_type' => 'ROOFTOP'],
            'address_components' => [
                ['long_name' => '25', 'short_name' => '25', 'types' => ['street_number']],
                ['long_name' => 'Rua do Brasil', 'short_name' => 'R. do Brasil', 'types' => ['route']],
                ['long_name' => 'Coimbra', 'short_name' => 'Coimbra', 'types' => ['locality', 'political']],
                ['long_name' => 'Portugal', 'short_name' => 'PT', 'types' => ['country', 'political']],
            ],
        ]]];
    }

    private static function googlePontaDelgadaApproximate(): array
    {
        return ['status' => 'OK', 'results' => [[
            'place_id' => self::PONTA_DELGADA_PLACE_ID,
            'formatted_address' => 'Ponta Delgada, Portugal',
            'partial_match' => true,
            'types' => ['locality', 'political'],
            'geometry' => ['location' => ['lat' => 37.7412345, 'lng' => -25.6756789], 'location_type' => 'APPROXIMATE'],
            'address_components' => [
                ['long_name' => 'Ponta Delgada', 'short_name' => 'Ponta Delgada', 'types' => ['locality', 'political']],
                ['long_name' => 'Portugal', 'short_name' => 'PT', 'types' => ['country', 'political']],
            ],
        ]]];
    }

    private static function googleCoimbraData(): array
    {
        return [
            'latitude' => null,
            'longitude' => null,
            'display_name' => self::COIMBRA_FORMATTED_ADDRESS,
            'attribution' => 'Google Maps',
            'approximate' => false,
            'place_id' => self::COIMBRA_PLACE_ID,
        ];
    }

    #[Test]
    public function google_driver_returns_the_place_id_without_coordinates(): void
    {
        $this->actingAsAdmin();
        $this->useGoogle();
        Http::fake([self::GOOGLE_URL_PATTERN => Http::response(self::googleCoimbraRooftop(), 200)]);

        $response = $this->geocode(['address' => 'Rua do Brasil nº 25, 3º Esq', 'location_id' => $this->locationId('Coimbra')])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Morada localizada com sucesso.',
                'data' => self::googleCoimbraData(),
            ]);

        $content = $response->getContent();
        foreach (['40.208', '-8.419', 'geometry', 'location_type', 'address_components', 'partial_match', self::GOOGLE_KEY] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }
    }

    #[Test]
    public function google_driver_returns_an_approximate_place_with_its_place_id(): void
    {
        $this->actingAsAdmin();
        $this->useGoogle();
        Http::fake([self::GOOGLE_URL_PATTERN => Http::response(self::googlePontaDelgadaApproximate(), 200)]);

        $response = $this->geocode([
            'address' => 'Rua da Sé 10, Ponta Delgada, Região Autónoma dos Açores',
            'location_id' => $this->locationId('Açores'),
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Foi encontrado um local aproximado. Confirme se corresponde à morada.')
            ->assertJsonPath('data.approximate', true)
            ->assertJsonPath('data.place_id', self::PONTA_DELGADA_PLACE_ID)
            ->assertJsonPath('data.latitude', null)
            ->assertJsonPath('data.longitude', null)
            ->assertJsonPath('data.attribution', 'Google Maps');

        $this->assertStringNotContainsString('partial_match', $response->getContent());
    }

    #[Test]
    public function google_result_from_the_cache_keeps_the_place_id_in_the_response(): void
    {
        $this->actingAsAdmin();
        $this->useGoogle();
        Http::fake([self::GOOGLE_URL_PATTERN => Http::response(self::googleCoimbraRooftop(), 200)]);
        $query = ['address' => 'Rua do Brasil nº 25, 3º Esq', 'location_id' => $this->locationId('Coimbra')];

        $first = $this->geocode($query)->assertOk();
        $second = $this->geocode($query)->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(self::googleCoimbraData(), $first->json('data'));
        $this->assertSame(self::googleCoimbraData(), $second->json('data'));
    }

    public static function googleErrors(): array
    {
        return [
            'ZERO_RESULTS' => [['status' => 'ZERO_RESULTS', 'results' => []], 200, 404, self::NOT_FOUND_MESSAGE],
            'OVER_QUERY_LIMIT' => [['status' => 'OVER_QUERY_LIMIT', 'results' => []], 200, 503, self::UNAVAILABLE_MESSAGE],
            'HTTP 500' => ['', 500, 503, self::UNAVAILABLE_MESSAGE],
        ];
    }

    #[Test]
    #[DataProvider('googleErrors')]
    public function google_errors_keep_the_same_responses(array|string $body, int $httpStatus, int $expectedStatus, string $message): void
    {
        config(['logging.default' => 'null']);
        $this->actingAsAdmin();
        $this->useGoogle();
        Http::fake([self::GOOGLE_URL_PATTERN => Http::response($body, $httpStatus)]);

        $response = $this->geocode(['address' => 'Rua do Brasil 25', 'location_id' => $this->locationId('Coimbra')])
            ->assertStatus($expectedStatus)
            ->assertExactJson(['success' => false, 'message' => $message]);

        $this->assertStringNotContainsString(self::GOOGLE_KEY, $response->getContent());
    }

    #[Test]
    public function attribution_follows_the_provider_that_answered(): void
    {
        $this->actingAsAdmin();
        Http::fake([
            self::URL_PATTERN => Http::response([[
                'lat' => '38.70845912345',
                'lon' => '-9.13652841234',
                'display_name' => self::DISPLAY_NAME,
                'address' => [
                    'road' => 'Rua Augusta',
                    'city' => 'Lisboa',
                    'ISO3166-2-lvl6' => 'PT-11',
                    'country_code' => 'pt',
                ],
            ]], 200),
            self::GOOGLE_URL_PATTERN => Http::response(self::googleCoimbraRooftop(), 200),
        ]);

        $this->geocode(['address' => 'Rua Augusta 1'])
            ->assertOk()
            ->assertJsonPath('data.attribution', '© OpenStreetMap contributors')
            ->assertJsonPath('data.place_id', null);

        $this->useGoogle();

        $this->geocode(['address' => 'Rua do Brasil 25', 'location_id' => $this->locationId('Coimbra')])
            ->assertOk()
            ->assertJsonPath('data.attribution', 'Google Maps')
            ->assertJsonPath('data.place_id', self::COIMBRA_PLACE_ID);
    }
}
