<?php

namespace Tests\Feature\Admin;

use App\Contracts\Geocoder;
use App\Exceptions\GeocodingUnavailable;
use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\Location;
use App\Models\User;
use App\Notifications\EventCancelledNotification;
use App\Notifications\EventUpdatedNotification;
use App\Services\Geocoding\Data\GeocodingResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminEventInstanceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/events';

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        $eventStartsAt = now()->addDays(30)->setTime(10, 0);

        return [
            'category_id' => EventCategory::query()->firstOrFail()->id,
            'location_id' => Location::query()->firstOrFail()->id,
            'name' => 'Workshop de teste',
            'event_date' => $eventStartsAt->format('Y-m-d'),
            'event_start_time' => '10:00',
            'event_end_time' => '12:00',
            'description' => 'Descrição do evento de teste.',
            'address' => 'Rua de teste, 10',
            'latitude' => 38.7223,
            'longitude' => -9.1393,
            'max_attendees' => 30,
            'registration_deadline' => $eventStartsAt
                ->copy()
                ->subDay()
                ->toDateString(),
            'organizer_notes' => 'Notas do organizador.',
            'is_online' => false,
        ];
    }

    #[Test]
    public function admin_can_list_events_with_pagination(): void
    {
        $this->actingAsAdmin();

        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $today = now($timezone)->startOfDay();

        EventInstance::factory()->create([
            'event_date' => $today->copy()->subDay()->toDateString(),
        ]);

        $todayEvent = EventInstance::factory()->create([
            'event_date' => $today->toDateString(),
            'event_start_time' => '08:00:00',
            'event_end_time' => '09:00:00',
        ]);

        $futureEvent = EventInstance::factory()->create([
            'event_date' => $today->copy()->addDay()->toDateString(),
        ]);

        EventRegistration::query()->create([
            'event_instance_id' => $futureEvent->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $todayEvent->id)
            ->assertJsonPath('data.items.1.id', $futureEvent->id)
            ->assertJsonPath('data.items.1.registrations_count', 1)
            ->assertJsonPath('data.pagination.per_page', 6)
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.counts.upcoming', 2)
            ->assertJsonPath('data.counts.past', 1)
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        '*' => ['id', 'name', 'category', 'location', 'event_date'],
                    ],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                    'counts' => ['upcoming', 'past'],
                ],
            ]);
    }

    #[Test]
    public function admin_can_filter_past_events_and_count_periods_using_combined_filters(): void
    {
        $this->actingAsAdmin();

        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $today = now($timezone)->startOfDay();

        $category = EventCategory::factory()->create();
        $otherCategory = EventCategory::factory()->create();
        $location = Location::create(['name' => 'ASPEC-375 Lisboa']);
        $otherLocation = Location::create(['name' => 'ASPEC-375 Porto']);

        $createEvent = function (
            string $date,
            string $startTime,
            string $endTime,
            EventCategory $eventCategory,
            Location $eventLocation
        ): EventInstance {
            return EventInstance::factory()->create([
                'category_id' => $eventCategory->id,
                'location_id' => $eventLocation->id,
                'event_date' => $date,
                'event_start_time' => $startTime,
                'event_end_time' => $endTime,
            ]);
        };

        $olderPast = $createEvent(
            $today->copy()->subDays(2)->toDateString(),
            '17:00:00',
            '18:00:00',
            $category,
            $location
        );

        $recentPastMorning = $createEvent(
            $today->copy()->subDay()->toDateString(),
            '17:00:00',
            '18:00:00',
            $category,
            $location
        );

        $recentPastEvening = $createEvent(
            $today->copy()->subDay()->toDateString(),
            '19:00:00',
            '20:00:00',
            $category,
            $location
        );

        $createEvent(
            $today->toDateString(),
            '08:00:00',
            '09:00:00',
            $category,
            $location
        );

        $createEvent(
            $today->copy()->addDay()->toDateString(),
            '09:00:00',
            '11:00:00',
            $category,
            $location
        );

        $createEvent(
            $today->copy()->subDay()->toDateString(),
            '10:00:00',
            '11:00:00',
            $otherCategory,
            $location
        );

        $createEvent(
            $today->copy()->subDay()->toDateString(),
            '12:00:00',
            '13:00:00',
            $category,
            $otherLocation
        );

        $filters = [
            'period' => 'past',
            'date_from' => $today->copy()->subDays(3)->toDateString(),
            'date_to' => $today->copy()->addDays(3)->toDateString(),
            'category_id' => $category->id,
            'location_id' => $location->id,
            'per_page' => 6,
        ];

        $this->getJson(self::URL.'?'.http_build_query($filters))
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.id', $recentPastEvening->id)
            ->assertJsonPath('data.items.1.id', $recentPastMorning->id)
            ->assertJsonPath('data.items.2.id', $olderPast->id)
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.counts.upcoming', 2)
            ->assertJsonPath('data.counts.past', 3);

        $filters['per_page'] = 1;

        $this->getJson(self::URL.'?'.http_build_query($filters))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $recentPastEvening->id)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.pagination.total', 3);
    }

    #[Test]
    public function admin_event_index_rejects_invalid_query_parameters(): void
    {
        $this->actingAsAdmin();

        $invalidRequests = [
            ['period=invalid', 'period'],
            ['date_from=2026-13-01', 'date_from'],
            ['date_to=not-a-date', 'date_to'],
            ['date_from=2030-11-20&date_to=2030-11-19', 'date_to'],
            ['category_id=not-a-uuid', 'category_id'],
            ['category_id=00000000-0000-4000-8000-000000000000', 'category_id'],
            ['location_id=not-a-uuid', 'location_id'],
            ['location_id=00000000-0000-4000-8000-000000000000', 'location_id'],
            ['per_page=0', 'per_page'],
            ['per_page=51', 'per_page'],
        ];

        foreach ($invalidRequests as [$query, $errorField]) {
            $this->getJson(self::URL.'?'.$query)
                ->assertUnprocessable()
                ->assertJsonStructure([
                    'errors' => [$errorField],
                ]);
        }
    }

    #[Test]
    public function admin_can_create_an_event(): void
    {
        $this->actingAsAdmin();
        $payload = $this->validPayload();

        $response = $this->postJson(self::URL, $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Evento criado com sucesso.')
            ->assertJsonPath('data.name', $payload['name'])
            ->assertJsonPath('data.max_attendees', $payload['max_attendees']);

        $this->assertDatabaseHas('event_instances', [
            'id' => $response->json('data.id'),
            'name' => $payload['name'],
            'category_id' => $payload['category_id'],
            'location_id' => $payload['location_id'],
        ]);

        $deadlineAt = Carbon::createFromFormat(
            '!Y-m-d',
            $payload['registration_deadline'],
            config('app.event_timezone', 'Europe/Lisbon')
        )
            ->setTime(23, 59)
            ->format('Y-m-d H:i:s');

        $this->assertDatabaseHas('event_instances', [
            'id' => $response->json('data.id'),
            'registration_deadline' => $deadlineAt,
        ]);
    }

    #[Test]
    public function event_creation_is_limited_to_20_per_hour_per_admin(): void
    {
        Notification::fake();

        $admin = $this->actingAsAdmin();
        $payload = $this->validPayload();

        for ($i = 1; $i <= 20; $i++) {
            $this->postJson(self::URL, $payload)->assertCreated();
        }

        $this->postJson(self::URL, $payload)
            ->assertTooManyRequests()
            ->assertJsonPath(
                'message',
                'Demasiados pedidos. Tente novamente mais tarde.'
            );

        $this->actingAsAdmin();
        $this->postJson(self::URL, $payload)->assertCreated();

        $this->travel(61)->minutes();
        Sanctum::actingAs($admin);

        $this->postJson(self::URL, $payload)->assertCreated();
    }

    #[Test]
    public function admin_can_create_a_recurring_event_series_with_deadlines_for_each_session(): void
    {
        $this->actingAsAdmin();

        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $payload = $this->validPayload();
        unset($payload['registration_deadline']);

        $firstDate = Carbon::createFromFormat(
            '!Y-m-d',
            $payload['event_date'],
            $timezone
        );

        $sessionDates = [
            $firstDate->toDateString(),
            $firstDate->copy()->addWeek()->toDateString(),
            $firstDate->copy()->addWeeks(2)->toDateString(),
        ];

        $payload['recurrence'] = [
            'frequency' => 'weekly',
            'ends_on' => $sessionDates[2],
            'registration_deadline_days_before' => 3,
        ];

        $response = $this->postJson(self::URL, $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('data.series.frequency', 'weekly')
            ->assertJsonPath('data.series.ends_on', $sessionDates[2])
            ->assertJsonPath('data.series.registration_deadline_days_before', 3)
            ->assertJsonCount(3, 'data.sessions');

        $seriesId = $response->json('data.series.id');

        $this->assertDatabaseHas('event_series', [
            'id' => $seriesId,
            'frequency' => 'weekly',
            'ends_on' => $sessionDates[2],
            'registration_deadline_days_before' => 3,
        ]);

        foreach ($sessionDates as $index => $sessionDate) {
            $deadline = Carbon::createFromFormat('!Y-m-d', $sessionDate, $timezone)
                ->subDays(3)
                ->setTime(23, 59);

            $this->assertDatabaseHas('event_instances', [
                'series_id' => $seriesId,
                'event_date' => $sessionDate,
                'registration_deadline' => $deadline->format('Y-m-d H:i:s'),
            ]);

            $response->assertJsonPath(
                "data.sessions.{$index}.event_date",
                $sessionDate
            );
            $response->assertJsonPath(
                "data.sessions.{$index}.registration_deadline",
                $deadline->toIso8601String()
            );

            $response->assertJsonPath(
                "data.sessions.{$index}.series_id",
                $seriesId
            );
        }
    }

    #[Test]
    public function event_creation_rejects_invalid_recurring_event_data(): void
    {
        $this->actingAsAdmin();

        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $payload = $this->validPayload();
        unset($payload['registration_deadline']);

        $endsOn = Carbon::createFromFormat(
            '!Y-m-d',
            $payload['event_date'],
            $timezone
        )->addWeeks(2)->toDateString();

        $validRecurrence = [
            'frequency' => 'weekly',
            'ends_on' => $endsOn,
            'registration_deadline_days_before' => 2,
        ];

        $invalidCases = [
            [
                'recurrence' => array_merge($validRecurrence, ['frequency' => 'daily']),
                'error' => 'recurrence.frequency',
            ],
            [
                'recurrence' => [
                    'frequency' => 'weekly',
                    'registration_deadline_days_before' => 2,
                ],
                'error' => 'recurrence.ends_on',
            ],
            [
                'recurrence' => array_merge(
                    $validRecurrence,
                    ['registration_deadline_days_before' => 0]
                ),
                'error' => 'recurrence.registration_deadline_days_before',
            ],
        ];

        foreach ($invalidCases as $invalidCase) {
            $response = $this->postJson(self::URL, array_merge(
                $payload,
                ['recurrence' => $invalidCase['recurrence']]
            ));

            $response
                ->assertUnprocessable()
                ->assertJsonStructure([
                    'errors' => [$invalidCase['error']],
                ]);
        }

        $this->assertDatabaseCount('event_series', 0);
        $this->assertDatabaseCount('event_instances', 0);
    }

    #[Test]
    public function event_creation_rejects_a_recurring_series_with_more_than_52_sessions(): void
    {
        $this->actingAsAdmin();

        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $payload = $this->validPayload();
        unset($payload['registration_deadline']);

        $payload['recurrence'] = [
            'frequency' => 'weekly',
            'ends_on' => Carbon::createFromFormat(
                '!Y-m-d',
                $payload['event_date'],
                $timezone
            )->addWeeks(52)->toDateString(),
            'registration_deadline_days_before' => 2,
        ];

        $this->postJson(self::URL, $payload)
            ->assertUnprocessable()
            ->assertJsonStructure([
                'errors' => ['recurrence.ends_on'],
            ]);

        $this->assertDatabaseCount('event_series', 0);
        $this->assertDatabaseCount('event_instances', 0);
    }

    #[Test]
    public function admin_can_show_an_event(): void
    {
        $this->actingAsAdmin();
        $event = EventInstance::factory()->create();

        $this->getJson(self::URL."/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $event->id)
            ->assertJsonPath('data.category.id', $event->category_id)
            ->assertJsonPath('data.location.id', $event->location_id);
    }

    #[Test]
    public function admin_can_update_an_event(): void
    {
        $this->actingAsAdmin();
        $event = EventInstance::factory()->create();
        $payload = $this->validPayload();
        $payload['name'] = 'Workshop atualizado';

        $this->putJson(self::URL."/{$event->id}", $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Evento atualizado com sucesso.')
            ->assertJsonPath('data.name', 'Workshop atualizado');

        $this->assertDatabaseHas('event_instances', [
            'id' => $event->id,
            'name' => 'Workshop atualizado',
        ]);
    }

    #[Test]
    public function admin_can_update_one_session_in_a_recurring_series_without_changing_the_others(): void
    {
        $this->actingAsAdmin();

        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $payload = $this->validPayload();
        unset($payload['registration_deadline']);

        $firstDate = Carbon::createFromFormat(
            '!Y-m-d',
            $payload['event_date'],
            $timezone
        );

        $sessionDates = [
            $firstDate->toDateString(),
            $firstDate->copy()->addWeek()->toDateString(),
            $firstDate->copy()->addWeeks(2)->toDateString(),
        ];

        $payload['recurrence'] = [
            'frequency' => 'weekly',
            'ends_on' => $sessionDates[2],
            'registration_deadline_days_before' => 3,
        ];

        $createResponse = $this->postJson(self::URL, $payload)
            ->assertCreated();

        $seriesId = $createResponse->json('data.series.id');

        $sessions = EventInstance::query()
            ->where('series_id', $seriesId)
            ->orderBy('event_date')
            ->get();

        $this->assertCount(3, $sessions);

        $firstSession = $sessions[0];
        $sessionToUpdate = $sessions[1];
        $thirdSession = $sessions[2];

        $firstSessionBefore = $firstSession->getAttributes();
        $thirdSessionBefore = $thirdSession->getAttributes();

        $updatePayload = $this->validPayload();
        $updatePayload['name'] = 'Sessão individual atualizada';
        $updatePayload['event_date'] = $sessionToUpdate->event_date->toDateString();
        $updatePayload['registration_deadline'] = substr(
            (string) $sessionToUpdate->getRawOriginal('registration_deadline'),
            0,
            10
        );

        $this->putJson(self::URL."/{$sessionToUpdate->id}", $updatePayload)
            ->assertOk()
            ->assertJsonPath('data.id', $sessionToUpdate->id)
            ->assertJsonPath('data.name', 'Sessão individual atualizada');

        $this->assertSame(
            $firstSessionBefore,
            $firstSession->fresh()->getAttributes()
        );
        $this->assertSame(
            $thirdSessionBefore,
            $thirdSession->fresh()->getAttributes()
        );

        $this->assertDatabaseHas('event_instances', [
            'id' => $sessionToUpdate->id,
            'series_id' => $seriesId,
            'name' => 'Sessão individual atualizada',
            'event_date' => $sessionToUpdate->event_date->toDateString(),
        ]);

        $this->assertDatabaseHas('event_series', [
            'id' => $seriesId,
            'frequency' => 'weekly',
            'ends_on' => $sessionDates[2],
            'registration_deadline_days_before' => 3,
        ]);
    }

    #[Test]
    public function admin_cannot_update_an_event_that_has_already_passed(): void
    {
        $this->actingAsAdmin();

        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $originalName = 'Evento passado';
        $event = EventInstance::factory()->create([
            'name' => $originalName,
            'event_date' => now($timezone)->subDay()->toDateString(),
        ]);

        $payload = $this->validPayload();
        $payload['name'] = 'Tentativa de alteração';

        $this->putJson(self::URL."/{$event->id}", $payload)
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Não é possível alterar um evento que já decorreu.'
            );

        $this->assertDatabaseHas('event_instances', [
            'id' => $event->id,
            'name' => $originalName,
        ]);
    }

    #[Test]
    public function admin_can_create_an_online_event_without_physical_address(): void
    {
        $this->actingAsAdmin();

        $payload = $this->validPayload();
        $payload['is_online'] = true;
        $payload['online_url'] = 'https://meet.example.com/event';

        unset($payload['address'], $payload['latitude'], $payload['longitude']);

        $response = $this->postJson(self::URL, $payload);

        $response->assertCreated();

        $this->assertDatabaseHas('event_instances', [
            'id' => $response->json('data.id'),
            'is_online' => true,
            'online_url' => 'https://meet.example.com/event',
            'address' => null,
            'latitude' => null,
            'longitude' => null,
        ]);
    }

    #[Test]
    public function admin_can_update_an_event_to_online_without_physical_address(): void
    {
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create([
            'address' => 'Morada anterior',
            'latitude' => 38.7223,
            'longitude' => -9.1393,
        ]);

        $payload = $this->validPayload();
        $payload['is_online'] = true;
        $payload['online_url'] = 'https://meet.example.com/event';

        unset($payload['address'], $payload['latitude'], $payload['longitude']);

        $this->putJson(self::URL."/{$event->id}", $payload)
            ->assertOk();

        $this->assertDatabaseHas('event_instances', [
            'id' => $event->id,
            'is_online' => true,
            'online_url' => 'https://meet.example.com/event',
            'address' => null,
            'latitude' => null,
            'longitude' => null,
        ]);
    }

    #[Test]
    public function online_event_rejects_physical_address_and_coordinates(): void
    {
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create();

        $payload = $this->validPayload();
        $payload['is_online'] = true;
        $payload['online_url'] = 'https://meet.example.com/event';

        foreach ([
            ['method' => 'post', 'url' => self::URL],
            ['method' => 'put', 'url' => self::URL."/{$event->id}"],
        ] as $request) {
            $this->{$request['method'].'Json'}($request['url'], $payload)
                ->assertUnprocessable()
                ->assertJsonStructure([
                    'errors' => ['address', 'latitude', 'longitude'],
                ]);
        }
    }

    #[Test]
    public function in_person_event_rejects_online_url(): void
    {
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create();

        $payload = $this->validPayload();
        $payload['is_online'] = false;
        $payload['online_url'] = 'https://meet.example.com/event';

        foreach ([
            ['method' => 'post', 'url' => self::URL],
            ['method' => 'put', 'url' => self::URL."/{$event->id}"],
        ] as $request) {
            $this->{$request['method'].'Json'}($request['url'], $payload)
                ->assertUnprocessable()
                ->assertJsonStructure([
                    'errors' => ['online_url'],
                ]);
        }
    }

    #[Test]
    public function event_creation_and_update_require_is_online(): void
    {
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create();
        $payload = $this->validPayload();

        unset($payload['is_online']);

        $this->postJson(self::URL, $payload)
            ->assertUnprocessable()
            ->assertJsonStructure([
                'errors' => ['is_online'],
            ]);

        $this->putJson(self::URL."/{$event->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonStructure([
                'errors' => ['is_online'],
            ]);
    }

    #[Test]
    public function online_event_rejects_an_invalid_online_url(): void
    {
        $this->actingAsAdmin();

        $payload = $this->validPayload();
        $payload['is_online'] = true;
        $payload['online_url'] = 'not-a-valid-url';

        unset($payload['address'], $payload['latitude'], $payload['longitude']);

        $this->postJson(self::URL, $payload)
            ->assertUnprocessable()
            ->assertJsonStructure([
                'errors' => ['online_url'],
            ]);
    }

    #[Test]
    public function admin_can_delete_an_event(): void
    {
        $this->actingAsAdmin();
        $event = EventInstance::factory()->create();

        $this->deleteJson(self::URL."/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('event_instances', ['id' => $event->id]);
    }

    #[Test]
    public function admin_cannot_delete_an_event_that_has_already_passed(): void
    {
        $this->actingAsAdmin();

        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $event = EventInstance::factory()->create([
            'event_date' => now($timezone)->subDay()->toDateString(),
        ]);

        $this->deleteJson(self::URL."/{$event->id}")
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Não é possível alterar um evento que já decorreu.'
            );

        $this->assertDatabaseHas('event_instances', [
            'id' => $event->id,
        ]);
    }

    #[Test]
    public function event_creation_rejects_invalid_times_deadline_capacity_and_coordinates(): void
    {
        $this->actingAsAdmin();

        $valid = $this->validPayload();
        $invalidCases = [
            'event_end_time' => ['event_end_time' => '09:00'],
            'registration_deadline' => [
                'registration_deadline' => $valid['event_date'],
            ],
            'max_attendees' => ['max_attendees' => 0],
            'latitude' => ['latitude' => -91],
            'longitude' => ['longitude' => 181],
        ];

        foreach ($invalidCases as $field => $override) {
            $response = $this->postJson(self::URL, array_merge($valid, $override));

            $response->assertUnprocessable();
            $this->assertArrayHasKey($field, $response->json('errors'));
        }
    }

    #[Test]
    public function guest_cannot_access_admin_events(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->postJson(self::URL, $this->validPayload())->assertUnauthorized();
    }

    #[Test]
    public function regular_member_cannot_access_admin_events(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL, $this->validPayload())->assertForbidden();
    }

    #[Test]
    public function admin_events_index_rejects_an_excessively_large_page_number(): void
    {
        $this->actingAsAdmin();

        $this->getJson(self::URL.'?page=9223372036854775807')
            ->assertUnprocessable()
            ->assertJsonStructure([
                'errors' => ['page'],
            ]);
    }

    #[Test]
    public function event_creation_rejects_registration_deadline_with_timezone_offset(): void
    {
        $this->actingAsAdmin();

        $payload = $this->validPayload();
        $payload['registration_deadline'] = '2026-11-13T23:00:00+14:00';

        $this->postJson(self::URL, $payload)
            ->assertUnprocessable()
            ->assertJsonStructure([
                'errors' => ['registration_deadline'],
            ]);
    }

    #[Test]
    public function event_creation_rejects_a_past_event_date_and_registration_deadline(): void
    {
        $this->actingAsAdmin();

        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $past = now($timezone)->subDays(2);
        $payload = $this->validPayload();

        $payload['event_date'] = $past->copy()->addDay()->format('Y-m-d');
        $payload['registration_deadline'] = $past->format('Y-m-d');

        $this->postJson(self::URL, $payload)
            ->assertUnprocessable()
            ->assertJsonStructure([
                'errors' => ['event_date', 'registration_deadline'],
            ]);
    }

    #[Test]
    public function admin_can_update_an_event_with_a_past_date_and_deadline(): void
    {
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create();
        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $past = now($timezone)->subDays(2);
        $payload = $this->validPayload();

        $payload['event_date'] = $past->copy()->addDay()->format('Y-m-d');
        $payload['registration_deadline'] = $past->format('Y-m-d');

        $this->putJson(self::URL."/{$event->id}", $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Evento atualizado com sucesso.');
    }

    #[Test]
    public function event_creation_and_update_reject_values_above_storage_limits(): void
    {
        $this->actingAsAdmin();

        $payload = $this->validPayload();
        $event = EventInstance::factory()->create();

        $invalidCases = [
            'max_attendees' => ['max_attendees' => 100001],
            'description' => ['description' => str_repeat('a', 5001)],
            'organizer_notes' => ['organizer_notes' => str_repeat('a', 2001)],
            'venue_name' => ['venue_name' => str_repeat('a', 256)],
        ];

        foreach ($invalidCases as $field => $override) {
            $invalidPayload = array_merge($payload, $override);

            $this->postJson(self::URL, $invalidPayload)
                ->assertUnprocessable()
                ->assertJsonStructure([
                    'errors' => [$field],
                ]);

            $this->putJson(self::URL."/{$event->id}", $invalidPayload)
                ->assertUnprocessable()
                ->assertJsonStructure([
                    'errors' => [$field],
                ]);
        }
    }

    #[Test]
    public function admin_event_update_rejects_invalid_data(): void
    {
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create();
        $payload = $this->validPayload();
        $payload['event_end_time'] = '09:00';

        $this->putJson(self::URL."/{$event->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonStructure([
                'errors' => ['event_end_time'],
            ]);
    }

    #[Test]
    public function missing_event_returns_404_for_show_update_and_delete(): void
    {
        $this->actingAsAdmin();

        $missingId = '00000000-0000-4000-8000-000000000000';

        $this->getJson(self::URL."/{$missingId}")
            ->assertNotFound();

        $this->putJson(self::URL."/{$missingId}", $this->validPayload())
            ->assertNotFound();

        $this->deleteJson(self::URL."/{$missingId}")
            ->assertNotFound();
    }

    #[Test]
    public function inactive_or_pending_admin_cannot_access_admin_events(): void
    {
        $admins = [
            User::factory()->admin()->pending()->create(),
            User::factory()->admin()->inactive()->create(),
        ];

        foreach ($admins as $admin) {
            Sanctum::actingAs($admin);

            $this->getJson(self::URL)->assertForbidden();
        }
    }

    #[Test]
    public function updating_event_without_organizer_notes_keeps_existing_notes(): void
    {
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create([
            'organizer_notes' => 'Notas já existentes.',
        ]);

        $payload = $this->validPayload();
        unset($payload['organizer_notes']);

        $this->putJson(self::URL."/{$event->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.organizer_notes', 'Notas já existentes.');

        $this->assertDatabaseHas('event_instances', [
            'id' => $event->id,
            'organizer_notes' => 'Notas já existentes.',
        ]);
    }

    #[Test]
    public function admin_can_create_an_event_with_a_venue_name(): void
    {
        $this->actingAsAdmin();

        $payload = $this->validPayload();
        $payload['venue_name'] = 'Centro de Congressos de Lisboa';

        $response = $this->postJson(self::URL, $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('data.venue_name', 'Centro de Congressos de Lisboa');

        $this->assertDatabaseHas('event_instances', [
            'id' => $response->json('data.id'),
            'venue_name' => 'Centro de Congressos de Lisboa',
        ]);
    }

    #[Test]
    public function deleting_an_event_with_registrations_removes_them_and_notifies_each_active_registrant(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create();
        $registrants = User::factory()->count(2)->create();
        $inactiveRegistrant = User::factory()->inactive()->create();
        $pendingRegistrant = User::factory()->pending()->create();

        foreach ([$registrants, collect([$inactiveRegistrant, $pendingRegistrant])] as $users) {
            foreach ($users as $user) {
                EventRegistration::query()->create([
                    'event_instance_id' => $event->id,
                    'user_id' => $user->id,
                ]);
            }
        }

        $justification = 'O evento foi cancelado pela organização.';

        $this->deleteJson(self::URL."/{$event->id}", [
            'justification' => $justification,
        ])
            ->assertOk()
            ->assertJsonPath('data', null);

        foreach ($registrants as $user) {
            Notification::assertSentTo(
                $user,
                EventCancelledNotification::class,
                function (EventCancelledNotification $notification) use ($user, $justification): bool {
                    $this->assertContains(
                        "Justificação: {$justification}",
                        $notification->toMail($user)->introLines
                    );

                    return $notification->justification === $justification;
                }
            );
        }

        Notification::assertNotSentTo($inactiveRegistrant, EventCancelledNotification::class);
        Notification::assertNotSentTo($pendingRegistrant, EventCancelledNotification::class);

        $this->assertDatabaseMissing('event_instances', ['id' => $event->id]);
        $this->assertDatabaseMissing('event_registrations', [
            'event_instance_id' => $event->id,
        ]);
    }

    #[Test]
    public function admin_cannot_reduce_capacity_below_existing_registrations(): void
    {
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create(['max_attendees' => 3]);

        foreach (User::factory()->count(2)->create() as $user) {
            EventRegistration::query()->create([
                'event_instance_id' => $event->id,
                'user_id' => $user->id,
            ]);
        }

        $payload = $this->validPayload();
        $payload['max_attendees'] = 1;

        $this->putJson(self::URL."/{$event->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['max_attendees']);

        $this->assertDatabaseHas('event_instances', [
            'id' => $event->id,
            'max_attendees' => 3,
        ]);
    }

    #[Test]
    public function admin_can_set_capacity_equal_to_or_above_existing_registrations(): void
    {
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create(['max_attendees' => 3]);

        foreach (User::factory()->count(2)->create() as $user) {
            EventRegistration::query()->create([
                'event_instance_id' => $event->id,
                'user_id' => $user->id,
            ]);
        }

        foreach ([2, 4] as $capacity) {
            $payload = $this->validPayload();
            $payload['max_attendees'] = $capacity;

            $this->putJson(self::URL."/{$event->id}", $payload)
                ->assertOk()
                ->assertJsonPath('data.max_attendees', $capacity);
        }
    }

    #[Test]
    public function event_creation_and_update_require_event_end_time(): void
    {
        $this->actingAsAdmin();

        $payload = $this->validPayload();
        unset($payload['event_end_time']);

        $this->postJson(self::URL, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event_end_time']);

        $event = EventInstance::factory()->create();

        $this->putJson(self::URL."/{$event->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event_end_time']);
    }

    #[Test]
    public function admin_can_create_an_in_person_event_without_coordinates_by_geocoding_its_address(): void
    {
        $this->actingAsAdmin();

        $payload = $this->validPayload();
        unset($payload['latitude'], $payload['longitude']);

        $locationName = Location::query()
            ->findOrFail($payload['location_id'])
            ->name;
        $query = $payload['address'].', '.$locationName;
        $result = new GeocodingResult(38.7084591, -9.1365284, 'Rua Augusta, Lisboa');

        $this->mock(Geocoder::class, function (MockInterface $mock) use ($query, $result): void {
            $mock->shouldReceive('geocode')
                ->once()
                ->with($query)
                ->andReturn($result);
        });

        $response = $this->postJson(self::URL, $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('data.latitude', 38.7084591)
            ->assertJsonPath('data.longitude', -9.1365284);

        $this->assertDatabaseHas('event_instances', [
            'id' => $response->json('data.id'),
            'latitude' => 38.7084591,
            'longitude' => -9.1365284,
        ]);
    }

    #[Test]
    public function admin_can_update_an_in_person_event_without_coordinates_by_geocoding_its_address(): void
    {
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create();
        $payload = $this->validPayload();
        unset($payload['latitude'], $payload['longitude']);

        $locationName = Location::query()
            ->findOrFail($payload['location_id'])
            ->name;
        $query = $payload['address'].', '.$locationName;
        $result = new GeocodingResult(38.7084591, -9.1365284, 'Rua Augusta, Lisboa');

        $this->mock(Geocoder::class, function (MockInterface $mock) use ($query, $result): void {
            $mock->shouldReceive('geocode')
                ->once()
                ->with($query)
                ->andReturn($result);
        });

        $response = $this->putJson(self::URL."/{$event->id}", $payload);

        $response
            ->assertOk()
            ->assertJsonPath('data.latitude', 38.7084591)
            ->assertJsonPath('data.longitude', -9.1365284);

        $this->assertDatabaseHas('event_instances', [
            'id' => $event->id,
            'latitude' => 38.7084591,
            'longitude' => -9.1365284,
        ]);
    }

    #[Test]
    public function event_creation_returns_not_found_when_address_cannot_be_geocoded(): void
    {
        $this->actingAsAdmin();

        $payload = $this->validPayload();
        unset($payload['latitude'], $payload['longitude']);

        $eventsBefore = EventInstance::query()->count();

        $locationName = Location::query()
            ->findOrFail($payload['location_id'])
            ->name;
        $query = $payload['address'].', '.$locationName;

        $this->mock(Geocoder::class, function (MockInterface $mock) use ($query): void {
            $mock->shouldReceive('geocode')
                ->once()
                ->with($query)
                ->andReturn(null);
        });

        $this->postJson(self::URL, $payload)
            ->assertNotFound();

        $this->assertSame($eventsBefore, EventInstance::query()->count());
    }

    #[Test]
    public function event_update_returns_service_unavailable_without_changing_the_event(): void
    {
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create();
        $originalName = $event->name;

        $payload = $this->validPayload();
        $payload['name'] = 'Nome que não deve ser guardado';
        unset($payload['latitude'], $payload['longitude']);

        $locationName = Location::query()
            ->findOrFail($payload['location_id'])
            ->name;
        $query = $payload['address'].', '.$locationName;

        $this->mock(Geocoder::class, function (MockInterface $mock) use ($query): void {
            $mock->shouldReceive('geocode')
                ->once()
                ->with($query)
                ->andThrow(new GeocodingUnavailable('Serviço indisponível'));
        });

        $this->putJson(self::URL."/{$event->id}", $payload)
            ->assertStatus(503);

        $this->assertDatabaseHas('event_instances', [
            'id' => $event->id,
            'name' => $originalName,
        ]);

        $this->assertDatabaseMissing('event_instances', [
            'id' => $event->id,
            'name' => 'Nome que não deve ser guardado',
        ]);
    }

    #[Test]
    public function in_person_event_rejects_only_one_coordinate(): void
    {
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create();
        $payload = $this->validPayload();
        unset($payload['longitude']);

        foreach ([
            ['method' => 'post', 'url' => self::URL],
            ['method' => 'put', 'url' => self::URL."/{$event->id}"],
        ] as $request) {
            $this->{$request['method'].'Json'}($request['url'], $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['latitude', 'longitude']);
        }
    }

    #[Test]
    public function updating_an_event_notifies_registrants_with_the_justification(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create();
        $registrant = User::factory()->create();
        $inactiveRegistrant = User::factory()->inactive()->create();
        $pendingRegistrant = User::factory()->pending()->create();

        foreach ([$registrant, $inactiveRegistrant, $pendingRegistrant] as $user) {
            EventRegistration::query()->create([
                'event_instance_id' => $event->id,
                'user_id' => $user->id,
            ]);
        }

        $payload = $this->validPayload();
        $payload['justification'] = 'Alteração do horário do evento.';

        $this->putJson(self::URL."/{$event->id}", $payload)
            ->assertOk();

        Notification::assertSentTo(
            $registrant,
            EventUpdatedNotification::class,
            function (EventUpdatedNotification $notification) use ($registrant): bool {
                $this->assertContains(
                    'Justificação: Alteração do horário do evento.',
                    $notification->toMail($registrant)->introLines
                );

                return $notification->justification === 'Alteração do horário do evento.';
            }
        );

        Notification::assertNotSentTo($inactiveRegistrant, EventUpdatedNotification::class);
        Notification::assertNotSentTo($pendingRegistrant, EventUpdatedNotification::class);
    }

    #[Test]
    public function updating_an_event_without_attendee_relevant_changes_does_not_notify_registrants(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        $payload = $this->validPayload();
        $timezone = config('app.event_timezone', 'Europe/Lisbon');

        $deadline = Carbon::createFromFormat(
            '!Y-m-d',
            $payload['registration_deadline'],
            $timezone
        )
            ->setTime(23, 59)
            ->format('Y-m-d H:i:s');

        $event = EventInstance::factory()->create([
            'category_id' => $payload['category_id'],
            'location_id' => $payload['location_id'],
            'name' => $payload['name'],
            'event_date' => $payload['event_date'],
            'event_start_time' => $payload['event_start_time'].':00',
            'event_end_time' => $payload['event_end_time'].':00',
            'description' => $payload['description'],
            'address' => $payload['address'],
            'latitude' => number_format((float) $payload['latitude'], 7, '.', ''),
            'longitude' => number_format((float) $payload['longitude'], 7, '.', ''),
            'max_attendees' => $payload['max_attendees'],
            'registration_deadline' => $deadline,
            'organizer_notes' => $payload['organizer_notes'],
            'is_online' => false,
            'online_url' => null,
        ]);

        $registrant = User::factory()->create();

        EventRegistration::query()->create([
            'event_instance_id' => $event->id,
            'user_id' => $registrant->id,
        ]);

        $this->putJson(self::URL."/{$event->id}", $payload)
            ->assertOk();

        Notification::assertNothingSent();
    }

    #[Test]
    public function updating_an_event_without_justification_still_notifies_registrants(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create();
        $registrant = User::factory()->create();

        EventRegistration::query()->create([
            'event_instance_id' => $event->id,
            'user_id' => $registrant->id,
        ]);

        $payload = $this->validPayload();

        $this->putJson(self::URL."/{$event->id}", $payload)
            ->assertOk();

        Notification::assertSentTo(
            $registrant,
            EventUpdatedNotification::class,
            function (EventUpdatedNotification $notification) use ($registrant): bool {
                $this->assertNotContains(
                    'Justificação: ',
                    $notification->toMail($registrant)->introLines
                );

                return $notification->justification === null;
            }
        );
    }

    #[Test]
    public function deleting_an_event_with_registrations_requires_justification(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create();
        $registrant = User::factory()->create();

        EventRegistration::query()->create([
            'event_instance_id' => $event->id,
            'user_id' => $registrant->id,
        ]);

        $this->deleteJson(self::URL."/{$event->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['justification']);

        $this->assertDatabaseHas('event_instances', ['id' => $event->id]);
        $this->assertDatabaseHas('event_registrations', [
            'event_instance_id' => $event->id,
            'user_id' => $registrant->id,
        ]);

        Notification::assertNothingSent();
    }

    #[Test]
    public function deleting_an_event_without_registrations_does_not_require_justification(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        $event = EventInstance::factory()->create();

        $this->deleteJson(self::URL."/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('event_instances', ['id' => $event->id]);

        Notification::assertNothingSent();
    }
}
