<?php

namespace Tests\Feature\Events;

use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\EventSeries;
use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventRegistrationSeriesMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now('Europe/Lisbon')->setTime(12, 0));
    }

    private function url(EventInstance $event): string
    {
        return "/api/events/{$event->id}/registrations";
    }

    private function activeMemberWithProfile(): User
    {
        $user = User::factory()->create();

        MemberProfile::factory()->create([
            'user_id' => $user->id,
        ]);

        return $user;
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

    private function fillSession(EventInstance $session): void
    {
        foreach (range(1, $session->max_attendees) as $ignored) {
            EventRegistration::query()->create([
                'event_instance_id' => $session->id,
                'user_id' => $this->activeMemberWithProfile()->id,
            ]);
        }
    }

    private function closeDeadline(EventInstance $session): void
    {
        $session->update([
            'registration_deadline' => now(config('app.event_timezone', 'Europe/Lisbon'))
                ->subMinute()
                ->format('Y-m-d H:i:s'),
        ]);
    }

    private function sessionDate(EventInstance $session): string
    {
        return $session->fresh()->event_date->format('d/m/Y');
    }

    #[Test]
    public function series_registration_names_the_following_session_where_the_member_is_already_registered(): void
    {
        [$selected, , $last] = $this->createSeriesSessions();

        $user = $this->activeMemberWithProfile();

        EventRegistration::query()->create([
            'event_instance_id' => $last->id,
            'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->postJson($this->url($selected), [
            'scope' => 'this_and_following',
        ])
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', "Já está inscrito na sessão de {$this->sessionDate($last)}.");

        $this->assertSame(1, EventRegistration::query()->count());
        $this->assertDatabaseHas('event_registrations', [
            'event_instance_id' => $last->id,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function series_registration_names_the_following_session_that_is_full(): void
    {
        [$selected, , $last] = $this->createSeriesSessions([2, 2, 1]);

        $this->fillSession($last);

        $user = $this->activeMemberWithProfile();
        Sanctum::actingAs($user);

        $this->postJson($this->url($selected), [
            'scope' => 'this_and_following',
        ])
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', "A sessão de {$this->sessionDate($last)} atingiu a lotação máxima.");

        $this->assertSame(0, EventRegistration::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, EventRegistration::query()->count());
    }

    #[Test]
    public function series_registration_names_the_following_session_whose_deadline_has_passed(): void
    {
        [$selected, $following] = $this->createSeriesSessions();

        $this->closeDeadline($following);

        Sanctum::actingAs($this->activeMemberWithProfile());

        $this->postJson($this->url($selected), [
            'scope' => 'this_and_following',
        ])
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', "O prazo de inscrição da sessão de {$this->sessionDate($following)} terminou.");

        $this->assertSame(0, EventRegistration::query()->count());
    }

    #[Test]
    public function series_registration_names_the_selected_session_when_it_is_the_one_that_fails(): void
    {
        [$selected] = $this->createSeriesSessions([1, 2, 2]);

        $this->fillSession($selected);

        $user = $this->activeMemberWithProfile();
        Sanctum::actingAs($user);

        $this->postJson($this->url($selected), [
            'scope' => 'this_and_following',
        ])
            ->assertConflict()
            ->assertJsonPath('message', "A sessão de {$this->sessionDate($selected)} atingiu a lotação máxima.");

        $this->assertSame(0, EventRegistration::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function series_registration_names_the_earliest_failing_session(): void
    {
        [$selected, $following, $last] = $this->createSeriesSessions([2, 1, 2]);

        $this->fillSession($following);

        $user = $this->activeMemberWithProfile();

        EventRegistration::query()->create([
            'event_instance_id' => $last->id,
            'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson($this->url($selected), [
            'scope' => 'this_and_following',
        ])
            ->assertConflict()
            ->assertJsonPath('message', "A sessão de {$this->sessionDate($following)} atingiu a lotação máxima.");

        $this->assertStringNotContainsString($this->sessionDate($last), $response->json('message'));
        $this->assertSame(1, EventRegistration::query()->where('user_id', $user->id)->count());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function singleSessionConflicts(): array
    {
        return [
            'já inscrito' => ['already_registered', 'Já está inscrito neste evento.'],
            'lotação esgotada' => ['full', 'O evento atingiu a lotação máxima.'],
            'prazo terminado' => ['deadline_passed', 'O prazo de inscrição para este evento terminou.'],
        ];
    }

    #[Test]
    #[DataProvider('singleSessionConflicts')]
    public function single_session_registration_in_a_series_keeps_the_generic_messages(
        string $conflict,
        string $expectedMessage
    ): void {
        [$selected] = $this->createSeriesSessions([1, 2, 2]);

        $user = $this->activeMemberWithProfile();

        match ($conflict) {
            'already_registered' => EventRegistration::query()->create([
                'event_instance_id' => $selected->id,
                'user_id' => $user->id,
            ]),
            'full' => $this->fillSession($selected),
            'deadline_passed' => $this->closeDeadline($selected),
        };

        Sanctum::actingAs($user);

        $this->postJson($this->url($selected))
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', $expectedMessage);
    }
}
