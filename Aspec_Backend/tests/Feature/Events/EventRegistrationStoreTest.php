<?php

namespace Tests\Feature\Events;

use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\EventSeries;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /**
     * @return array<int, EventInstance>
     */
    private function createSeriesSessions(array $capacities = [2, 2, 2]): array
    {
        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $firstDate = now($timezone)->addDays(30)->startOfDay();

        $series = EventSeries::query()->create([
            'frequency' => 'weekly',
            'ends_on' => $firstDate->copy()->addWeeks(2)->toDateString(),
            'registration_deadline_days_before' => 1,
        ]);

        $sessions = [];

        foreach (range(0, 2) as $index) {
            $sessionDate = $firstDate->copy()->addWeeks($index);

            $sessions[] = EventInstance::factory()->create([
                'series_id' => $series->id,
                'event_date' => $sessionDate->toDateString(),
                'registration_deadline' => $sessionDate
                    ->copy()
                    ->subDay()
                    ->setTime(23, 59)
                    ->format('Y-m-d H:i:s'),
                'max_attendees' => $capacities[$index],
            ]);
        }

        return $sessions;
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

    #[Test]
    public function member_can_register_for_this_and_following_sessions_in_a_series(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        [$previousSession, $selectedSession, $followingSession] =
            $this->createSeriesSessions();

        $response = $this->postJson($this->url($selectedSession), [
            'scope' => 'this_and_following',
        ]);

        $response
            ->assertCreated()
            ->assertJsonCount(2, 'data.registrations')
            ->assertJsonPath(
                'data.registrations.0.event_instance_id',
                $selectedSession->id
            )
            ->assertJsonPath(
                'data.registrations.1.event_instance_id',
                $followingSession->id
            );

        $this->assertDatabaseMissing('event_registrations', [
            'event_instance_id' => $previousSession->id,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('event_registrations', [
            'event_instance_id' => $selectedSession->id,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('event_registrations', [
            'event_instance_id' => $followingSession->id,
            'user_id' => $user->id,
        ]);

        $this->assertSame(
            2,
            EventRegistration::query()
                ->where('user_id', $user->id)
                ->count()
        );
    }

    #[Test]
    public function series_registration_is_all_or_nothing_when_a_following_session_is_full(): void
    {
        [$selectedSession, $followingSession, $fullSession] =
            $this->createSeriesSessions([2, 2, 1]);

        $existingUser = User::factory()->create();

        EventRegistration::query()->create([
            'event_instance_id' => $fullSession->id,
            'user_id' => $existingUser->id,
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson($this->url($selectedSession), [
            'scope' => 'this_and_following',
        ])->assertConflict();

        $this->assertDatabaseMissing('event_registrations', [
            'event_instance_id' => $selectedSession->id,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseMissing('event_registrations', [
            'event_instance_id' => $followingSession->id,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('event_registrations', [
            'event_instance_id' => $fullSession->id,
            'user_id' => $existingUser->id,
        ]);

        $this->assertSame(1, EventRegistration::query()->count());
    }

    #[Test]
    public function series_registration_is_all_or_nothing_when_already_registered_for_a_following_session(): void
    {
        [, $selectedSession, $followingSession] = $this->createSeriesSessions();

        $user = User::factory()->create();

        EventRegistration::query()->create([
            'event_instance_id' => $followingSession->id,
            'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->postJson($this->url($selectedSession), [
            'scope' => 'this_and_following',
        ])->assertConflict();

        $this->assertDatabaseMissing('event_registrations', [
            'event_instance_id' => $selectedSession->id,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('event_registrations', [
            'event_instance_id' => $followingSession->id,
            'user_id' => $user->id,
        ]);

        $this->assertSame(1, EventRegistration::query()->count());
    }

    #[Test]
    public function series_registration_is_all_or_nothing_when_a_following_session_deadline_has_passed(): void
    {
        [$selectedSession, $followingSession] = $this->createSeriesSessions();

        $timezone = config('app.event_timezone', 'Europe/Lisbon');

        $followingSession->update([
            'registration_deadline' => now($timezone)
                ->subMinute()
                ->format('Y-m-d H:i:s'),
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson($this->url($selectedSession), [
            'scope' => 'this_and_following',
        ])->assertConflict();

        $this->assertDatabaseMissing('event_registrations', [
            'event_instance_id' => $selectedSession->id,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseMissing('event_registrations', [
            'event_instance_id' => $followingSession->id,
            'user_id' => $user->id,
        ]);

        $this->assertSame(0, EventRegistration::query()->count());
    }

    #[Test]
    public function this_and_following_scope_is_rejected_for_a_standalone_event(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $event = EventInstance::factory()->create();

        $this->postJson($this->url($event), [
            'scope' => 'this_and_following',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scope']);

        $this->assertDatabaseCount('event_registrations', 0);
    }
}
