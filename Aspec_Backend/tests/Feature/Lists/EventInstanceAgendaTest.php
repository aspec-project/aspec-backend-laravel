<?php

namespace Tests\Feature\Lists;

use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\Location;
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
        string $name,
        ?Location $location = null
    ): EventInstance {
        $eventStartsAt = Carbon::parse($date.' '.$startTime);

        $attributes = [
            'category_id' => $category->id,
            'name' => $name,
            'event_date' => $date,
            'event_start_time' => $startTime,
            'event_end_time' => $eventStartsAt->copy()->addHour()->format('H:i:s'),
            'registration_deadline' => $eventStartsAt->copy()->subDay(),
        ];

        if ($location !== null) {
            $attributes['location_id'] = $location->id;
        }

        return EventInstance::factory()->create($attributes);
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
    public function public_agenda_can_be_filtered_by_location(): void
    {
        $locations = Location::query()->orderBy('name')->take(2)->get();
        $this->assertCount(2, $locations);

        $category = EventCategory::factory()->create();

        $matchingEvent = $this->createEvent(
            $category,
            today()->addDays(2)->toDateString(),
            '10:00',
            'Evento no local escolhido',
            $locations[0]
        );

        $this->createEvent(
            $category,
            today()->addDays(3)->toDateString(),
            '10:00',
            'Evento noutro local',
            $locations[1]
        );

        $this->getJson(self::URL.'?location_id='.$locations[0]->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $matchingEvent->id);
    }

    #[Test]
    public function public_agenda_can_combine_category_and_location_filters(): void
    {
        $locations = Location::query()->orderBy('name')->take(2)->get();
        $this->assertCount(2, $locations);

        $category = EventCategory::factory()->create();
        $otherCategory = EventCategory::factory()->create();
        $eventDate = today()->addDays(2)->toDateString();

        $matchingEvent = $this->createEvent(
            $category,
            $eventDate,
            '10:00',
            'Evento correspondente aos filtros',
            $locations[0]
        );

        $this->createEvent(
            $category,
            $eventDate,
            '12:00',
            'Mesma categoria, outro local',
            $locations[1]
        );

        $this->createEvent(
            $otherCategory,
            $eventDate,
            '14:00',
            'Outro categoria, mesmo local',
            $locations[0]
        );

        $this->getJson(
            self::URL.'?category_id='.$category->id.'&location_id='.$locations[0]->id
        )
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
    public function location_filter_must_be_a_valid_existing_uuid(): void
    {
        $this->getJson(self::URL.'?location_id=invalid')
            ->assertUnprocessable()
            ->assertJsonStructure([
                'errors' => ['location_id'],
            ]);
    }

    #[Test]
    public function public_agenda_is_paginated_by_nine_events(): void
    {
        $category = EventCategory::factory()->create();

        EventInstance::factory()->count(16)->create([
            'category_id' => $category->id,
            'event_date' => today()->addDays(2)->toDateString(),
        ]);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(9, 'data.items')
            ->assertJsonPath('data.pagination.per_page', 9)
            ->assertJsonPath('data.pagination.total', 16)
            ->assertJsonPath('data.pagination.last_page', 2);

        $this->getJson(self::URL.'?page=2')
            ->assertOk()
            ->assertJsonCount(7, 'data.items')
            ->assertJsonPath('data.pagination.current_page', 2);
    }

    #[Test]
    public function public_agenda_rejects_an_excessively_large_page_number(): void
    {
        $this->getJson(self::URL.'?page=9223372036854775807')
            ->assertUnprocessable()
            ->assertJsonStructure([
                'errors' => ['page'],
            ]);
    }

    #[Test]
    public function public_agenda_uses_lisbon_time_during_summer(): void
    {
        config(['app.event_timezone' => 'Europe/Lisbon']);

        $this->travelTo(Carbon::parse('2026-07-01 10:30:00', 'UTC'));

        $category = EventCategory::factory()->create();

        $this->createEvent(
            $category,
            '2026-07-01',
            '11:00',
            'Evento que já começou em Lisboa'
        );

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    #[Test]
    public function public_event_routes_share_a_sixty_requests_per_minute_ip_limit(): void
    {
        $event = EventInstance::factory()->create();

        for ($i = 1; $i <= 60; $i++) {
            $this->getJson(self::URL)->assertOk();
        }

        $this->getJson('/api/events/'.$event->id)
            ->assertTooManyRequests();
    }

    #[Test]
    public function public_agenda_uses_nine_events_by_default_and_accepts_per_page(): void
    {
        $category = EventCategory::factory()->create();
        $eventDate = today()->addDays(2)->toDateString();

        for ($i = 0; $i < 12; $i++) {
            $this->createEvent(
                $category,
                $eventDate,
                sprintf('%02d:00', $i + 8),
                "Evento {$i}"
            );
        }

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(9, 'data.items')
            ->assertJsonPath('data.pagination.per_page', 9)
            ->assertJsonPath('data.pagination.total', 12)
            ->assertJsonPath('data.pagination.last_page', 2);

        $this->getJson(self::URL.'?per_page=9')
            ->assertOk()
            ->assertJsonCount(9, 'data.items')
            ->assertJsonPath('data.pagination.per_page', 9);

        $this->getJson(self::URL.'?per_page=4')
            ->assertOk()
            ->assertJsonCount(4, 'data.items')
            ->assertJsonPath('data.pagination.per_page', 4)
            ->assertJsonPath('data.pagination.last_page', 3);
    }

    #[Test]
    public function public_agenda_rejects_per_page_above_one_hundred(): void
    {
        $this->getJson(self::URL.'?per_page=101')
            ->assertUnprocessable()
            ->assertJsonStructure([
                'errors' => ['per_page'],
            ]);
    }

    #[Test]
    public function public_agenda_searches_event_name_description_category_and_location(): void
    {
        $category = EventCategory::factory()->create([
            'name' => 'CategorySearchMarker',
        ]);
        $location = Location::create([
            'name' => 'LocationSearchMarker',
        ]);

        $event = EventInstance::factory()->create([
            'category_id' => $category->id,
            'location_id' => $location->id,
            'name' => 'EventNameSearchMarker',
            'description' => 'EventDescriptionSearchMarker',
            'event_date' => today()->addDays(2)->toDateString(),
        ]);

        foreach ([
            'EventNameSearchMarker',
            'EventDescriptionSearchMarker',
            'CategorySearchMarker',
            'LocationSearchMarker',
        ] as $term) {
            $this->getJson(self::URL.'?search='.$term)
                ->assertOk()
                ->assertJsonCount(1, 'data.items')
                ->assertJsonPath('data.items.0.id', $event->id);
        }
    }

    #[Test]
    public function public_agenda_combines_text_search_with_category_and_location_filters(): void
    {
        $category = EventCategory::factory()->create();
        $otherCategory = EventCategory::factory()->create();
        $location = Location::create(['name' => 'SearchFilterLocation']);
        $otherLocation = Location::create(['name' => 'OtherSearchFilterLocation']);
        $eventDate = today()->addDays(2)->toDateString();

        $matchingEvent = EventInstance::factory()->create([
            'category_id' => $category->id,
            'location_id' => $location->id,
            'name' => 'Business Search Match',
            'event_date' => $eventDate,
        ]);

        EventInstance::factory()->create([
            'category_id' => $otherCategory->id,
            'location_id' => $location->id,
            'name' => 'Business Search Wrong Category',
            'event_date' => $eventDate,
        ]);

        EventInstance::factory()->create([
            'category_id' => $category->id,
            'location_id' => $otherLocation->id,
            'name' => 'Business Search Wrong Location',
            'event_date' => $eventDate,
        ]);

        $this->getJson(
            self::URL.'?search=Business&category_id='.$category->id.'&location_id='.$location->id
        )
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $matchingEvent->id);
    }

    #[Test]
    public function public_agenda_rejects_search_terms_longer_than_one_hundred_characters(): void
    {
        $search = str_repeat('a', 101);

        $this->getJson(self::URL.'?search='.$search)
            ->assertUnprocessable()
            ->assertJsonStructure([
                'errors' => ['search'],
            ]);
    }
}
