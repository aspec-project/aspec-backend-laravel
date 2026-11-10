<?php

namespace Tests\Feature\Admin;

use App\Enums\InactiveReason;
use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\EventSeries;
use App\Models\PrayerCard;
use App\Models\User;
use App\Notifications\EventCancelledNotification;
use App\Notifications\EventUpdatedNotification;
use App\Notifications\UserBlockedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsPrayerCards;
use Tests\TestCase;

/**
 * PATCH /api/admin/users/{id}/block: bloquear um membro apaga as inscrições dele em eventos que ainda
 * não começaram (hora de Lisboa); as de eventos já começados ou passados ficam.
 */
class AdminUserBlockRegistrationsTest extends TestCase
{
    use BuildsPrayerCards, RefreshDatabase;

    private User $admin;

    private User $memberToBlock;

    private User $otherMember;

    private EventRegistration $yesterday;

    private EventRegistration $todayMorning;

    private EventRegistration $todayEvening;

    private EventRegistration $tomorrow;

    private EventRegistration $otherTomorrow;

    private EventInstance $tomorrowEvent;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelToLisbon('2026-11-10 15:00');

        $this->admin = User::factory()->admin()->create();
        $this->memberToBlock = $this->member();
        $this->otherMember = $this->member();

        $this->tomorrowEvent = $this->eventAt('2026-11-11 09:00');

        $this->yesterday = $this->register($this->memberToBlock, $this->eventAt('2026-11-09 19:00'));
        $this->todayMorning = $this->register($this->memberToBlock, $this->eventAt('2026-11-10 10:00'));
        $this->todayEvening = $this->register($this->memberToBlock, $this->eventAt('2026-11-10 18:00'));
        $this->tomorrow = $this->register($this->memberToBlock, $this->tomorrowEvent);
        $this->otherTomorrow = $this->register($this->otherMember, $this->tomorrowEvent);

        Sanctum::actingAs($this->admin);
    }

    /**
     * Evento sem cartões de oração que começa em $startsAt (hora de Lisboa).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function eventAt(string $startsAt, array $attributes = []): EventInstance
    {
        return $this->prayerEvent($startsAt, [
            'prayer_cards_enabled' => false,
            'prayer_verse' => null,
            ...$attributes,
        ]);
    }

    private function blockUrl(User $user): string
    {
        return "/api/admin/users/{$user->id}/block";
    }

    /**
     * @param  list<EventRegistration>  $registrations
     */
    private function assertRegistrationsKept(array $registrations): void
    {
        foreach ($registrations as $registration) {
            $this->assertDatabaseHas('event_registrations', ['id' => $registration->id]);
        }
    }

    /**
     * @param  list<EventRegistration>  $registrations
     */
    private function assertRegistrationsDeleted(array $registrations): void
    {
        foreach ($registrations as $registration) {
            $this->assertDatabaseMissing('event_registrations', ['id' => $registration->id]);
        }
    }

    #[Test]
    public function blocking_deletes_registrations_in_events_that_have_not_started(): void
    {
        $this->patchJson($this->blockUrl($this->memberToBlock))
            ->assertOk()
            ->assertJsonPath('message', 'Utilizador bloqueado com sucesso.');

        $this->assertRegistrationsDeleted([$this->todayEvening, $this->tomorrow]);
        $this->assertRegistrationsKept([$this->yesterday, $this->todayMorning, $this->otherTomorrow]);
    }

    #[Test]
    public function blocking_frees_the_spot_in_a_full_event(): void
    {
        $fullEvent = $this->eventAt('2026-11-11 19:00', ['max_attendees' => 1]);
        $this->register($this->memberToBlock, $fullEvent);

        $this->patchJson($this->blockUrl($this->memberToBlock))->assertOk();

        Sanctum::actingAs($this->otherMember);

        $this->postJson("/api/events/{$fullEvent->id}/registrations")
            ->assertCreated()
            ->assertJsonPath('data.event_instance_id', $fullEvent->id);
    }

    #[Test]
    public function blocking_deletes_only_upcoming_sessions_of_a_series(): void
    {
        $series = EventSeries::query()->create([
            'frequency' => 'weekly',
            'ends_on' => '2026-11-23',
            'registration_deadline_days_before' => 1,
        ]);

        $sessions = array_map(
            fn (string $startsAt): EventRegistration => $this->register(
                $this->memberToBlock,
                $this->eventAt($startsAt, ['series_id' => $series->id])
            ),
            ['2026-11-09 19:00', '2026-11-16 19:00', '2026-11-23 19:00']
        );

        $this->patchJson($this->blockUrl($this->memberToBlock))->assertOk();

        $this->assertRegistrationsKept([$sessions[0]]);
        $this->assertRegistrationsDeleted([$sessions[1], $sessions[2]]);
    }

    #[Test]
    public function blocking_sends_only_the_blocked_email(): void
    {
        $this->patchJson($this->blockUrl($this->memberToBlock))->assertOk();

        Notification::assertSentToTimes($this->memberToBlock, UserBlockedNotification::class, 1);
        Notification::assertSentTimes(EventCancelledNotification::class, 0);
        Notification::assertSentTimes(EventUpdatedNotification::class, 0);
    }

    #[Test]
    public function refused_block_keeps_the_registrations(): void
    {
        $blocked = $this->member(User::factory()->inactive(InactiveReason::Blocked)->create());
        $otherAdmin = User::factory()->admin()->create();

        $registrations = [
            $this->register($blocked, $this->tomorrowEvent),
            $this->register($otherAdmin, $this->tomorrowEvent),
            $this->register($this->admin, $this->tomorrowEvent),
        ];

        foreach ([$blocked, $otherAdmin, $this->admin] as $target) {
            $this->patchJson($this->blockUrl($target))->assertConflict();
        }

        $this->assertRegistrationsKept($registrations);
    }

    #[Test]
    public function unblocking_does_not_restore_registrations(): void
    {
        $this->patchJson($this->blockUrl($this->memberToBlock))->assertOk();
        $this->patchJson("/api/admin/users/{$this->memberToBlock->id}/unblock")->assertOk();

        $this->assertRegistrationsDeleted([$this->todayEvening, $this->tomorrow]);
        $this->assertSame(2, EventRegistration::query()->where('user_id', $this->memberToBlock->id)->count());
    }

    #[Test]
    public function other_deactivations_keep_registrations(): void
    {
        $this->memberToBlock->deactivate(InactiveReason::Unpaid->value);
        $this->otherMember->deactivate(InactiveReason::Cancelled->value);

        $this->assertRegistrationsKept([
            $this->yesterday,
            $this->todayMorning,
            $this->todayEvening,
            $this->tomorrow,
            $this->otherTomorrow,
        ]);
    }

    #[Test]
    public function blocking_uses_lisbon_time_in_summer(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 17:30:00', 'UTC'));
        $member = $this->member();
        $started = $this->register($member, $this->eventAt('2026-07-15 18:00'));
        $notStarted = $this->register($member, $this->eventAt('2026-07-15 19:00'));

        $this->patchJson($this->blockUrl($member))->assertOk();

        $this->assertRegistrationsKept([$started]);
        $this->assertRegistrationsDeleted([$notStarted]);
    }

    #[Test]
    public function blocked_member_card_in_an_ongoing_event_is_kept_but_not_distributed(): void
    {
        $event = $this->prayerEvent('2026-11-10 14:00');
        $blocked = $this->registeredMember($event);
        $blockedCard = $this->cardFor($blocked, $event);
        [$second, $third] = $this->membersWithCards($event, 2);

        $this->patchJson($this->blockUrl($blocked))->assertOk();

        $this->assertDatabaseHas('event_registrations', ['event_instance_id' => $event->id, 'user_id' => $blocked->id]);
        $this->assertDatabaseHas('prayer_cards', ['id' => $blockedCard->id]);

        $this->postJson("/api/admin/events/{$event->id}/prayer-cards/distribute")->assertOk();

        $this->assertNull($blockedCard->fresh()->recipient_user_id);
        $this->assertSame(0, PrayerCard::query()->where('recipient_user_id', $blocked->id)->count());
        $this->assertDatabaseHas('prayer_cards', ['author_user_id' => $second->id, 'recipient_user_id' => $third->id]);
        $this->assertDatabaseHas('prayer_cards', ['author_user_id' => $third->id, 'recipient_user_id' => $second->id]);
    }
}
