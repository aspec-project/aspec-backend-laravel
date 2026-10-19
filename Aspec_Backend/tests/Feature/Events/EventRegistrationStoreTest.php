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

class EventRegistrationStoreTest extends TestCase
{
    use RefreshDatabase;

    private function url(EventInstance $event): string
    {
        return "/api/events/{$event->id}/registrations";
    }

    #[Test]
    public function active_member_can_register_for_an_event(): void
    {
        $user = User::factory()->create(); // A factory cria uma conta Active.
        Sanctum::actingAs($user);

        $event = EventInstance::factory()->create([
            'max_attendees' => 2,
        ]);

        $this->postJson($this->url($event))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.event_instance_id', $event->id);

        $this->assertDatabaseHas('event_registrations', [
            'event_instance_id' => $event->id,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function active_admin_can_register_for_an_event(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $event = EventInstance::factory()->create();

        $this->postJson($this->url($event))
            ->assertCreated();

        $this->assertDatabaseHas('event_registrations', [
            'event_instance_id' => $event->id,
            'user_id' => $admin->id,
        ]);
    }

    #[Test]
    public function guest_cannot_register_for_an_event(): void
    {
        $event = EventInstance::factory()->create();

        $this->postJson($this->url($event))
            ->assertUnauthorized();

        $this->assertDatabaseCount('event_registrations', 0);
    }

    #[Test]
    public function pending_or_inactive_user_cannot_register_for_an_event(): void
    {
        $event = EventInstance::factory()->create();

        $users = [
            User::factory()->pending()->create(),
            User::factory()->inactive()->create(),
        ];

        foreach ($users as $user) {
            Sanctum::actingAs($user);

            $this->postJson($this->url($event))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('event_registrations', 0);
    }

    #[Test]
    public function user_cannot_register_for_the_same_event_twice(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $event = EventInstance::factory()->create();

        $this->postJson($this->url($event))->assertCreated();

        $this->postJson($this->url($event))
            ->assertConflict();

        $this->assertDatabaseCount('event_registrations', 1);
    }

    #[Test]
    public function user_cannot_register_when_the_event_is_full(): void
    {
        $event = EventInstance::factory()->create([
            'max_attendees' => 1,
        ]);

        $existingUser = User::factory()->create();

        EventRegistration::query()->create([
            'event_instance_id' => $event->id,
            'user_id' => $existingUser->id,
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson($this->url($event))
            ->assertConflict();

        $this->assertDatabaseCount('event_registrations', 1);
    }

    #[Test]
    public function user_cannot_register_after_the_registration_deadline(): void
    {
        $timezone = config('app.event_timezone', 'Europe/Lisbon');

        $event = EventInstance::factory()->create([
            'registration_deadline' => now($timezone)
                ->subMinute()
                ->format('Y-m-d H:i:s'),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson($this->url($event))
            ->assertConflict();

        $this->assertDatabaseCount('event_registrations', 0);
    }

    #[Test]
    public function user_can_register_at_the_exact_registration_deadline(): void
    {
        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $deadline = now($timezone)->addMinutes(5)->startOfSecond();

        $event = EventInstance::factory()->create([
            'registration_deadline' => $deadline->format('Y-m-d H:i:s'),
        ]);

        $this->travelTo($deadline);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson($this->url($event))
            ->assertCreated();

        $this->assertDatabaseCount('event_registrations', 1);
    }
}