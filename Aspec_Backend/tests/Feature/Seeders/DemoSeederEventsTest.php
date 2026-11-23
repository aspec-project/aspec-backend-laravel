<?php

namespace Tests\Feature\Seeders;

use App\Jobs\AnnounceNewEventJob;
use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\EventSeries;
use App\Models\Location;
use App\Models\PrayerCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsDemoData;
use Tests\TestCase;

/**
 * DemoSeeder: eventos únicos (futuros, online e passados), as duas séries, inscrições e cartões de oração.
 */
class DemoSeederEventsTest extends TestCase
{
    use RefreshDatabase, SeedsDemoData;

    /** Série semanal de formação em Leiria, mantida da versão anterior da demonstração. */
    private const LEIRIA_SERIES = 'Ciclo de Formação: Liderar Pequenas Equipas';

    /**
     * Eventos únicos (nome, distrito) da versão anterior da demonstração, como ficaram nas bases de
     * dados já semeadas (incluindo o TiDB do deploy).
     *
     * @var list<array{string, string}>
     */
    private const PREVIOUS_VERSION_EVENTS = [
        ['Pequeno-almoço de Negócios ASPEC', 'Lisboa'],
        ['Pequeno-almoço de Negócios ASPEC', 'Porto'],
        ['Pequeno-almoço de Negócios ASPEC', 'Braga'],
        ['Pequeno-almoço de Negócios ASPEC', 'Leiria'],
        ['Jantar de Networking ASPEC', 'Lisboa'],
        ['Jantar de Networking ASPEC', 'Coimbra'],
        ['Jantar de Networking ASPEC', 'Faro'],
        ['Jantar de Networking ASPEC', 'Aveiro'],
        ['Almoço de Negócios ASPEC', 'Viseu'],
        ['Formação: Finanças para Pequenas Empresas', 'Aveiro'],
        ['Formação: Marketing Digital para Negócios Locais', 'Leiria'],
        ['Formação: Liderança com Valores', 'Setúbal'],
        ['Workshop: Apresente o seu Negócio em Dois Minutos', 'Porto'],
        ['Webinar: Faturação Eletrónica sem Complicações', 'Lisboa'],
        ['Workshop Online: Vender Mais nas Redes Sociais', 'Lisboa'],
        ['Formação Online: RGPD para Pequenas Empresas', 'Lisboa'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDemoSeeder();
    }

    /**
     * @return EloquentCollection<int, EventInstance>
     */
    private function futureOneOffEvents(): EloquentCollection
    {
        $today = $this->lisbonToday();

        return $this->oneOffEvents()->filter(fn (EventInstance $event) => $event->event_date->gte($today))->values();
    }

    /**
     * @return EloquentCollection<int, EventInstance>
     */
    private function pastOneOffEvents(): EloquentCollection
    {
        $today = $this->lisbonToday();

        return $this->oneOffEvents()->filter(fn (EventInstance $event) => $event->event_date->lt($today))->values();
    }

    /**
     * Sessões da série de Leiria, por ordem de data.
     *
     * @return Collection<int, EventInstance>
     */
    private function leiriaSessions(): Collection
    {
        return EventInstance::where('name', self::LEIRIA_SERIES)
            ->whereHas('location', fn ($query) => $query->where('name', 'Leiria'))
            ->orderBy('event_date')
            ->get()
            ->values();
    }

    private function leiriaSeries(): EventSeries
    {
        $ids = $this->leiriaSessions()->pluck('series_id')->unique();
        $this->assertCount(1, $ids, 'Série de Leiria');

        return EventSeries::findOrFail($ids->first());
    }

    /**
     * A série online semanal (a outra série da demonstração).
     */
    private function onlineSeries(): EventSeries
    {
        $series = EventSeries::whereHas('eventInstances', fn ($query) => $query->where('is_online', true))->get();
        $this->assertCount(1, $series, 'Séries online');

        return $series->first();
    }

    /**
     * @return list<string>
     */
    private function registrantEmails(EventInstance $event): array
    {
        return User::whereHas('eventRegistrations', fn ($query) => $query->where('event_instance_id', $event->id))
            ->orderBy('email')
            ->pluck('email')
            ->all();
    }

    private function isRegistered(EventInstance $event, User $user): bool
    {
        return $event->registrations()->where('user_id', $user->id)->exists();
    }

    /**
     * Ocupação crítica, a mesma regra do aviso vermelho do frontend: até 10 lugares, metade ou mais
     * ocupados; acima disso, 5 ou menos lugares livres. Um evento esgotado não conta.
     */
    private function isCritical(EventInstance $event): bool
    {
        $free = $event->max_attendees - $event->registrations_count;

        if ($free <= 0) {
            return false;
        }

        return $event->max_attendees <= 10
            ? $event->registrations_count * 2 >= $event->max_attendees
            : $free <= 5;
    }

    #[Test]
    public function creates_about_twenty_future_and_twenty_four_past_one_off_events(): void
    {
        $this->runDemoSeeder();

        $future = $this->futureOneOffEvents();
        $past = $this->pastOneOffEvents();
        $today = $this->lisbonToday();

        $this->assertGreaterThanOrEqual(17, $future->count());
        $this->assertLessThanOrEqual(23, $future->count());
        $this->assertGreaterThanOrEqual(20, $past->count());
        $this->assertLessThanOrEqual(28, $past->count());

        foreach ($future as $event) {
            $this->assertTrue($event->event_date->lte($today->copy()->addDays(56)), $event->name);
        }

        foreach ($past as $event) {
            $this->assertTrue($event->event_date->lte($today->copy()->subDays(7)), $event->name);
            $this->assertTrue($event->event_date->gte($today->copy()->subDays(180)), $event->name);
        }
    }

    /**
     * O painel conta os eventos desta semana e a agenda tem de ter eventos todas as semanas
     * (semana de segunda a domingo, em Lisboa), durante as próximas 8 semanas.
     */
    #[Test]
    public function there_are_events_this_week_next_week_and_every_week_for_eight_weeks(): void
    {
        $this->runDemoSeeder();

        $this->assertEventsInEachOfTheNextEightWeeks();
    }

    /**
     * Mesma regra quando o seeder corre a um domingo à noite (fim da semana).
     */
    #[Test]
    public function there_are_events_this_week_even_when_seeded_on_a_sunday_evening(): void
    {
        $this->travelTo(now($this->eventTimezone())->next('Sunday')->setTime(21, 30));

        $this->runDemoSeeder();

        $this->assertEventsInEachOfTheNextEightWeeks();
    }

    private function assertEventsInEachOfTheNextEightWeeks(): void
    {
        $dates = EventInstance::pluck('event_date');
        $weekStart = $this->lisbonWeekStart();

        foreach (range(0, 7) as $week) {
            $from = $weekStart->copy()->addWeeks($week);
            $to = $from->copy()->addDays(6);

            $this->assertTrue(
                $dates->contains(fn ($date) => $date->between($from, $to)),
                "Sem eventos na semana de {$from->toDateString()}"
            );
        }
    }

    #[Test]
    public function events_use_the_five_categories_and_at_least_twelve_districts(): void
    {
        $this->runDemoSeeder();

        $this->assertSame(5, EventCategory::count());
        $this->assertSame(5, EventInstance::distinct()->count('category_id'));

        foreach (['Pequeno-almoço', 'Almoço', 'Jantar', 'Workshop', 'Formação'] as $name) {
            $this->assertTrue(EventInstance::whereHas('category', fn ($query) => $query->where('name', $name))->exists(), $name);
        }

        $this->assertGreaterThanOrEqual(12, $this->futureOneOffEvents()->pluck('location_id')->unique()->count());
    }

    /**
     * Requisito 6: o mesmo formato repete-se em várias cidades (pequeno-almoço em 5, jantar em 4,
     * almoço em 3).
     */
    #[Test]
    public function event_formats_are_replicated_in_several_districts(): void
    {
        $this->runDemoSeeder();

        $events = $this->oneOffEvents();

        foreach (['Pequeno-almoço' => 5, 'Jantar' => 4, 'Almoço' => 3] as $category => $districts) {
            $widest = $events->filter(fn (EventInstance $event) => $event->category->name === $category)
                ->groupBy('name')
                ->map(fn ($sameFormat) => $sameFormat->pluck('location_id')->unique()->count())
                ->max();

            $this->assertGreaterThanOrEqual($districts, $widest ?? 0, $category);
        }
    }

    /**
     * Os eventos únicos são procurados pelo nome e distrito: cada par tem de ser único.
     */
    #[Test]
    public function one_off_events_are_unique_by_name_and_district(): void
    {
        $this->runDemoSeeder();

        $keys = $this->oneOffEvents()->map(fn (EventInstance $event) => $event->name.'|'.$event->location_id);

        $this->assertSame($keys->count(), $keys->unique()->count(), 'Repetidos: '.$keys->duplicates()->implode(', '));
    }

    #[Test]
    public function every_event_has_a_deadline_before_its_date_and_a_plausible_capacity(): void
    {
        $this->runDemoSeeder();

        foreach (EventInstance::all() as $event) {
            $this->assertTrue($event->registration_deadline->lessThan($event->event_date), $event->name);
            $this->assertSame('23:59', $event->registration_deadline->format('H:i'), $event->name);
            $this->assertGreaterThanOrEqual(10, $event->max_attendees, $event->name);
            $this->assertLessThanOrEqual(100, $event->max_attendees, $event->name);
            $this->assertNotEmpty($event->description, $event->name);
        }
    }

    /**
     * Locais sem marcas (nem de hotel): nomes, descrições, locais e notas de todos os eventos.
     */
    #[Test]
    public function events_mention_no_real_brand(): void
    {
        $this->runDemoSeeder();

        foreach (EventInstance::all() as $event) {
            foreach (['name', 'description', 'venue_name', 'organizer_notes'] as $field) {
                $this->assertNoForbiddenBrand($event->{$field}, "{$event->name}: {$field}");
            }
        }
    }

    /**
     * O mapa usa só o place_id da Google (a API nunca grava place_id e coordenadas juntos); o
     * mesmo local tem sempre a mesma morada; os online não têm local.
     */
    #[Test]
    public function in_person_events_have_a_place_id_without_coordinates_and_online_events_have_none(): void
    {
        $this->runDemoSeeder();

        $this->assertSame(0, EventInstance::whereNotNull('latitude')->orWhereNotNull('longitude')->count());

        $inPerson = EventInstance::where('is_online', false)->get();
        $this->assertNotEmpty($inPerson);

        foreach ($inPerson as $event) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,512}$/D', (string) $event->place_id, $event->name);
            $this->assertNotEmpty($event->address, $event->name);
        }

        foreach ($inPerson->groupBy('place_id') as $placeId => $samePlace) {
            $this->assertCount(1, $samePlace->pluck('address')->unique(), "place_id {$placeId} com moradas diferentes");
        }

        foreach (EventInstance::where('is_online', true)->get() as $event) {
            $this->assertNull($event->place_id, $event->name);
            $this->assertNull($event->address, $event->name);
            $this->assertNull($event->venue_name, $event->name);
            $this->assertStringStartsWith('https://', (string) $event->online_url, $event->name);
        }
    }

    #[Test]
    public function public_event_api_returns_the_place_id_of_an_in_person_event(): void
    {
        $this->runDemoSeeder();

        $event = $this->futureOneOffEvents()->firstWhere('is_online', false);
        $this->assertNotNull($event);

        $this->getJson("/api/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.place_id', $event->place_id);
    }

    /**
     * Numa base de dados semeada com coordenadas antigas, voltar a correr o seeder limpa-as.
     */
    #[Test]
    public function running_again_clears_coordinates_saved_before(): void
    {
        $this->runDemoSeeder();

        $event = $this->futureOneOffEvents()->firstWhere('is_online', false);
        $placeId = $event->place_id;
        $event->forceFill(['latitude' => 38.7676, 'longitude' => -9.0973])->save();

        $this->runDemoSeeder();

        $event->refresh();
        $this->assertNull($event->latitude);
        $this->assertNull($event->longitude);
        $this->assertSame($placeId, $event->place_id);
    }

    /**
     * Online: 5 futuros e 3 passados (fora das séries).
     */
    #[Test]
    public function creates_five_future_and_three_past_online_events(): void
    {
        $this->runDemoSeeder();

        $this->assertSame(5, $this->futureOneOffEvents()->where('is_online', true)->count());
        $this->assertSame(3, $this->pastOneOffEvents()->where('is_online', true)->count());
    }

    /**
     * Os casos do link online, vistos pela API pública como o frontend: antes do prazo ninguém o
     * vê; depois do prazo só os inscritos (num evento o demo.membro está inscrito, noutro não).
     */
    #[Test]
    public function online_events_show_the_link_only_to_registered_members_after_the_deadline(): void
    {
        $this->runDemoSeeder();

        $demoMember = $this->demoUser('demo.membro@aspec.test');
        $online = $this->futureOneOffEvents()->where('is_online', true);

        $open = $online->first(fn (EventInstance $event) => ! $event->registrationDeadlinePassed() && ! $this->isRegistered($event, $demoMember));
        $closedWithDemoMember = $online->first(fn (EventInstance $event) => $event->registrationDeadlinePassed() && $this->isRegistered($event, $demoMember));
        $closedWithoutDemoMember = $online->first(fn (EventInstance $event) => $event->registrationDeadlinePassed() && ! $this->isRegistered($event, $demoMember) && $event->registrations_count > 0);

        $this->assertNotNull($open, 'Online com inscrições abertas sem o demo.membro');
        $this->assertNotNull($closedWithDemoMember, 'Online com prazo terminado e o demo.membro inscrito');
        $this->assertNotNull($closedWithoutDemoMember, 'Online com prazo terminado sem o demo.membro');

        $this->actingAs($demoMember, 'sanctum')->getJson("/api/events/{$open->id}")
            ->assertOk()
            ->assertJsonPath('data.registration_open', true)
            ->assertJsonPath('data.is_registered', false)
            ->assertJsonMissingPath('data.online_url');

        $this->actingAs($demoMember, 'sanctum')->getJson("/api/events/{$closedWithDemoMember->id}")
            ->assertOk()
            ->assertJsonPath('data.registration_open', false)
            ->assertJsonPath('data.is_registered', true)
            ->assertJsonPath('data.online_url', $closedWithDemoMember->online_url);

        $this->actingAs($demoMember, 'sanctum')->getJson("/api/events/{$closedWithoutDemoMember->id}")
            ->assertOk()
            ->assertJsonPath('data.is_registered', false)
            ->assertJsonMissingPath('data.online_url');

        $registered = User::whereHas('eventRegistrations', fn ($query) => $query->where('event_instance_id', $closedWithoutDemoMember->id))->firstOrFail();

        $this->actingAs($registered, 'sanctum')->getJson("/api/events/{$closedWithoutDemoMember->id}")
            ->assertOk()
            ->assertJsonPath('data.is_registered', true)
            ->assertJsonPath('data.online_url', $closedWithoutDemoMember->online_url);
    }

    /**
     * Cerca de 700 inscrições, só de contas Active e nunca acima da lotação.
     */
    #[Test]
    public function creates_about_seven_hundred_registrations_only_from_active_accounts_within_capacity(): void
    {
        $this->runDemoSeeder();

        $this->assertGreaterThanOrEqual(600, EventRegistration::count());
        $this->assertLessThanOrEqual(800, EventRegistration::count());

        $notActive = EventRegistration::whereHas('user.accountStatus', fn ($query) => $query->where('name', '!=', 'Active'))->count();
        $this->assertSame(0, $notActive);

        foreach (EventInstance::withCount('registrations')->get() as $event) {
            $this->assertLessThanOrEqual($event->max_attendees, $event->registrations_count, $event->name);
        }
    }

    /**
     * Datas das inscrições espalhadas entre a publicação do evento e o prazo, nunca no futuro.
     */
    #[Test]
    public function registration_dates_fall_between_the_announcement_and_the_deadline(): void
    {
        $this->runDemoSeeder();

        $registrations = EventRegistration::with('eventInstance')->get();

        foreach ($registrations as $registration) {
            $event = $registration->eventInstance;
            $label = "{$event->name} ({$event->event_date->toDateString()})";

            $this->assertTrue($registration->created_at->lessThanOrEqualTo(now()), $label);
            $this->assertTrue($registration->created_at->lessThanOrEqualTo($this->registrationDeadlineAt($event)), $label);

            if ($event->announced_at !== null) {
                $this->assertTrue($registration->created_at->greaterThanOrEqualTo($event->announced_at), $label);
            }
        }

        $this->assertGreaterThanOrEqual(30, $registrations->map(fn ($registration) => $registration->created_at->toDateString())->unique()->count());
    }

    /**
     * Ocupação dos futuros: 1 esgotado, 2 críticos e 2 sem inscrições (os únicos por anunciar).
     */
    #[Test]
    public function future_events_show_every_occupancy_case(): void
    {
        $this->runDemoSeeder();

        $future = $this->futureOneOffEvents();

        $this->assertSame(1, $future->filter(fn (EventInstance $event) => $event->registrations_count === $event->max_attendees)->count());
        $this->assertSame(2, $future->filter(fn (EventInstance $event) => $this->isCritical($event))->count());
        $this->assertGreaterThanOrEqual(4, $future->filter(fn (EventInstance $event) => $event->registrations_count * 2 > $event->max_attendees && $event->registrations_count < $event->max_attendees)->count());

        $empty = $future->filter(fn (EventInstance $event) => $event->registrations_count === 0);
        $this->assertCount(2, $empty);

        foreach ($future as $event) {
            $this->assertSame($event->registrations_count === 0, $event->announced_at === null, $event->name);
        }
    }

    #[Test]
    public function full_future_event_refuses_new_registrations(): void
    {
        $this->runDemoSeeder();

        $full = $this->futureOneOffEvents()->first(fn (EventInstance $event) => $event->registrations_count === $event->max_attendees);
        $this->assertNotNull($full);

        $outsider = User::whereHas('accountStatus', fn ($query) => $query->where('name', 'Active'))
            ->where('email', 'like', 'demo.%@aspec.test')
            ->whereDoesntHave('eventRegistrations', fn ($query) => $query->where('event_instance_id', $full->id))
            ->firstOrFail();

        $this->getJson("/api/events/{$full->id}")
            ->assertOk()
            ->assertJsonPath('data.registrations_count', $full->max_attendees)
            ->assertJsonPath('data.available_spots', 0)
            ->assertJsonPath('data.registration_open', false);

        Sanctum::actingAs($outsider);

        $this->postJson("/api/events/{$full->id}/registrations")->assertConflict();
    }

    /**
     * Passados com ocupação entre 60 % e 100 % (um esgotado), todos anunciados e fora da agenda pública.
     */
    #[Test]
    public function past_events_have_high_occupancy_and_stay_out_of_the_public_agenda(): void
    {
        $this->runDemoSeeder();

        $past = $this->pastOneOffEvents();
        $this->assertNotEmpty($past);

        foreach ($past as $event) {
            $occupancy = $event->registrations_count / $event->max_attendees;

            $this->assertGreaterThanOrEqual(0.6, $occupancy, $event->name);
            $this->assertLessThanOrEqual(1.0, $occupancy, $event->name);
            $this->assertNotNull($event->announced_at, $event->name);
        }

        $this->assertSame(1, $past->filter(fn (EventInstance $event) => $event->registrations_count === $event->max_attendees)->count());

        $agendaIds = collect($this->getJson('/api/events?per_page=100')->assertOk()->json('data.items'))->pluck('id');

        foreach ($past as $event) {
            $this->assertNotContains($event->id, $agendaIds, $event->name);
        }
    }

    /**
     * Cerca de um terço dos eventos únicos tem notas do organizador.
     */
    #[Test]
    public function about_a_third_of_the_one_off_events_have_organizer_notes(): void
    {
        $this->runDemoSeeder();

        $events = $this->oneOffEvents();
        $share = $events->whereNotNull('organizer_notes')->count() / $events->count();

        $this->assertGreaterThanOrEqual(0.2, $share);
        $this->assertLessThanOrEqual(0.5, $share);
    }

    /**
     * O demo.membro tem inscrições em eventos presenciais futuros, em 5 sessões da série de Leiria,
     * num online e em eventos passados, para "As minhas inscrições" mostrar todos os casos.
     */
    #[Test]
    public function demo_member_has_upcoming_online_series_and_past_registrations(): void
    {
        $this->runDemoSeeder();

        $today = $this->lisbonToday()->toDateString();

        $events = collect(
            $this->actingAs($this->demoUser('demo.membro@aspec.test'), 'sanctum')
                ->getJson('/api/me/registrations')
                ->assertOk()
                ->json('data')
        )->pluck('event');

        $this->assertGreaterThanOrEqual(2, $events->filter(fn (array $event) => ! $event['is_online'] && $event['event_date'] >= $today && $event['series_id'] === null)->count());
        $this->assertSame(5, $events->where('name', self::LEIRIA_SERIES)->count());
        $this->assertGreaterThanOrEqual(1, $events->where('is_online', true)->whereNull('series_id')->count());
        $this->assertGreaterThanOrEqual(1, $events->filter(fn (array $event) => $event['event_date'] < $today)->count());
    }

    #[Test]
    public function seeding_never_announces_events_nor_sends_notifications(): void
    {
        Queue::fake([AnnounceNewEventJob::class]);
        Notification::fake();

        $this->runDemoSeeder();

        $this->assertGreaterThan(0, EventInstance::count());
        Queue::assertNotPushed(AnnounceNewEventJob::class);
        Notification::assertNothingSent();
    }

    #[Test]
    public function creates_two_series(): void
    {
        $this->runDemoSeeder();

        $this->assertSame(2, EventSeries::count());
        $this->assertNotSame($this->leiriaSeries()->id, $this->onlineSeries()->id);
    }

    /**
     * Série semanal de formação em Leiria: seis sessões a partir de depois de amanhã, com os mesmos
     * campos que o backoffice grava numa série (prazo na véspera às 23:59, sem anúncio).
     */
    #[Test]
    public function leiria_series_has_six_weekly_sessions_like_the_backoffice(): void
    {
        $this->runDemoSeeder();

        $series = $this->leiriaSeries();
        $sessions = $this->leiriaSessions();
        $firstDate = $this->lisbonToday()->addDays(2);

        $this->assertSame('weekly', $series->frequency);
        $this->assertSame(1, $series->registration_deadline_days_before);
        $this->assertCount(6, $sessions);
        $this->assertSame($sessions->last()->event_date->toDateString(), $series->ends_on->toDateString());

        foreach ($sessions as $index => $session) {
            $date = $firstDate->copy()->addWeeks($index);
            $label = "sessão {$index}";

            $this->assertSame($date->toDateString(), $session->event_date->toDateString(), $label);
            $this->assertSame($date->copy()->subDay()->toDateString().' 23:59', $session->registration_deadline->format('Y-m-d H:i'), $label);
            $this->assertSame('Formação', $session->category->name, $label);
            $this->assertFalse($session->is_online, $label);
            $this->assertNotNull($session->place_id, $label);
            $this->assertNull($session->latitude, $label);
            $this->assertFalse($session->prayer_cards_enabled, $label);
            $this->assertNull($session->announced_at, $label);
        }

        $this->assertCount(1, $sessions->pluck('place_id')->unique());
    }

    /**
     * Grupo de oração semanal online (o cliente tem grupos de oração semanais): 8 sessões geradas
     * como no backoffice, com link e sem local, nunca anunciadas.
     */
    #[Test]
    public function online_prayer_group_series_has_eight_weekly_sessions(): void
    {
        $this->runDemoSeeder();

        $series = $this->onlineSeries();
        $sessions = $series->eventInstances()->orderBy('event_date')->get()->values();

        $this->assertSame('weekly', $series->frequency);
        $this->assertCount(8, $sessions);
        $this->assertSame($sessions->last()->event_date->toDateString(), $series->ends_on->toDateString());
        $this->assertCount(1, $sessions->pluck('name')->unique());

        foreach ($sessions as $index => $session) {
            $label = "sessão {$index}";

            $this->assertTrue($session->is_online, $label);
            $this->assertStringStartsWith('https://', (string) $session->online_url, $label);
            $this->assertNull($session->place_id, $label);
            $this->assertNull($session->address, $label);
            $this->assertNull($session->announced_at, $label);

            if ($index > 0) {
                $this->assertSame(7, (int) $sessions[$index - 1]->event_date->diffInDays($session->event_date), $label);
            }
        }
    }

    #[Test]
    public function public_agenda_lists_the_future_sessions_of_both_series(): void
    {
        $this->runDemoSeeder();

        $items = collect($this->getJson('/api/events?per_page=100')->assertOk()->json('data.items'));

        $this->assertCount(6, $items->where('series_id', $this->leiriaSeries()->id));

        $onlineFuture = $this->onlineSeries()->eventInstances()->notStarted()->count();
        $this->assertGreaterThan(0, $onlineFuture);
        $this->assertCount($onlineFuture, $items->where('series_id', $this->onlineSeries()->id));
    }

    /**
     * O demo.membro está inscrito "nesta e nas seguintes" a partir da sessão 2 de Leiria (a 1 fica
     * para se inscrever ao vivo); nenhuma sessão passa da lotação.
     */
    #[Test]
    public function demo_member_is_registered_from_the_second_leiria_session_on(): void
    {
        $this->runDemoSeeder();

        $sessions = $this->leiriaSessions();
        $demoMember = $this->demoUser('demo.membro@aspec.test');

        $this->assertFalse($this->isRegistered($sessions[0], $demoMember));

        foreach ($sessions->slice(1) as $index => $session) {
            $this->assertTrue($this->isRegistered($session, $demoMember), "sessão {$index}");
        }

        foreach ($sessions as $session) {
            $this->assertLessThanOrEqual($session->max_attendees, $session->registrations()->count());
        }
    }

    /**
     * Repetir a demonstração mantém a série e os ids das sessões (links do frontend), repõe as
     * inscrições mexidas à mão e volta a criar uma sessão apagada "só nesta sessão".
     */
    #[Test]
    public function running_again_keeps_the_series_and_restores_its_sessions_and_registrations(): void
    {
        $this->runDemoSeeder();

        $seriesId = $this->leiriaSeries()->id;
        $sessions = $this->leiriaSessions();
        $originalIds = $sessions->pluck('id')->all();
        $originalDates = $sessions->map(fn (EventInstance $session) => $session->event_date->toDateString())->all();
        $firstSessionEmails = $this->registrantEmails($sessions[0]);
        $thirdSessionEmails = $this->registrantEmails($sessions[2]);
        $lastSessionCount = $sessions[5]->registrations()->count();

        $outsider = User::whereHas('accountStatus', fn ($query) => $query->where('name', 'Active'))
            ->where('email', 'like', 'demo.%@aspec.test')
            ->whereNotIn('email', $firstSessionEmails)
            ->firstOrFail();

        $sessions[0]->registrations()->create(['user_id' => $outsider->id]);
        $sessions[2]->registrations()->where('user_id', $this->demoUser('demo.membro@aspec.test')->id)->delete();
        $sessions[5]->registrations()->delete();
        $sessions[5]->delete();

        $this->runDemoSeeder();

        $after = $this->leiriaSessions();

        $this->assertSame($seriesId, $this->leiriaSeries()->id);
        $this->assertCount(6, $after);
        $this->assertSame(array_slice($originalIds, 0, 5), $after->take(5)->pluck('id')->all());
        $this->assertSame($originalDates, $after->map(fn (EventInstance $session) => $session->event_date->toDateString())->all());
        $this->assertSame($firstSessionEmails, $this->registrantEmails($after[0]));
        $this->assertSame($thirdSessionEmails, $this->registrantEmails($after[2]));
        $this->assertSame($lastSessionCount, $after[5]->registrations()->count());
        $this->assertSame(2, EventSeries::count());
    }

    #[Test]
    public function running_on_another_day_moves_the_series_dates_and_keeps_the_ids(): void
    {
        $this->runDemoSeeder();

        $originalIds = $this->leiriaSessions()->pluck('id')->all();

        $this->travel(3)->days();
        $this->runDemoSeeder();

        $sessions = $this->leiriaSessions();
        $firstDate = $this->lisbonToday()->addDays(2);

        $this->assertSame(2, EventSeries::count());
        $this->assertSame($originalIds, $sessions->pluck('id')->all());

        foreach ($sessions as $index => $session) {
            $this->assertSame($firstDate->copy()->addWeeks($index)->toDateString(), $session->event_date->toDateString(), "sessão {$index}");
        }
    }

    /**
     * Repetir a demonstração repõe as inscrições mexidas à mão e não toca nos eventos nem nas
     * inscrições que não são da demonstração (ex.: criados pelo professor no deploy).
     */
    #[Test]
    public function running_again_resets_demo_registrations_and_keeps_events_created_by_hand(): void
    {
        $this->runDemoSeeder();
        $eventCount = EventInstance::count();

        $otherEvent = EventInstance::factory()->create(['name' => 'Evento criado à mão no backoffice']);
        $otherUser = User::factory()->create();
        $otherEvent->registrations()->create(['user_id' => $otherUser->id]);

        $demoEvent = $this->futureOneOffEvents()->first(fn (EventInstance $event) => $event->registrations_count > 0);
        $expected = $demoEvent->registrations_count;
        $demoEvent->registrations()->delete();

        $this->runDemoSeeder();

        $this->assertSame($expected, $demoEvent->registrations()->count());
        $this->assertModelExists($otherEvent);
        $this->assertTrue($otherEvent->registrations()->where('user_id', $otherUser->id)->exists());
        $this->assertSame($eventCount + 1, EventInstance::count());
    }

    /**
     * Uma conta que não é demo (ex.: a preparada para a defesa) inscrita num evento demo continua
     * inscrita depois de voltar a correr o seeder, e o evento nunca passa da lotação.
     */
    #[Test]
    public function running_again_keeps_registrations_of_accounts_that_are_not_demo(): void
    {
        $this->runDemoSeeder();

        $full = $this->futureOneOffEvents()->first(fn (EventInstance $event) => $event->registrations_count === $event->max_attendees);
        $open = $this->futureOneOffEvents()->first(fn (EventInstance $event) => $event->registrations_count > 0 && $event->registrations_count < $event->max_attendees);
        $member = User::factory()->create(['email' => 'defesa@example.test']);

        $full->registrations()->create(['user_id' => $member->id]);
        $open->registrations()->create(['user_id' => $member->id]);

        $this->runDemoSeeder();

        $this->assertTrue($this->isRegistered($full, $member));
        $this->assertTrue($this->isRegistered($open, $member));
        $this->assertSame($full->max_attendees, $full->registrations()->count());
        $this->assertSame($open->registrations_count + 1, $open->registrations()->count());
    }

    /**
     * Um evento com o nome e o distrito de um evento retirado da demonstração, mas com outra
     * descrição (criado ou editado à mão), não é apagado.
     */
    #[Test]
    public function retired_event_with_another_description_is_kept(): void
    {
        $event = EventInstance::factory()->create([
            'name' => 'Jantar de Networking ASPEC',
            'location_id' => Location::where('name', 'Faro')->value('id'),
            'description' => 'Jantar criado à mão no backoffice.',
        ]);

        $this->runDemoSeeder();

        $this->assertModelExists($event);
    }

    /**
     * O evento retirado com a descrição da versão anterior e só com dados demo é apagado.
     */
    #[Test]
    public function retired_event_with_the_previous_description_is_removed(): void
    {
        $event = EventInstance::factory()->create([
            'name' => 'Jantar de Networking ASPEC',
            'location_id' => Location::where('name', 'Faro')->value('id'),
            'description' => 'Jantar informal entre membros e convidados, com um breve testemunho empresarial e tempo livre para conversar.',
        ]);

        $this->runDemoSeeder();

        $this->assertModelMissing($event);
    }

    /**
     * Numa base de dados semeada pela versão anterior, os eventos renomeados saem (com as
     * inscrições e os cartões) e os que mantêm o nome são atualizados: nenhum evento da versão
     * anterior fica com os dados antigos nem em duplicado.
     */
    #[Test]
    public function events_of_the_previous_version_are_removed_or_updated(): void
    {
        $marker = 'Evento da versão anterior da demonstração.';
        $previousDescriptions = [
            'Pequeno-almoço de Negócios ASPEC' => 'Um pequeno-almoço para conhecer outros empresários da região, apresentar o seu negócio em dois minutos e criar parcerias.',
            'Jantar de Networking ASPEC' => 'Jantar informal entre membros e convidados, com um breve testemunho empresarial e tempo livre para conversar.',
        ];
        $member = User::factory()->create(['email' => 'demo.membro05@aspec.test']);
        $registrationIds = [];
        $cardIds = [];

        foreach (self::PREVIOUS_VERSION_EVENTS as [$name, $district]) {
            $event = EventInstance::factory()->create([
                'name' => $name,
                'location_id' => Location::where('name', $district)->value('id'),
                'description' => $previousDescriptions[$name] ?? 'Descrição da versão anterior.',
                'organizer_notes' => $marker,
                'prayer_cards_enabled' => true,
                'prayer_verse' => 'Salmo 37:5',
            ]);
            $registrationIds[] = $event->registrations()->create(['user_id' => $member->id])->id;
            $cardIds[] = PrayerCard::factory()->create(['event_instance_id' => $event->id, 'author_user_id' => $member->id])->id;
        }

        $this->runDemoSeeder();

        $this->assertSame(0, EventInstance::where('organizer_notes', $marker)->count());
        $this->assertSame(0, EventRegistration::whereIn('id', $registrationIds)->count());
        $this->assertSame(0, PrayerCard::withTrashed()->whereIn('id', $cardIds)->count());

        foreach ([['Pequeno-almoço de Negócios ASPEC', 'Leiria'], ['Jantar de Networking ASPEC', 'Faro'], ['Jantar de Networking ASPEC', 'Aveiro']] as [$name, $district]) {
            $this->assertFalse(
                EventInstance::where('name', $name)->whereHas('location', fn ($query) => $query->where('name', $district))->exists(),
                "{$name} ({$district})"
            );
        }

        $keys = $this->oneOffEvents()->map(fn (EventInstance $event) => $event->name.'|'.$event->location_id);
        $this->assertSame($keys->count(), $keys->unique()->count());
    }

    /**
     * Cartões de oração em 6 eventos com versículos diferentes: a formação de hoje com o prazo
     * aberto, 4 passados já distribuídos nos últimos 5 meses e 1 futuro ativado ainda sem cartões.
     */
    #[Test]
    public function prayer_cards_are_enabled_on_six_events_with_different_verses(): void
    {
        $this->runDemoSeeder();

        $today = $this->lisbonToday();
        $enabled = EventInstance::withCount(['prayerCards' => fn ($query) => $query->withTrashed()])
            ->where('prayer_cards_enabled', true)
            ->get();

        $this->assertCount(6, $enabled);
        $this->assertCount(6, $enabled->pluck('prayer_verse')->filter()->unique());

        $todays = $enabled->filter(fn (EventInstance $event) => $event->event_date->isSameDay($today));
        $past = $enabled->filter(fn (EventInstance $event) => $event->event_date->lt($today));
        $future = $enabled->filter(fn (EventInstance $event) => $event->event_date->gt($today));

        $this->assertCount(1, $todays);
        $this->assertSame('open', $todays->first()->prayerCardsWindowStatus());
        $this->assertNull($todays->first()->prayer_cards_distributed_at);

        $this->assertCount(4, $past);

        foreach ($past as $event) {
            $this->assertNotNull($event->prayer_cards_distributed_at, $event->name);
            $this->assertTrue($event->event_date->gte($today->copy()->subDays(155)), $event->name);
            $this->assertGreaterThanOrEqual(2, $event->prayer_cards_count, $event->name);
        }

        $this->assertGreaterThanOrEqual(3, $past->map(fn (EventInstance $event) => $event->event_date->format('Y-m'))->unique()->count());

        $this->assertCount(1, $future);
        $this->assertSame(0, $future->first()->prayer_cards_count);
        $this->assertNull($future->first()->prayer_cards_distributed_at);
    }

    /**
     * Só os inscritos escrevem cartões, e num evento distribuído cada autor recebe exatamente um.
     */
    #[Test]
    public function prayer_card_authors_are_registered_and_each_receives_one_card(): void
    {
        $this->runDemoSeeder();

        $cards = PrayerCard::with('eventInstance')->get();
        $this->assertNotEmpty($cards);

        foreach ($cards as $card) {
            $this->assertTrue(
                $card->eventInstance->registrations()->where('user_id', $card->author_user_id)->exists(),
                "Autor não inscrito em {$card->eventInstance->name}"
            );
            $this->assertNotSame($card->author_user_id, $card->recipient_user_id);
        }

        foreach ($cards->whereNotNull('recipient_user_id')->groupBy('event_instance_id') as $eventCards) {
            $this->assertEqualsCanonicalizing($eventCards->pluck('author_user_id')->all(), $eventCards->pluck('recipient_user_id')->all());
        }
    }

    /**
     * O demo.membro recebe 3 cartões, com cartões no período atual e no anterior de "Os meus cartões".
     */
    #[Test]
    public function demo_member_receives_three_prayer_cards_in_the_current_and_previous_periods(): void
    {
        $this->runDemoSeeder();

        $demoMember = $this->demoUser('demo.membro@aspec.test');

        $this->assertSame(3, PrayerCard::where('recipient_user_id', $demoMember->id)->count());

        Sanctum::actingAs($demoMember);

        $current = $this->getJson('/api/me/prayer-cards?period=current')->assertOk()->json('data.pagination.total');
        $previous = $this->getJson('/api/me/prayer-cards?period=previous')->assertOk()->json('data.pagination.total');

        $this->assertGreaterThan(0, $current);
        $this->assertGreaterThan(0, $previous);
        $this->assertSame(3, $current + $previous);
    }

    /**
     * Na formação de hoje o demo.membro está inscrito e ainda não enviou o cartão: pode enviá-lo ao vivo.
     */
    #[Test]
    public function demo_member_can_still_send_the_prayer_card_of_todays_event(): void
    {
        $this->runDemoSeeder();

        $today = EventInstance::where('prayer_cards_enabled', true)
            ->whereDate('event_date', $this->lisbonToday()->toDateString())
            ->sole();

        Sanctum::actingAs($this->demoUser('demo.membro@aspec.test'));

        $this->getJson("/api/events/{$today->id}/prayer-card")
            ->assertOk()
            ->assertJsonPath('data.window.status', 'open')
            ->assertJsonPath('data.card', null);
    }

    #[Test]
    public function prayer_requests_mention_no_real_brand(): void
    {
        $this->runDemoSeeder();

        foreach (PrayerCard::all() as $card) {
            foreach (['personal_request', 'work_request', 'salvation_request'] as $field) {
                $this->assertNoForbiddenBrand($card->{$field}, "Cartão {$card->id}: {$field}");
            }
        }
    }
}
