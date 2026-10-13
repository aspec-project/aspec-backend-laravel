<?php

namespace Tests\Feature\Lists;

use App\Models\EventCategory;
use App\Models\EventInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventInstanceAgendaTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/events';

    private function createEvent(
        EventCategory $category,
        string $date,
        string $startTime,
        string $name
    ): EventInstance {
        $eventStartsAt = Carbon::parse($date.' '.$startTime);

        return EventInstance::factory()->create([
            'category_id' => $category->id,
            'name' => $name,
            'event_date' => $date,
            'event_start_time' => $startTime,
            'event_end_time' => $eventStartsAt->copy()->addHour()->format('H:i:s'),
            'registration_deadline' => $eventStartsAt->copy()->subDay(),
        ]);
    }

    #[Test]
    public function public_agenda_excludes_started_events_and_orders_upcoming_events(): void
    {
        $now = now()->setTime(12, 0, 0);
        $this->travelTo($now);

        $category = EventCategory::factory()->create();
        $today = $now->toDateString();

        $this->createEvent($category, $now->copy()->subDay()->toDateString(), '10:00', 'Evento passado');
        $this->createEvent($category, $today, '11:00', 'Evento que já começou');

        $todayLater = $this->createEvent($category, $today, '18:00', 'Evento de hoje');
        $tomorrow = $this->createEvent(
            $category,
            $now->copy()->addDay()->toDateString(),
            '09:00',
            'Evento de amanhã'
        );

        $response = $this->getJson(self::URL);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $todayLater->id)
            ->assertJsonPath('data.items.1.id', $tomorrow->id)
            ->assertJsonPath('data.items.0.category.id', $category->id);
    }

    #[Test]
    public function public_agenda_can_be_filtered_by_category(): void
    {
        $category = EventCategory::factory()->create();
        $otherCategory = EventCategory::factory()->create();

        $matchingEvent = $this->createEvent(
            $category,
            today()->addDays(2)->toDateString(),
            '10:00',
            'Evento da categoria'
        );

        $this->createEvent(
            $otherCategory,
            today()->addDays(3)->toDateString(),
            '10:00',
            'Evento de outra categoria'
        );

        $this->getJson(self::URL.'?category_id='.$category->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $matchingEvent->id);
    }

    #[Test]
    public function category_filter_must_be_a_valid_existing_uuid(): void
    {
        $this->getJson(self::URL.'?category_id=invalid')
            ->assertUnprocessable()
            ->assertJsonStructure([
                'errors' => ['category_id'],
            ]);
    }

    #[Test]
    public function public_agenda_is_paginated_by_fifteen_events(): void
    {
        $category = EventCategory::factory()->create();

        EventInstance::factory()->count(16)->create([
            'category_id' => $category->id,
            'event_date' => today()->addDays(2)->toDateString(),
        ]);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(15, 'data.items')
            ->assertJsonPath('data.pagination.per_page', 15)
            ->assertJsonPath('data.pagination.total', 16)
            ->assertJsonPath('data.pagination.last_page', 2);

        $this->getJson(self::URL.'?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination.current_page', 2);
    }
}
