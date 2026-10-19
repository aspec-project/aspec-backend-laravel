<?php

namespace Tests\Feature\Lists;

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReferenceListsTest extends TestCase
{
    use RefreshDatabase;

    private const LOCATIONS_IN_PORTUGUESE_ORDER = [
        'Açores',
        'Aveiro',
        'Beja',
        'Braga',
        'Bragança',
        'Castelo Branco',
        'Coimbra',
        'Évora',
        'Faro',
        'Guarda',
        'Leiria',
        'Lisboa',
        'Madeira',
        'Portalegre',
        'Porto',
        'Santarém',
        'Setúbal',
        'Viana do Castelo',
        'Vila Real',
        'Viseu',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_sectors_endpoint_returns_list(): void
    {
        $response = $this->getJson('/api/sectors');

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => [
                    'id',
                    'name',
                ],
            ],
        ]);

        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_locations_endpoint_returns_list(): void
    {
        $response = $this->getJson('/api/locations');

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => [
                    'id',
                    'name',
                ],
            ],
        ]);

        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_social_platforms_endpoint_returns_list(): void
    {
        $response = $this->getJson('/api/social-platforms');

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => [
                    'id',
                    'name',
                ],
            ],
        ]);

        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_week_days_endpoint_returns_list(): void
    {
        $response = $this->getJson('/api/week-days');

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => [
                    'id',
                    'name',
                ],
            ],
        ]);

        $response->assertJson([
            'success' => true,
        ]);
    }

    #[Test]
    public function locations_endpoint_returns_the_twenty_locations_in_portuguese_alphabetical_order(): void
    {
        $response = $this->getJson('/api/locations');

        $response->assertOk()->assertJsonCount(20, 'data');
        $this->assertSame(self::LOCATIONS_IN_PORTUGUESE_ORDER, array_column($response->json('data'), 'name'));
    }

    #[Test]
    public function locations_with_accents_are_sorted_as_their_base_letter(): void
    {
        $names = array_column($this->getJson('/api/locations')->assertOk()->json('data'), 'name');

        $this->assertSame('Açores', $names[0]);
        $this->assertSame('Aveiro', $names[1]);
        $this->assertSame(array_search('Coimbra', $names, true) + 1, array_search('Évora', $names, true));
        $this->assertSame(array_search('Faro', $names, true) - 1, array_search('Évora', $names, true));
    }

    #[Test]
    public function locations_created_later_are_also_sorted_in_portuguese(): void
    {
        Location::create(['name' => 'Óbidos']);

        $response = $this->getJson('/api/locations');

        $response->assertOk()->assertJsonCount(21, 'data');
        $names = array_column($response->json('data'), 'name');
        $obidos = array_search('Óbidos', $names, true);
        $this->assertSame(array_search('Madeira', $names, true) + 1, $obidos);
        $this->assertSame(array_search('Portalegre', $names, true) - 1, $obidos);
    }
}
