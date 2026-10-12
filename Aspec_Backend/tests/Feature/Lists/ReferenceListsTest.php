<?php

namespace Tests\Feature\Lists;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ReferenceListsTest extends TestCase
{
    use RefreshDatabase;

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
}