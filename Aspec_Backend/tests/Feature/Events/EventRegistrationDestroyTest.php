<?php

namespace Tests\Feature\Events;

use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventRegistrationDestroyTest extends TestCase
{
    use RefreshDatabase;

    private function url(EventInstance $event): string
    {
        return "/api/events/{$event->id}/registrations";
    }

    private function createRegistration(
        EventInstance $event,
        User $user
    ): EventRegistration {
        return EventRegistration::query()->create([
            'event_instance_id' => $event->id,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function active_member_can_cancel_their_own_registration_before_deadline(): void
    {
        $user = User::factory()->create();
        $event = EventInstance::factory()->create();
        $registration = $this->createRegistration($event, $user);

        Sanctum::actingAs($user);

        $this->deleteJson($this->url($event))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('event_registrations', [
            'id' => $registration->id,
        ]);
    }

    #[Test]
    public function active_admin_can_cancel_their_own_registration(): void
    {
        $admin = User::factory()->admin()->create();
        $event = EventInstance::factory()->create();
        $registration = $this->createRegistration($event, $admin);

        Sanctum::actingAs($admin);

        $this->deleteJson($this->url($event))
            ->assertOk();

        $this->assertDatabaseMissing('event_registrations', [
            'id' => $registration->id,
        ]);
    }

    #[Test]
    public function guest_cannot_cancel_a_registration(): void
    {
        $event = EventInstance::factory()->create();

        $this->deleteJson($this->url($event))
            ->assertUnauthorized();
    }

    #[Test]
    public function pending_or_inactive_user_cannot_cancel_a_registration(): void
    {
        $event = EventInstance::factory()->create();
        $users = [
            User::factory()->pending()->create(),
            User::factory()->inactive()->create(),
        ];

        foreach ($users as $user) {
            $registration = $this->createRegistration($event, $user);
            Sanctum::actingAs($user);

            $this->deleteJson($this->url($event))
                ->assertForbidden();

            $this->assertDatabaseHas('event_registrations', [
                'id' => $registration->id,
            ]);
        }
    }

    #[Test]
    public function cancel_returns_not_found_if_the_user_is_not_registered(): void
    {
        $user = User::factory()->create();
        $event = EventInstance::factory()->create();

        Sanctum::actingAs($user);

        $this->deleteJson($this->url($event))
            ->assertNotFound();

        $this->assertDatabaseCount('event_registrations', 0);
    }

    #[Test]
    public function user_cannot_cancel_another_users_registration(): void
    {
        $event = EventInstance::factory()->create();
        $registeredUser = User::factory()->create();
        $otherUser = User::factory()->create();
        $registration = $this->createRegistration($event, $registeredUser);

        Sanctum::actingAs($otherUser);

        $this->deleteJson($this->url($event))
            ->assertNotFound();

        $this->assertDatabaseHas('event_registrations', [
            'id' => $registration->id,
        ]);
    }

    #[Test]
    public function registration_cannot_be_cancelled_after_the_deadline(): void
    {
        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $user = User::factory()->create();
        $event = EventInstance::factory()->create([
            'registration_deadline' => now($timezone)
                ->subMinute()
                ->format('Y-m-d H:i:s'),
        ]);
        $registration = $this->createRegistration($event, $user);

        Sanctum::actingAs($user);

        $this->deleteJson($this->url($event))
            ->assertConflict();

        $this->assertDatabaseHas('event_registrations', [
            'id' => $registration->id,
        ]);
    }

    #[Test]
    public function registration_can_be_cancelled_at_the_exact_deadline(): void
    {
        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $deadline = now($timezone)->addMinutes(5)->startOfSecond();
        $user = User::factory()->create();
        $event = EventInstance::factory()->create([
            'registration_deadline' => $deadline->format('Y-m-d H:i:s'),
        ]);
        $registration = $this->createRegistration($event, $user);

        $this->travelTo($deadline);
        Sanctum::actingAs($user);

        $this->deleteJson($this->url($event))
            ->assertOk();

        $this->assertDatabaseMissing('event_registrations', [
            'id' => $registration->id,
        ]);
    }
}
