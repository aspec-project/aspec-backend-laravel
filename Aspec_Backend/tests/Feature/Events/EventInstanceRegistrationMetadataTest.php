<?php

namespace Tests\Feature\Events;

use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventInstanceRegistrationMetadataTest extends TestCase
{
    use RefreshDatabase;

    private function createOnlineEvent(int $capacity = 3): EventInstance
    {
        $timezone = config('app.event_timezone', 'Europe/Lisbon');

        return EventInstance::factory()->create([
            'is_online' => true,
            'online_url' => 'https://meet.example.com/event',
            'address' => null,
            'latitude' => null,
            'longitude' => null,
            'max_attendees' => $capacity,
            'registration_deadline' => now($timezone)
                ->addDay()
                ->format('Y-m-d H:i:s'),
        ]);
    }

    private function registerUser(EventInstance $event, User $user): void
    {
        EventRegistration::query()->create([
            'event_instance_id' => $event->id,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function guest_agenda_shows_registration_metadata_but_hides_online_url(): void
    {
        $event = $this->createOnlineEvent();
        $this->registerUser($event, User::factory()->create());

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $event->id)
            ->assertJsonPath('data.items.0.registrations_count', 1)
            ->assertJsonPath('data.items.0.available_spots', 2)
            ->assertJsonPath('data.items.0.registration_open', true)
            ->assertJsonPath('data.items.0.is_registered', false)
            ->assertJsonMissingPath('data.items.0.online_url')
            ->assertJsonPath('data.items.0.is_online', true)
            ->assertJsonPath('data.items.0.address', null)
            ->assertJsonPath('data.items.0.latitude', null)
            ->assertJsonPath('data.items.0.longitude', null);
    }

    #[Test]
    public function registered_member_does_not_see_online_url_before_registration_deadline(): void
    {
        $event = $this->createOnlineEvent();
        $user = User::factory()->create();
        $this->registerUser($event, $user);

        Sanctum::actingAs($user);

        $this->getJson("/api/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.registration_open', true)
            ->assertJsonPath('data.is_registered', true)
            ->assertJsonMissingPath('data.online_url');
    }

    #[Test]
    public function authenticated_nonregistrant_does_not_see_online_url(): void
    {
        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $event = $this->createOnlineEvent();

        $event->update([
            'registration_deadline' => now($timezone)
                ->subMinute()
                ->format('Y-m-d H:i:s'),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.is_registered', false)
            ->assertJsonMissingPath('data.online_url');
    }

    #[Test]
    public function full_event_has_no_available_spots_and_is_not_open(): void
    {
        $event = $this->createOnlineEvent(capacity: 1);
        $this->registerUser($event, User::factory()->create());

        $this->getJson("/api/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.registrations_count', 1)
            ->assertJsonPath('data.available_spots', 0)
            ->assertJsonPath('data.registration_open', false);
    }

    #[Test]
    public function event_with_passed_registration_deadline_is_not_open(): void
    {
        $timezone = config('app.event_timezone', 'Europe/Lisbon');

        $event = EventInstance::factory()->create([
            'registration_deadline' => now($timezone)
                ->subMinute()
                ->format('Y-m-d H:i:s'),
            'max_attendees' => 3,
        ]);

        $this->getJson("/api/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.registrations_count', 0)
            ->assertJsonPath('data.available_spots', 3)
            ->assertJsonPath('data.registration_open', false);
    }

    #[Test]
    public function active_admin_can_see_online_url_in_admin_event_detail(): void
    {
        $event = $this->createOnlineEvent();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/admin/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.online_url', 'https://meet.example.com/event');
    }

    #[Test]
    public function registered_member_does_not_see_online_url_at_the_exact_deadline(): void
    {
        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $deadline = now($timezone)->startOfSecond();

        $event = $this->createOnlineEvent();
        $event->update([
            'registration_deadline' => $deadline->format('Y-m-d H:i:s'),
        ]);

        $user = User::factory()->create();
        $this->registerUser($event, $user);
        Sanctum::actingAs($user);

        $this->travelTo($deadline);

        $this->getJson("/api/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.is_registered', true)
            ->assertJsonMissingPath('data.online_url');
    }

    #[Test]
    public function registered_member_sees_online_url_after_registration_deadline(): void
    {
        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $event = $this->createOnlineEvent();

        $event->update([
            'registration_deadline' => now($timezone)
                ->subMinute()
                ->format('Y-m-d H:i:s'),
        ]);

        $user = User::factory()->create();
        $this->registerUser($event, $user);
        Sanctum::actingAs($user);

        $this->getJson("/api/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.registration_open', false)
            ->assertJsonPath('data.is_registered', true)
            ->assertJsonPath('data.online_url', 'https://meet.example.com/event');
    }
}
