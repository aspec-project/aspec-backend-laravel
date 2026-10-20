<?php

namespace Tests\Feature\Events;

use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MyEventRegistrationsTest extends TestCase
{
    use RefreshDatabase;

    private function createEventAt(string $name, Carbon $startsAt): EventInstance
    {
        return EventInstance::factory()->create([
            'name' => $name,
            'event_date' => $startsAt->toDateString(),
            'event_start_time' => $startsAt->format('H:i:s'),
            'registration_deadline' => $startsAt->copy()
                ->subDay()
                ->format('Y-m-d H:i:s'),
        ]);
    }

    private function createRegistration(EventInstance $event, User $user): EventRegistration
    {
        return EventRegistration::query()->create([
            'event_instance_id' => $event->id,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function active_member_sees_only_their_registrations_with_upcoming_events_first(): void
    {
        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $now = now($timezone);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $futureSoon = $this->createEventAt(
            'Evento futuro próximo',
            $now->copy()->addDays(2)->setTime(10, 0)
        );
        $futureLater = $this->createEventAt(
            'Evento futuro distante',
            $now->copy()->addDays(5)->setTime(10, 0)
        );
        $pastRecent = $this->createEventAt(
            'Evento passado recente',
            $now->copy()->subDays(1)->setTime(10, 0)
        );
        $pastOlder = $this->createEventAt(
            'Evento passado antigo',
            $now->copy()->subDays(5)->setTime(10, 0)
        );

        $this->createRegistration($futureSoon, $user);
        $this->createRegistration($futureLater, $user);
        $this->createRegistration($pastRecent, $user);
        $this->createRegistration($pastOlder, $user);
        $this->createRegistration($futureSoon, $otherUser);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/me/registrations')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(4, 'data');

        $eventNames = array_map(
            fn (array $item): string => $item['event']['name'],
            $response->json('data')
        );

        $this->assertSame([
            'Evento futuro próximo',
            'Evento futuro distante',
            'Evento passado recente',
            'Evento passado antigo',
        ], $eventNames);

        $this->assertTrue($response->json('data.0.event.is_registered'));
    }

    #[Test]
    public function active_admin_can_see_their_own_registrations(): void
    {
        $admin = User::factory()->admin()->create();
        $event = EventInstance::factory()->create();

        $this->createRegistration($event, $admin);
        Sanctum::actingAs($admin);

        $this->getJson('/api/me/registrations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event.id', $event->id);
    }

    #[Test]
    public function guest_cannot_see_registrations(): void
    {
        $this->getJson('/api/me/registrations')
            ->assertUnauthorized();
    }

    #[Test]
    public function pending_or_inactive_user_cannot_see_registrations(): void
    {
        foreach ([
            User::factory()->pending()->create(),
            User::factory()->inactive()->create(),
        ] as $user) {
            Sanctum::actingAs($user);

            $this->getJson('/api/me/registrations')
                ->assertForbidden();
        }
    }

    #[Test]
    public function active_user_without_registrations_gets_an_empty_list(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/me/registrations')
            ->assertOk()
            ->assertJsonPath('data', []);
    }
}
