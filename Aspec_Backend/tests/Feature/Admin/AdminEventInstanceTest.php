<?php

namespace Tests\Feature\Admin;

use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
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
                ->setTime(23, 59)
                ->format('Y-m-d H:i'),
            'organizer_notes' => 'Notas do organizador.',
            'is_online' => false,
        ];
    }

    #[Test]
    public function admin_can_list_events_with_pagination(): void
    {
        $this->actingAsAdmin();
        EventInstance::factory()->count(2)->create();

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.per_page', 15)
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        '*' => ['id', 'name', 'category', 'location', 'event_date'],
                    ],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ]);
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
    public function event_creation_rejects_invalid_times_deadline_capacity_and_coordinates(): void
    {
        $this->actingAsAdmin();

        $valid = $this->validPayload();
        $invalidCases = [
            'event_end_time' => ['event_end_time' => '09:00'],
            'registration_deadline' => [
                'registration_deadline' => Carbon::parse(
                    $valid['event_date'].' 11:00'
                )->format('Y-m-d H:i'),
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
        $payload['registration_deadline'] = $past->format('Y-m-d H:i');

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
        $payload['registration_deadline'] = $past->format('Y-m-d H:i');

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
}
