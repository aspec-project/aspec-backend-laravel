<?php

namespace Tests\Feature\Events;

use App\Models\EventInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventInstanceShowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guest_can_view_event_details(): void
    {
        $eventDate = today()->addMonth()->toDateString();
        $eventStartsAt = Carbon::parse($eventDate.' 10:00');

        $event = EventInstance::factory()->create([
            'name' => 'Workshop público',
            'event_date' => $eventDate,
            'event_start_time' => '10:00',
            'event_end_time' => '12:00',
            'address' => 'Rua de teste, 10',
            'venue_name' => 'Centro de Congressos de Lisboa',
            'latitude' => '38.7223000',
            'longitude' => '-9.1393000',
            'max_attendees' => 40,
            'registration_deadline' => $eventStartsAt->copy()->subDay(),
            'organizer_notes' => 'Entrada pela porta lateral.',
        ]);

        $this->getJson("/api/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $event->id)
            ->assertJsonPath('data.name', 'Workshop público')
            ->assertJsonPath('data.category.id', $event->category_id)
            ->assertJsonPath('data.location.id', $event->location_id)
            ->assertJsonPath('data.address', 'Rua de teste, 10')
            ->assertJsonPath('data.venue_name', 'Centro de Congressos de Lisboa')
            ->assertJsonPath('data.latitude', 38.7223)
            ->assertJsonPath('data.longitude', -9.1393)
            ->assertJsonPath('data.max_attendees', 40)
            ->assertJsonPath(
                'data.registration_deadline',
                $event->fresh()->registration_deadline->toIso8601String()
            )
            ->assertJsonPath('data.organizer_notes', 'Entrada pela porta lateral.');
    }

    #[Test]
    public function missing_or_invalid_event_id_returns_404(): void
    {
        $this->getJson('/api/events/00000000-0000-4000-8000-000000000000')
            ->assertNotFound()
            ->assertJsonPath('message', 'Recurso não encontrado.');

        $this->getJson('/api/events/not-a-uuid')
            ->assertNotFound()
            ->assertJsonPath('message', 'Recurso não encontrado.');
    }
}
