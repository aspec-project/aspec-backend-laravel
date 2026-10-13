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
            'registration_deadline' => $eventStartsAt->copy()->subDay()->setTime(23, 59)->toIso8601String(),
            'organizer_notes' => 'Notas do organizador.',
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
                )->toIso8601String(),
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
}
