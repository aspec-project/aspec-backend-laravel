<?php

namespace Tests\Feature\Lists;

use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventCategoryListTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guest_can_list_event_categories_ordered_by_name(): void
    {
        $response = $this->getJson('/api/event-categories');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Categorias de eventos obtidas com sucesso.')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => ['id', 'name', 'color'],
                ],
            ]);

        $this->assertSame(
            ['Formações', 'Jantares de Networking', 'Pequenos-almoços de Negócios', 'Reuniões'],
            array_column($response->json('data'), 'name')
        );
    }

    #[Test]
    public function public_list_does_not_expose_timestamps(): void
    {
        $response = $this->getJson('/api/event-categories');

        $response->assertOk();

        $items = $response->json('data');
        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $this->assertArrayNotHasKey('created_at', $item);
            $this->assertArrayNotHasKey('updated_at', $item);
        }
    }

    #[Test]
    public function member_can_read_the_public_list(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/event-categories')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(4, 'data');
    }

    #[Test]
    public function public_list_does_not_include_the_number_of_events(): void
    {
        $formacoes = EventCategory::where('name', 'Formações')->firstOrFail();
        EventInstance::factory()->create(['category_id' => $formacoes->id]);

        $response = $this->getJson('/api/event-categories');

        $response->assertOk();

        $items = $response->json('data');
        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $this->assertArrayNotHasKey('events_count', $item);
        }
    }
}
