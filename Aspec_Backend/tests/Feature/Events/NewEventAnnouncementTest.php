<?php

namespace Tests\Feature\Events;

use App\Enums\InactiveReason;
use App\Jobs\AnnounceNewEventJob;
use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\EventSeries;
use App\Models\Location;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\NewEventAnnouncementNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Anúncio por email de um evento pontual acabado de criar pelo administrador. Vai para as contas
 * Active com perfil e com a preferência ligada (Member ou Admin), uma só vez por evento, sem o link
 * da reunião online (na criação ainda ninguém está inscrito). Sessões de séries nunca são anunciadas.
 */
class NewEventAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/events';

    private const FRONTEND = 'https://app.aspec.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => self::FRONTEND]);
    }

    private function memberWithProfile(?User $user = null, string $name = 'Membro Ativo'): User
    {
        $user ??= User::factory()->create();
        MemberProfile::factory()->create(['user_id' => $user->id, 'name' => $name]);

        return $user;
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->technicalAdmin()->create();
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
            'name' => 'Jantar de Networking Anunciado',
            'event_date' => $eventStartsAt->format('Y-m-d'),
            'event_start_time' => '10:00',
            'event_end_time' => '12:00',
            'description' => 'Descrição do evento.',
            'address' => 'Rua de teste, 10',
            'latitude' => 38.7223,
            'longitude' => -9.1393,
            'max_attendees' => 30,
            'registration_deadline' => $eventStartsAt->copy()->subDay()->toDateString(),
            'organizer_notes' => 'Notas do organizador.',
            'is_online' => false,
        ];
    }

    private function oneOffEvent(array $attributes = []): EventInstance
    {
        $date = now('Europe/Lisbon')->addDays(20);

        return EventInstance::factory()->create([
            'name' => 'Pequeno-almoço de Negócios',
            'event_date' => $date->toDateString(),
            'event_start_time' => '09:00:00',
            'event_end_time' => '11:00:00',
            'registration_deadline' => $date->copy()->subDay()->setTime(23, 59)->format('Y-m-d H:i:s'),
            ...$attributes,
        ]);
    }

    private function announce(EventInstance|string $event): void
    {
        AnnounceNewEventJob::dispatchSync($event instanceof EventInstance ? $event->id : $event);
    }

    private function renderedMail(NewEventAnnouncementNotification $notification, User $notifiable): string
    {
        return (string) $notification->toMail($notifiable)->render();
    }

    #[Test]
    public function creating_a_one_off_event_queues_the_announcement_job(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->validPayload())->assertCreated();

        Queue::assertPushed(AnnounceNewEventJob::class, 1);
    }

    #[Test]
    public function creating_a_recurring_series_queues_no_announcement(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $payload = $this->validPayload();
        unset($payload['registration_deadline']);
        $payload['recurrence'] = [
            'frequency' => 'weekly',
            'ends_on' => Carbon::parse($payload['event_date'])->addWeeks(2)->toDateString(),
            'registration_deadline_days_before' => 1,
        ];

        $this->postJson(self::URL, $payload)->assertCreated();

        Queue::assertNotPushed(AnnounceNewEventJob::class);
    }

    #[Test]
    public function invalid_event_creation_queues_no_announcement(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $this->postJson(self::URL, [...$this->validPayload(), 'name' => ''])->assertUnprocessable();

        Queue::assertNotPushed(AnnounceNewEventJob::class);
    }

    #[Test]
    public function updating_an_event_queues_no_announcement(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $event = $this->oneOffEvent();

        $this->putJson(self::URL.'/'.$event->id, $this->validPayload())->assertOk();

        Queue::assertNotPushed(AnnounceNewEventJob::class);
    }

    #[Test]
    public function announcement_job_and_notification_are_queued(): void
    {
        $this->assertTrue(is_subclass_of(AnnounceNewEventJob::class, ShouldQueue::class));
        $this->assertTrue(is_subclass_of(NewEventAnnouncementNotification::class, ShouldQueue::class));
    }

    #[Test]
    public function notification_is_discarded_when_the_event_was_deleted_before_sending(): void
    {
        $notification = new NewEventAnnouncementNotification($this->oneOffEvent());

        $this->assertTrue($notification->deleteWhenMissingModels);
    }

    #[Test]
    public function creating_a_one_off_event_announces_it_to_active_members(): void
    {
        Notification::fake();
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();

        $response = $this->postJson(self::URL, $this->validPayload())->assertCreated();
        $eventId = $response->json('data.id');

        Notification::assertSentTo(
            $member,
            NewEventAnnouncementNotification::class,
            fn (NewEventAnnouncementNotification $notification) => str_contains(
                $this->renderedMail($notification, $member),
                self::FRONTEND.'/eventos/'.$eventId
            )
        );
        $this->assertNotNull(DB::table('event_instances')->where('id', $eventId)->value('announced_at'));
    }

    #[Test]
    public function active_member_with_profile_and_preference_receives_the_announcement(): void
    {
        Notification::fake();
        $member = $this->memberWithProfile();

        $this->announce($this->oneOffEvent());

        Notification::assertSentToTimes($member, NewEventAnnouncementNotification::class, 1);
    }

    #[Test]
    public function admin_with_member_profile_receives_the_announcement(): void
    {
        Notification::fake();
        $admin = $this->memberWithProfile(User::factory()->admin()->create());

        $this->announce($this->oneOffEvent());

        Notification::assertSentTo($admin, NewEventAnnouncementNotification::class);
    }

    #[Test]
    public function active_member_in_grace_period_receives_the_announcement(): void
    {
        Notification::fake();
        $member = $this->memberWithProfile();
        $member->forceFill(['grace_ends_at' => now()->addDays(3)])->save();

        $this->announce($this->oneOffEvent());

        Notification::assertSentTo($member, NewEventAnnouncementNotification::class);
    }

    #[Test]
    public function pending_approved_and_inactive_accounts_never_receive_the_announcement(): void
    {
        Notification::fake();
        $pending = $this->memberWithProfile(User::factory()->pending()->create());
        $approved = $this->memberWithProfile(User::factory()->approved()->create());
        $unpaid = $this->memberWithProfile(User::factory()->inactive(InactiveReason::Unpaid)->create());
        $blocked = $this->memberWithProfile(User::factory()->inactive(InactiveReason::Blocked)->create());
        $active = $this->memberWithProfile();

        $this->announce($this->oneOffEvent());

        Notification::assertSentTo($active, NewEventAnnouncementNotification::class);
        foreach ([$pending, $approved, $unpaid, $blocked] as $user) {
            Notification::assertNotSentTo($user, NewEventAnnouncementNotification::class);
        }
    }

    #[Test]
    public function anonymized_account_never_receives_the_announcement(): void
    {
        Notification::fake();
        $anonymized = $this->memberWithProfile();
        $anonymized->anonymizeAndDelete();

        $this->announce($this->oneOffEvent());

        Notification::assertNotSentTo(
            User::withTrashed()->findOrFail($anonymized->id),
            NewEventAnnouncementNotification::class
        );
    }

    #[Test]
    public function active_accounts_without_profile_never_receive_the_announcement(): void
    {
        Notification::fake();
        $technicalAdmin = User::factory()->technicalAdmin()->create();
        $memberWithoutProfile = User::factory()->create();

        $this->announce($this->oneOffEvent());

        Notification::assertNotSentTo($technicalAdmin, NewEventAnnouncementNotification::class);
        Notification::assertNotSentTo($memberWithoutProfile, NewEventAnnouncementNotification::class);
    }

    #[Test]
    public function member_with_new_events_preference_disabled_does_not_receive_the_announcement(): void
    {
        Notification::fake();
        $optedOut = $this->memberWithProfile(User::factory()->create(['notify_new_events' => false]));
        $weeklyOnlyOff = $this->memberWithProfile(User::factory()->create(['notify_weekly_agenda' => false]));

        $this->announce($this->oneOffEvent());

        Notification::assertNotSentTo($optedOut, NewEventAnnouncementNotification::class);
        Notification::assertSentTo($weeklyOnlyOff, NewEventAnnouncementNotification::class);
    }

    #[Test]
    public function running_the_job_twice_sends_one_email_per_recipient_and_marks_the_event(): void
    {
        Notification::fake();
        $first = $this->memberWithProfile();
        $second = $this->memberWithProfile();
        $event = $this->oneOffEvent();

        $this->announce($event);
        $this->announce($event);

        Notification::assertSentToTimes($first, NewEventAnnouncementNotification::class, 1);
        Notification::assertSentToTimes($second, NewEventAnnouncementNotification::class, 1);
        $this->assertNotNull($event->fresh()->announced_at);
    }

    #[Test]
    public function already_announced_event_is_not_announced_again(): void
    {
        Notification::fake();
        $this->memberWithProfile();
        $event = $this->oneOffEvent();
        DB::table('event_instances')->where('id', $event->id)->update(['announced_at' => now()->subHour()]);

        $this->announce($event);

        Notification::assertNothingSent();
    }

    #[Test]
    public function deleted_event_sends_nothing_and_does_not_fail(): void
    {
        Notification::fake();
        $this->memberWithProfile();
        $event = $this->oneOffEvent();
        $eventId = $event->id;
        $event->delete();

        $this->announce($eventId);

        Notification::assertNothingSent();
    }

    #[Test]
    public function series_session_is_never_announced(): void
    {
        Notification::fake();
        $this->memberWithProfile();
        $series = EventSeries::create([
            'frequency' => 'weekly',
            'ends_on' => now()->addMonths(2)->toDateString(),
            'registration_deadline_days_before' => 1,
        ]);
        $session = $this->oneOffEvent(['series_id' => $series->id]);

        $this->announce($session);

        Notification::assertNothingSent();
        $this->assertNull($session->fresh()->announced_at);
    }

    #[Test]
    public function event_whose_registration_deadline_has_passed_is_not_announced(): void
    {
        Notification::fake();
        $this->memberWithProfile();
        $event = $this->oneOffEvent([
            'registration_deadline' => now('Europe/Lisbon')->subDay()->setTime(23, 59)->format('Y-m-d H:i:s'),
        ]);

        $this->announce($event);

        Notification::assertNothingSent();
    }

    #[Test]
    public function every_recipient_is_reached_exactly_once_across_batches(): void
    {
        Notification::fake();
        $recipients = collect(range(1, 201))->map(fn () => $this->memberWithProfile());

        $this->announce($this->oneOffEvent());

        foreach ($recipients as $recipient) {
            Notification::assertSentToTimes($recipient, NewEventAnnouncementNotification::class, 1);
        }
    }

    #[Test]
    public function email_greets_the_member_and_describes_the_event_with_link_and_footer(): void
    {
        Notification::fake();
        $member = $this->memberWithProfile(name: 'Joana Ferreira');
        $date = now('Europe/Lisbon')->addDays(25);
        $event = $this->oneOffEvent([
            'name' => 'Almoço de Empresários do Norte',
            'event_date' => $date->toDateString(),
            'event_start_time' => '12:30:00',
            'registration_deadline' => $date->copy()->subDay()->setTime(23, 59)->format('Y-m-d H:i:s'),
        ]);

        $this->announce($event);

        Notification::assertSentTo(
            $member,
            NewEventAnnouncementNotification::class,
            function (NewEventAnnouncementNotification $notification) use ($member, $event, $date) {
                $mail = $this->renderedMail($notification, $member);

                return str_contains($mail, 'Joana Ferreira')
                    && str_contains($mail, 'Almoço de Empresários do Norte')
                    && str_contains($mail, $date->format('d/m/Y'))
                    && str_contains($mail, '12:30')
                    && str_contains($mail, self::FRONTEND.'/eventos/'.$event->id)
                    && str_contains($mail, self::FRONTEND.'/perfil/conta');
            }
        );
    }

    #[Test]
    public function online_event_announcement_never_includes_the_meeting_link(): void
    {
        Notification::fake();
        $member = $this->memberWithProfile();
        $event = $this->oneOffEvent([
            'is_online' => true,
            'online_url' => 'https://meet.example.com/segredo-123',
            'address' => null,
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->announce($event);

        Notification::assertSentTo(
            $member,
            NewEventAnnouncementNotification::class,
            function (NewEventAnnouncementNotification $notification) use ($member) {
                $mail = $this->renderedMail($notification, $member);

                return ! str_contains($mail, 'meet.example.com') && ! str_contains($mail, 'segredo-123');
            }
        );
    }

    #[Test]
    public function queued_announcement_is_not_sent_when_the_member_was_deactivated_or_turned_announcements_off_meanwhile(): void
    {
        $blocked = $this->memberWithProfile();
        $optedOut = $this->memberWithProfile();
        $stillActive = $this->memberWithProfile();
        $notification = new NewEventAnnouncementNotification($this->oneOffEvent());

        $blocked->deactivate(InactiveReason::Blocked->value);
        $optedOut->forceFill(['notify_new_events' => false])->save();

        $this->assertFalse($notification->shouldSend($blocked->fresh(), 'mail'));
        $this->assertFalse($notification->shouldSend($optedOut->fresh(), 'mail'));
        $this->assertTrue($notification->shouldSend($stillActive->fresh(), 'mail'));
    }
}
