<?php

namespace Tests\Feature\Console;

use App\Enums\InactiveReason;
use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\EventSeries;
use App\Models\Location;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\WeeklyAgendaNotification;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsEventEmailLinks;
use Tests\TestCase;

/**
 * Agenda semanal (events:send-weekly-agenda), enviada ao sábado às 10:00 de Lisboa.
 *
 * A janela da secção "A sua semana" vai do momento do envio (inclusive) até ao sábado seguinte às
 * 23:59 (inclusive), pela hora de início do evento em Lisboa. Os eventos de sábado depois das 10:00
 * aparecem de propósito em duas agendas seguidas, para o membro saber com antecedência.
 * A secção "Inscrições abertas" lista eventos pontuais com vagas e prazo aberto em que o membro não
 * está inscrito. Os testes correm no verão (Lisboa = UTC+1) para apanhar comparações feitas em UTC.
 */
class SendWeeklyAgendaTest extends TestCase
{
    use AssertsEventEmailLinks, RefreshDatabase;

    private const COMMAND = 'events:send-weekly-agenda';

    private const FRONTEND = 'https://app.aspec.test';

    private const TZ = 'Europe/Lisbon';

    private const CALENDAR_ICON = 'https://media.aspec.test/branding/calendar-icon.png';

    private const LINK_PENDING_TEXT = 'O link será disponibilizado na página do evento após o fecho das inscrições.';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['app.frontend_url' => self::FRONTEND, 'mail.calendar_icon_url' => self::CALENDAR_ICON]);
        $this->travelToLisbon('2027-06-05 10:00');
    }

    private function travelToLisbon(string $dateTime): void
    {
        $this->travelTo(Carbon::createFromFormat('Y-m-d H:i', $dateTime, self::TZ)->utc());
    }

    private function member(?User $user = null, string $name = 'Membro Ativo'): User
    {
        $user ??= User::factory()->create();
        MemberProfile::factory()->create(['user_id' => $user->id, 'name' => $name]);

        return $user;
    }

    /**
     * Evento que começa em $startsAt (hora de Lisboa); prazo por omissão na véspera às 23:59.
     */
    private function event(string $name, string $startsAt, array $attributes = []): EventInstance
    {
        $start = Carbon::createFromFormat('Y-m-d H:i', $startsAt, self::TZ);

        return EventInstance::factory()->create([
            'name' => $name,
            'event_date' => $start->toDateString(),
            'event_start_time' => $start->format('H:i:s'),
            'event_end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'max_attendees' => 50,
            'registration_deadline' => $start->copy()->subDay()->setTime(23, 59)->format('Y-m-d H:i:s'),
            ...$attributes,
        ]);
    }

    private function onlineAttributes(string $url): array
    {
        return [
            'is_online' => true,
            'online_url' => $url,
            'address' => null,
            'latitude' => null,
            'longitude' => null,
        ];
    }

    private function series(): EventSeries
    {
        return EventSeries::create([
            'frequency' => 'weekly',
            'ends_on' => '2027-12-31',
            'registration_deadline_days_before' => 1,
        ]);
    }

    private function register(User $user, EventInstance $event): EventRegistration
    {
        return EventRegistration::create(['event_instance_id' => $event->id, 'user_id' => $user->id]);
    }

    private function runAgenda(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();
    }

    private function mailFor(User $user): string
    {
        $notification = Notification::sent($user, WeeklyAgendaNotification::class)->last();
        $this->assertNotNull($notification, 'A agenda semanal não foi enviada a este utilizador.');

        return (string) $notification->toMail($user)->render();
    }

    /**
     * Texto da secção "A sua semana" (até ao início da secção "Inscrições abertas", se existir).
     */
    private function sectionA(string $mail): string
    {
        $start = mb_strpos($mail, 'A sua semana');
        if ($start === false) {
            return '';
        }
        $end = mb_strpos($mail, 'Inscrições abertas', $start);

        return $end === false ? mb_substr($mail, $start) : mb_substr($mail, $start, $end - $start);
    }

    private function sectionB(string $mail): string
    {
        $start = mb_strpos($mail, 'Inscrições abertas');

        return $start === false ? '' : mb_substr($mail, $start);
    }

    private function assertInSectionA(string $eventName, User $user): void
    {
        $this->assertStringContainsString($eventName, $this->sectionA($this->mailFor($user)));
    }

    private function sentAt(User $user): ?string
    {
        return DB::table('users')->where('id', $user->id)->value('weekly_agenda_sent_at');
    }

    #[Test]
    public function event_on_sending_saturday_at_0959_is_outside_the_window(): void
    {
        $member = $this->member();
        $this->register($member, $this->event('Oração Matinal', '2027-06-05 09:59'));

        $this->runAgenda();

        Notification::assertNotSentTo($member, WeeklyAgendaNotification::class);
    }

    #[Test]
    public function event_on_sending_saturday_at_1000_is_inside_the_window(): void
    {
        $member = $this->member();
        $this->register($member, $this->event('Encontro das Dez', '2027-06-05 10:00'));

        $this->runAgenda();

        $this->assertInSectionA('Encontro das Dez', $member);
    }

    #[Test]
    public function event_on_friday_at_2359_is_inside_the_window(): void
    {
        $member = $this->member();
        $this->register($member, $this->event('Ceia de Sexta', '2027-06-11 23:59'));

        $this->runAgenda();

        $this->assertInSectionA('Ceia de Sexta', $member);
    }

    #[Test]
    public function event_on_next_saturday_at_2359_is_inside_the_window(): void
    {
        $member = $this->member();
        $this->register($member, $this->event('Vigília de Sábado', '2027-06-12 23:59'));

        $this->runAgenda();

        $this->assertInSectionA('Vigília de Sábado', $member);
    }

    #[Test]
    public function event_on_next_sunday_at_0000_is_outside_the_window(): void
    {
        $member = $this->member();
        $this->register($member, $this->event('Culto de Domingo', '2027-06-13 00:00'));

        $this->runAgenda();

        Notification::assertNotSentTo($member, WeeklyAgendaNotification::class);
    }

    #[Test]
    public function saturday_event_after_ten_appears_in_two_consecutive_agendas(): void
    {
        $member = $this->member();
        $this->register($member, $this->event('Almoço de Sábado', '2027-06-12 13:00'));

        $this->runAgenda();
        $this->assertInSectionA('Almoço de Sábado', $member);

        $this->travelToLisbon('2027-06-12 10:00');
        $this->runAgenda();

        Notification::assertSentToTimes($member, WeeklyAgendaNotification::class, 2);
        $this->assertInSectionA('Almoço de Sábado', $member);
    }

    #[Test]
    public function manual_run_starts_the_window_at_the_moment_it_runs(): void
    {
        $this->travelToLisbon('2027-06-05 15:00');
        $member = $this->member();
        $this->register($member, $this->event('Reunião das Duas', '2027-06-05 14:00'));
        $this->register($member, $this->event('Reunião das Quatro', '2027-06-05 16:00'));
        $this->register($member, $this->event('Reunião de Sábado Seguinte', '2027-06-12 23:59'));
        $this->register($member, $this->event('Reunião de Domingo Seguinte', '2027-06-13 00:00'));

        $this->runAgenda();

        $mail = $this->mailFor($member);
        $this->assertStringContainsString('Reunião das Quatro', $this->sectionA($mail));
        $this->assertStringContainsString('Reunião de Sábado Seguinte', $this->sectionA($mail));
        $this->assertStringNotContainsString('Reunião das Duas', $mail);
        $this->assertStringNotContainsString('Reunião de Domingo Seguinte', $mail);
    }

    #[Test]
    public function section_a_includes_registered_series_sessions_and_one_off_events(): void
    {
        $member = $this->member();
        $series = $this->series();
        $this->register($member, $this->event('Grupo de Oração Semanal', '2027-06-08 19:00', ['series_id' => $series->id]));
        $this->register($member, $this->event('Jantar Pontual', '2027-06-09 20:00'));

        $this->runAgenda();

        $sectionA = $this->sectionA($this->mailFor($member));
        $this->assertStringContainsString('Grupo de Oração Semanal', $sectionA);
        $this->assertStringContainsString('Jantar Pontual', $sectionA);
    }

    #[Test]
    public function section_a_ignores_events_the_member_is_not_registered_in(): void
    {
        $member = $this->member();
        $other = $this->member();
        $this->register($other, $this->event('Evento do Outro', '2027-06-08 19:00', ['series_id' => $this->series()->id]));
        $this->register($member, $this->event('Evento Meu', '2027-06-09 19:00'));

        $this->runAgenda();

        $this->assertStringNotContainsString('Evento do Outro', $this->mailFor($member));
    }

    #[Test]
    public function section_b_lists_open_one_off_events_the_member_is_not_registered_in_at_any_future_date(): void
    {
        $member = $this->member();
        $this->event('Formação em Setembro', '2027-09-15 18:00');

        $this->runAgenda();

        $this->assertStringContainsString('Formação em Setembro', $this->sectionB($this->mailFor($member)));
    }

    #[Test]
    public function section_b_excludes_series_full_closed_and_registered_events(): void
    {
        $member = $this->member();
        $this->event('Aberto Valido', '2027-07-01 18:00');
        $this->event('Sessao de Serie', '2027-07-02 18:00', ['series_id' => $this->series()->id]);
        $full = $this->event('Evento Esgotado', '2027-07-03 18:00', ['max_attendees' => 1]);
        $this->register($this->member(), $full);
        $this->event('Prazo Fechado', '2027-07-04 18:00', ['registration_deadline' => '2027-06-04 23:59:00']);
        $this->register($member, $this->event('Ja Inscrito Longe', '2027-07-05 18:00'));

        $this->runAgenda();

        $mail = $this->mailFor($member);
        $this->assertStringContainsString('Aberto Valido', $this->sectionB($mail));
        $this->assertStringNotContainsString('Sessao de Serie', $mail);
        $this->assertStringNotContainsString('Evento Esgotado', $mail);
        $this->assertStringNotContainsString('Prazo Fechado', $mail);
        $this->assertStringNotContainsString('Ja Inscrito Longe', $mail);
    }

    #[Test]
    public function registered_event_inside_the_window_shows_only_in_section_a(): void
    {
        $member = $this->member();
        $this->register($member, $this->event('Pequeno-almoço Inscrito', '2027-06-09 08:30'));
        $this->event('Outro Aberto', '2027-07-01 18:00');

        $this->runAgenda();

        $mail = $this->mailFor($member);
        $this->assertStringContainsString('Pequeno-almoço Inscrito', $this->sectionA($mail));
        $this->assertStringNotContainsString('Pequeno-almoço Inscrito', $this->sectionB($mail));
    }

    #[Test]
    public function section_b_shows_at_most_the_ten_nearest_events_by_date(): void
    {
        $member = $this->member();
        foreach ([7, 3, 11, 1, 9, 5, 2, 10, 4, 8, 6] as $n) {
            $this->event(sprintf('Aberto B%02d', $n), sprintf('2027-07-%02d 18:00', $n + 1));
        }

        $this->runAgenda();

        $sectionB = $this->sectionB($this->mailFor($member));
        $this->assertStringNotContainsString('Aberto B11', $sectionB);
        $positions = [];
        for ($n = 1; $n <= 10; $n++) {
            $name = sprintf('Aberto B%02d', $n);
            $this->assertStringContainsString($name, $sectionB);
            $positions[] = mb_strpos($sectionB, $name);
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Os eventos da secção B devem vir por data.');
    }

    #[Test]
    public function nothing_to_show_sends_nothing_and_does_not_mark(): void
    {
        $member = $this->member();
        $this->register($member, $this->event('Evento Distante Inscrito', '2027-08-01 18:00'));
        $this->event('Evento Fechado', '2027-07-01 18:00', ['registration_deadline' => '2027-06-01 23:59:00']);

        $this->runAgenda();

        Notification::assertNothingSent();
        $this->assertNull($this->sentAt($member));
    }

    #[Test]
    public function sending_marks_weekly_agenda_sent_at(): void
    {
        $member = $this->member();
        $this->event('Aberto Valido', '2027-07-01 18:00');

        $this->runAgenda();

        Notification::assertSentTo($member, WeeklyAgendaNotification::class);
        $this->assertNotNull($this->sentAt($member));
        $this->assertTrue(Carbon::parse($this->sentAt($member))->equalTo(now()));
    }

    #[Test]
    public function running_twice_in_the_same_week_sends_one_agenda(): void
    {
        $member = $this->member();
        $this->event('Aberto Valido', '2027-07-01 18:00');

        $this->runAgenda();
        $this->runAgenda();

        Notification::assertSentToTimes($member, WeeklyAgendaNotification::class, 1);
    }

    #[Test]
    public function member_who_got_an_agenda_five_days_ago_gets_none(): void
    {
        $member = $this->member();
        $member->forceFill(['weekly_agenda_sent_at' => now()->subDays(5)])->save();
        $this->event('Aberto Valido', '2027-07-01 18:00');

        $this->runAgenda();

        Notification::assertNotSentTo($member, WeeklyAgendaNotification::class);
    }

    #[Test]
    public function next_week_the_agenda_is_sent_again(): void
    {
        $member = $this->member();
        $this->event('Aberto Valido', '2027-07-01 18:00');

        $this->runAgenda();
        $this->travelToLisbon('2027-06-12 10:00');
        $this->runAgenda();

        Notification::assertSentToTimes($member, WeeklyAgendaNotification::class, 2);
    }

    #[Test]
    public function only_active_accounts_with_profile_and_preference_receive_the_agenda(): void
    {
        $this->event('Aberto Valido', '2027-07-01 18:00');
        $member = $this->member();
        $admin = $this->member(User::factory()->admin()->create());
        $newEventsOff = $this->member(User::factory()->create(['notify_new_events' => false]));
        $agendaOff = $this->member(User::factory()->create(['notify_weekly_agenda' => false]));
        $pending = $this->member(User::factory()->pending()->create());
        $approved = $this->member(User::factory()->approved()->create());
        $inactive = $this->member(User::factory()->inactive(InactiveReason::Unpaid)->create());
        $technicalAdmin = User::factory()->technicalAdmin()->create();
        $withoutProfile = User::factory()->create();
        $anonymized = $this->member();
        $anonymized->anonymizeAndDelete();

        $this->runAgenda();

        foreach ([$member, $admin, $newEventsOff] as $recipient) {
            Notification::assertSentTo($recipient, WeeklyAgendaNotification::class);
        }
        foreach ([$agendaOff, $pending, $approved, $inactive, $technicalAdmin, $withoutProfile] as $excluded) {
            Notification::assertNotSentTo($excluded, WeeklyAgendaNotification::class);
            $this->assertNull($this->sentAt($excluded));
        }
        Notification::assertNotSentTo(User::withTrashed()->findOrFail($anonymized->id), WeeklyAgendaNotification::class);
    }

    #[Test]
    public function registered_online_event_with_closed_registrations_shows_the_meeting_link(): void
    {
        $member = $this->member();
        $this->register($member, $this->event('Webinar Fechado', '2027-06-08 20:00', [
            ...$this->onlineAttributes('https://meet.example.com/fechado-777'),
            'registration_deadline' => '2027-06-04 23:59:00',
        ]));

        $this->runAgenda();

        $this->assertStringContainsString('https://meet.example.com/fechado-777', $this->sectionA($this->mailFor($member)));
    }

    #[Test]
    public function registered_online_event_with_open_registrations_hides_the_link_and_explains_why(): void
    {
        $member = $this->member();
        $this->register($member, $this->event('Webinar Aberto', '2027-06-10 20:00', [
            ...$this->onlineAttributes('https://meet.example.com/aberto-888'),
            'registration_deadline' => '2027-06-09 23:59:00',
        ]));

        $this->runAgenda();

        $mail = $this->mailFor($member);
        $this->assertStringNotContainsString('meet.example.com/aberto-888', $mail);
        $this->assertStringContainsString(self::LINK_PENDING_TEXT, $this->sectionA($mail));
    }

    #[Test]
    public function meeting_link_is_hidden_if_the_registration_is_gone_when_the_email_is_composed(): void
    {
        $member = $this->member();
        $registration = $this->register($member, $this->event('Webinar Cancelado', '2027-06-08 20:00', [
            ...$this->onlineAttributes('https://meet.example.com/saiu-999'),
            'registration_deadline' => '2027-06-04 23:59:00',
        ]));
        $this->event('Aberto Valido', '2027-07-01 18:00');

        $this->runAgenda();
        $registration->delete();

        $this->assertStringNotContainsString('meet.example.com/saiu-999', $this->mailFor($member));
    }

    #[Test]
    public function section_b_never_shows_the_meeting_link(): void
    {
        $member = $this->member();
        $this->event('Webinar Publico', '2027-07-01 20:00', $this->onlineAttributes('https://meet.example.com/publico-555'));

        $this->runAgenda();

        $mail = $this->mailFor($member);
        $this->assertStringContainsString('Webinar Publico', $this->sectionB($mail));
        $this->assertStringNotContainsString('meet.example.com/publico-555', $mail);
    }

    #[Test]
    public function agenda_greets_the_member_and_links_events_and_preferences(): void
    {
        $member = $this->member(name: 'Rui Carvalho');
        $registered = $this->event('Jantar Inscrito', '2027-06-09 20:00');
        $this->register($member, $registered);
        $open = $this->event('Aberto Valido', '2027-07-01 18:00');

        $this->runAgenda();

        $mail = $this->mailFor($member);
        $this->assertStringContainsString('Rui Carvalho', $mail);
        $this->assertCalendarIconLink($this->sectionA($mail), $registered->id, self::CALENDAR_ICON);
        $this->assertCalendarIconLink($this->sectionB($mail), $open->id, self::CALENDAR_ICON);
        $this->assertStringContainsString(self::FRONTEND.'/perfil/conta', $mail);
    }

    #[Test]
    public function command_is_scheduled_on_saturdays_at_ten_lisbon_time_without_overlapping(): void
    {
        $this->app->make(Kernel::class)->bootstrap();

        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event) => str_contains((string) $event->command, self::COMMAND));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('0 10 * * 6', $event->expression);
        $this->assertSame('Europe/Lisbon', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
    }

    #[Test]
    public function queued_agenda_is_not_sent_when_the_member_was_deactivated_or_turned_the_agenda_off_meanwhile(): void
    {
        $blocked = $this->member();
        $optedOut = $this->member();
        $stillActive = $this->member();
        $notification = new WeeklyAgendaNotification(new EloquentCollection, new EloquentCollection);

        $blocked->deactivate(InactiveReason::Blocked->value);
        $optedOut->forceFill(['notify_weekly_agenda' => false])->save();

        $this->assertFalse($notification->shouldSend($blocked->fresh(), 'mail'));
        $this->assertFalse($notification->shouldSend($optedOut->fresh(), 'mail'));
        $this->assertTrue($notification->shouldSend($stillActive->fresh(), 'mail'));
    }

    #[Test]
    public function each_event_in_both_sections_has_one_calendar_link(): void
    {
        $member = $this->member();
        $registered = $this->event('Jantar Inscrito', '2027-06-09 20:00');
        $this->register($member, $registered);
        $open = $this->event('Aberto Valido', '2027-07-01 18:00');

        $this->runAgenda();

        $mail = $this->mailFor($member);
        $this->assertSame(1, substr_count($mail, 'href="'.e($this->calendarIcsUrl($registered->id)).'"'));
        $this->assertSame(1, substr_count($mail, 'href="'.e($this->calendarIcsUrl($open->id)).'"'));
        $this->assertStringContainsString(e($this->calendarIcsUrl($registered->id)), $this->sectionA($mail));
        $this->assertStringContainsString(e($this->calendarIcsUrl($open->id)), $this->sectionB($mail));
    }

    #[Test]
    public function calendar_icon_link_sits_at_the_end_of_the_event_details_line_below_the_title(): void
    {
        $member = $this->member();
        $event = $this->event('Jantar Inscrito', '2027-06-09 20:00', [
            'location_id' => Location::query()->where('name', 'Porto')->firstOrFail()->id,
        ]);
        $this->register($member, $event);

        $this->runAgenda();

        $html = $this->sectionA($this->mailFor($member));
        $anchor = $this->calendarAnchor($html, $event->id);
        $this->assertNotNull($anchor);

        $paragraphStart = strrpos(substr($html, 0, $anchor['offset']), '<p');
        $paragraph = substr($html, $paragraphStart, $anchor['offset'] - $paragraphStart);
        $lastLine = preg_split('/<br\s*\/?>/i', $paragraph);
        $detailsLine = end($lastLine);
        $this->assertStringContainsString('09/06/2027', $detailsLine);
        $this->assertStringContainsString('20:00', $detailsLine);
        $this->assertStringContainsString('Porto', $detailsLine);
        $this->assertStringNotContainsString('Jantar Inscrito', $detailsLine);

        $titlePosition = strpos($html, '>Jantar Inscrito</strong>');
        $this->assertNotFalse($titlePosition);
        $this->assertLessThan($anchor['offset'], $titlePosition);
        $betweenTitleAndDate = substr($html, $titlePosition, strpos($html, '09/06/2027', $titlePosition) - $titlePosition);
        $this->assertMatchesRegularExpression('/<br\s*\/?>|<\/p>/i', $betweenTitleAndDate);
    }

    #[Test]
    public function agenda_has_no_large_per_event_calendar_button(): void
    {
        $member = $this->member();
        $this->register($member, $this->event('Jantar Inscrito', '2027-06-09 20:00'));
        $this->event('Aberto Valido', '2027-07-01 18:00');

        $this->runAgenda();

        $mail = $this->mailFor($member);
        $this->assertSame(2, preg_match_all('/<a\s[^>]*class="[^"]*button[^"]*"/i', $mail));
        $this->assertNotNull($this->anchorTagFor($mail, self::FRONTEND.'/perfil/inscricoes'));
        $this->assertNotNull($this->anchorTagFor($mail, self::FRONTEND.'/eventos'));
        $this->assertStringNotContainsString('>Adicionar ao calendário<', $mail);
    }

    #[Test]
    public function without_calendar_icon_url_the_agenda_uses_a_small_calendario_text_link(): void
    {
        config(['mail.calendar_icon_url' => null]);
        $member = $this->member();
        $event = $this->event('Jantar Inscrito', '2027-06-09 20:00');
        $this->register($member, $event);

        $this->runAgenda();

        $anchor = $this->calendarAnchor($this->mailFor($member), $event->id);
        $this->assertNotNull($anchor);
        $this->assertSame('Calendário', trim(strip_tags($anchor['inner'])));
        $this->assertStringNotContainsString('<img', $anchor['inner']);
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*button/i', $anchor['tag']);
    }

    #[Test]
    public function text_version_of_the_agenda_keeps_the_calendar_url_in_full(): void
    {
        $member = $this->member();
        $event = $this->event('Jantar Inscrito', '2027-06-09 20:00');
        $this->register($member, $event);

        $this->runAgenda();

        $message = Notification::sent($member, WeeklyAgendaNotification::class)->last()->toMail($member);
        $text = (string) app(Markdown::class)->renderText($message->markdown, $message->data());

        $this->assertStringContainsString('Adicionar ao calendário: '.$this->calendarIcsUrl($event->id), $text);
    }

    #[Test]
    public function calendar_icon_file_is_a_48_by_48_png(): void
    {
        $path = resource_path('images/calendar-icon.png');

        $this->assertFileExists($path);
        [$width, $height, $type] = getimagesize($path);
        $this->assertSame([48, 48, IMAGETYPE_PNG], [$width, $height, $type]);
    }

    #[Test]
    public function agenda_lines_have_no_per_event_page_link_but_keep_the_agenda_button(): void
    {
        $member = $this->member();
        $registered = $this->event('Jantar Inscrito', '2027-06-09 20:00');
        $this->register($member, $registered);
        $open = $this->event('Aberto Valido', '2027-07-01 18:00');

        $this->runAgenda();

        $mail = $this->mailFor($member);
        $this->assertStringNotContainsString('Ver o evento', $mail);
        $this->assertStringNotContainsString(self::FRONTEND.'/eventos/'.$registered->id, $mail);
        $this->assertStringNotContainsString(self::FRONTEND.'/eventos/'.$open->id, $mail);
        $this->assertStringContainsString('Ver a agenda', $mail);
        $this->assertNotNull($this->anchorTagFor($mail, self::FRONTEND.'/eventos'));
    }

    #[Test]
    public function agenda_links_the_venue_to_google_maps_with_the_district_and_hides_the_address(): void
    {
        $member = $this->member();
        $porto = Location::query()->where('name', 'Porto')->firstOrFail()->id;
        $this->register($member, $this->event('Jantar no Salao', '2027-06-09 20:00', [
            'location_id' => $porto,
            'venue_name' => 'Salão de eventos',
            'address' => 'Rua de Santa Catarina 100',
            'place_id' => 'ChIJ_abc-123',
            'latitude' => null,
            'longitude' => null,
        ]));
        $this->event('Aberto sem Espaco', '2027-07-01 18:00', [
            'location_id' => $porto,
            'venue_name' => null,
            'address' => 'Avenida dos Aliados 1',
            'latitude' => null,
            'longitude' => null,
        ]);
        $maps = 'https://www.google.com/maps/search/?api=1&query=';

        $this->runAgenda();

        $mail = $this->mailFor($member);
        $this->assertNotNull($this->anchorTagFor(
            $this->sectionA($mail),
            $maps.rawurlencode('Rua de Santa Catarina 100').'&query_place_id=ChIJ_abc-123'
        ));
        $this->assertStringContainsString('Salão de eventos</a>, Porto', $this->sectionA($mail));
        $this->assertNotNull($this->anchorTagFor($this->sectionB($mail), $maps.rawurlencode('Avenida dos Aliados 1')));
        $this->assertStringContainsString('Ver no mapa</a>, Porto', $this->sectionB($mail));
        $this->assertStringNotContainsString('Rua de Santa Catarina 100', $mail);
        $this->assertStringNotContainsString('Avenida dos Aliados 1', $mail);
    }

    #[Test]
    public function online_events_in_the_agenda_have_no_map_link(): void
    {
        $member = $this->member();
        $this->register($member, $this->event('Webinar Inscrito', '2027-06-09 20:00', $this->onlineAttributes('https://meet.example.com/a-1')));
        $this->event('Webinar Aberto', '2027-07-01 20:00', $this->onlineAttributes('https://meet.example.com/b-2'));

        $this->runAgenda();

        $this->assertStringNotContainsString('google.com/maps', $this->mailFor($member));
    }

    #[Test]
    public function agenda_with_week_events_ends_that_section_with_my_registrations_button(): void
    {
        $member = $this->member();
        $this->register($member, $this->event('Jantar Inscrito', '2027-06-09 20:00'));
        $this->event('Aberto Valido', '2027-07-01 18:00');

        $this->runAgenda();

        $sectionA = $this->sectionA($this->mailFor($member));
        $tag = $this->anchorTagFor($sectionA, self::FRONTEND.'/perfil/inscricoes');
        $this->assertNotNull($tag);
        $start = strpos($sectionA, $tag);
        $this->assertStringContainsString(
            'Ver as minhas inscrições',
            substr($sectionA, $start, strpos($sectionA, '</a>', $start) - $start)
        );
        $this->assertGreaterThan(strrpos($sectionA, 'Jantar Inscrito'), $start);
    }

    #[Test]
    public function agenda_with_only_open_events_has_no_my_registrations_button(): void
    {
        $member = $this->member();
        $this->event('Aberto Valido', '2027-07-01 18:00');

        $this->runAgenda();

        $mail = $this->mailFor($member);
        $this->assertStringNotContainsString('Ver as minhas inscrições', $mail);
        $this->assertStringNotContainsString(self::FRONTEND.'/perfil/inscricoes', $mail);
        $this->assertStringContainsString('Ver a agenda', $mail);
    }

    #[Test]
    public function agenda_with_only_week_events_has_no_agenda_button(): void
    {
        $member = $this->member();
        $this->register($member, $this->event('Jantar Inscrito', '2027-06-09 20:00'));

        $this->runAgenda();

        $mail = $this->mailFor($member);
        $this->assertStringNotContainsString('Inscrições abertas', $mail);
        $this->assertStringNotContainsString('Ver a agenda', $mail);
        $this->assertNull($this->anchorTagFor($mail, self::FRONTEND.'/eventos'));
    }

    #[Test]
    public function markdown_in_the_event_name_does_not_create_a_link_in_the_agenda(): void
    {
        $member = $this->member();
        $this->register($member, $this->event('Jantar [Confirme aqui](https://phish.pt)', '2027-06-09 20:00'));
        $this->event('Aberto [Clique](https://phish.pt)', '2027-07-01 18:00');

        $this->runAgenda();

        $mail = $this->mailFor($member);
        $this->assertStringNotContainsString('href="https://phish.pt', $mail);
        $this->assertStringContainsString('>Jantar [Confirme aqui](https://phish.pt)</strong>', $this->sectionA($mail));
        $this->assertStringContainsString('>Aberto [Clique](https://phish.pt)</strong>', $this->sectionB($mail));
    }

    #[Test]
    public function angle_brackets_in_the_event_name_are_shown_as_text_in_the_agenda(): void
    {
        $member = $this->member();
        $this->register($member, $this->event('Jantar <b>VIP</b>', '2027-06-09 20:00'));

        $this->runAgenda();

        $mail = $this->mailFor($member);
        $this->assertStringNotContainsString('<b>', $mail);
        $this->assertStringContainsString('Jantar &lt;b&gt;VIP&lt;/b&gt;', $mail);
        $this->assertStringNotContainsString('\&lt;', $mail);
    }
}
