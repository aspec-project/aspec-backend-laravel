<?php

namespace Tests\Feature\Admin;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

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

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
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

        $this->assertSame(['latitude', 'longitude', 'display_name', 'attribution'], array_keys($response->json('data')));
        $this->assertStringNotContainsString('osm_id', $response->getContent());
        $this->assertStringNotContainsString('place_id', $response->getContent());
    }
}
