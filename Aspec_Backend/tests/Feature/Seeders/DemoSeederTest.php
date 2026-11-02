<?php

namespace Tests\Feature\Seeders;

use App\Enums\InactiveReason;
use App\Enums\SubscriptionState;
use App\Jobs\AnnounceNewEventJob;
use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\EventSeries;
use App\Models\MemberProfile;
use App\Models\Portfolio;
use App\Models\Review;
use App\Models\User;
use App\Rules\InternationalPhone;
use App\Rules\PortuguesePostalCode;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Seeder de dados de demonstração (php artisan db:seed --class=DemoSeeder).
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const SERIES = 'Ciclo de Formação: Liderar Pequenas Equipas';

    private const SERIES_PLACE_ID = 'ChIJLfCNbWxzIg0RRw7VWELDhHQ';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * Corre o seeder como na linha de comandos; o --force evita a confirmação do Laravel em
     * produção, para ser a proteção do próprio seeder a decidir.
     */
    private function runDemoSeeder(): void
    {
        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->assertSuccessful();
    }

    private function demoUser(string $email): User
    {
        return User::with(['role', 'accountStatus', 'memberProfile'])->where('email', $email)->firstOrFail();
    }

    private function activeProfiles()
    {
        return MemberProfile::listedInDirectory()->get();
    }

    /**
     * Dia de hoje em Lisboa, como no seeder e na agenda pública (a aplicação corre em UTC).
     */
    private function lisbonToday(): Carbon
    {
        return now(config('app.event_timezone', 'Europe/Lisbon'))->startOfDay();
    }

    private function demoEvent(string $name, string $district): EventInstance
    {
        return EventInstance::where('name', $name)
            ->whereHas('location', fn ($query) => $query->where('name', $district))
            ->firstOrFail();
    }

    /**
     * Sessões da série de demonstração, por ordem de data.
     *
     * @return Collection<int, EventInstance>
     */
    private function seriesSessions(): Collection
    {
        return EventInstance::where('name', self::SERIES)
            ->whereHas('location', fn ($query) => $query->where('name', 'Leiria'))
            ->orderBy('event_date')
            ->get()
            ->values();
    }

    /**
     * Emails dos inscritos numa sessão, por ordem alfabética.
     *
     * @return list<string>
     */
    private function registrantEmails(EventInstance $event): array
    {
        return User::whereHas('eventRegistrations', fn ($query) => $query->where('event_instance_id', $event->id))
            ->orderBy('email')
            ->pluck('email')
            ->all();
    }

    private function demoSeries(): EventSeries
    {
        $this->assertSame(1, EventSeries::count(), 'Séries de demonstração');

        return EventSeries::sole();
    }

    #[Test]
    public function creates_accounts_with_the_right_role_status_and_reason(): void
    {
        $this->runDemoSeeder();

        $expected = [
            'demo.admin@aspec.test' => ['Admin', 'Active', null],
            'demo.membro@aspec.test' => ['Member', 'Active', null],
            'demo.pendente01@aspec.test' => ['Member', 'Pending', null],
            'demo.pendente02@aspec.test' => ['Member', 'Pending', null],
            'demo.pendente03@aspec.test' => ['Member', 'Pending', null],
            'demo.aprovado@aspec.test' => ['Member', 'Approved', null],
            'demo.inativo.pagamento@aspec.test' => ['Member', 'Inactive', InactiveReason::Unpaid],
            'demo.bloqueado@aspec.test' => ['Member', 'Inactive', InactiveReason::Blocked],
        ];

        foreach (range(1, 11) as $number) {
            $expected[sprintf('demo.membro%02d@aspec.test', $number)] = ['Member', 'Active', null];
        }

        foreach ($expected as $email => [$role, $status, $reason]) {
            $user = $this->demoUser($email);

            $this->assertSame($role, $user->role->name, $email);
            $this->assertSame($status, $user->accountStatus->name, $email);
            $this->assertSame($reason, $user->inactive_reason, $email);
            $this->assertNotNull($user->email_verified_at, $email);
            $this->assertNotNull($user->memberProfile, $email);
            $this->assertMatchesRegularExpression('/^\+\d{8,15}$/', $user->phone, $email);
            $this->assertSame($user->phone, InternationalPhone::normalize($user->phone), $email);
        }

        $this->assertSame(19, User::where('email', 'like', 'demo.%@aspec.test')->count());
    }

    /**
     * O admin da demo tem perfil e paga como os outros administradores: só o aspec:create-admin
     * cria o administrador técnico isento, e o seeder nunca escreve essa marca.
     */
    #[Test]
    public function demo_admin_is_not_subscription_exempt_and_shows_as_active(): void
    {
        $this->runDemoSeeder();

        $admin = $this->demoUser('demo.admin@aspec.test');

        $this->assertFalse($admin->subscription_exempt);
        $this->assertFalse($admin->isSubscriptionExempt());
        $this->assertSame(SubscriptionState::Active, $admin->subscriptionState());
        $this->assertSame(0, User::where('subscription_exempt', true)->count());
    }

    #[Test]
    public function stores_every_phone_in_e164_with_two_foreign_members(): void
    {
        $this->runDemoSeeder();

        $phones = User::where('email', 'like', 'demo.%@aspec.test')->pluck('phone');

        foreach ($phones as $phone) {
            $this->assertMatchesRegularExpression('/^\+\d{8,15}$/', $phone);
            $this->assertSame($phone, InternationalPhone::normalize($phone));
        }

        $this->assertGreaterThanOrEqual(2, $phones->reject(fn (string $phone) => str_starts_with($phone, '+351'))->count());
    }

    #[Test]
    public function creates_thirteen_complete_active_profiles_with_varied_sectors_and_districts(): void
    {
        $this->runDemoSeeder();

        $profiles = $this->activeProfiles();

        $this->assertCount(13, $profiles);
        $this->assertGreaterThanOrEqual(8, $profiles->pluck('sector_id')->unique()->count());
        $this->assertGreaterThanOrEqual(8, $profiles->pluck('location_id')->unique()->count());

        $districts = $profiles->load('location')->pluck('location.name');

        foreach (['Lisboa', 'Porto', 'Braga', 'Coimbra', 'Faro', 'Aveiro', 'Leiria', 'Setúbal'] as $district) {
            $this->assertContains($district, $districts);
        }

        foreach ($profiles->load(['socialPlatforms', 'weekDays']) as $profile) {
            foreach (['description', 'website_url', 'commercial_email', 'commercial_phone', 'address', 'congregation', 'role_in_congregation'] as $field) {
                $this->assertNotEmpty($profile->{$field}, "{$profile->business_name}: {$field}");
            }

            $this->assertGreaterThanOrEqual(1, $profile->socialPlatforms->count());
            $this->assertLessThanOrEqual(3, $profile->socialPlatforms->count());
        }

        $withoutHours = $profiles->filter(fn (MemberProfile $profile) => $profile->weekDays->isEmpty());
        $this->assertGreaterThanOrEqual(1, $withoutHours->count());
        $this->assertLessThanOrEqual(2, $withoutHours->count());
    }

    #[Test]
    public function every_demo_profile_has_street_postal_code_and_city_separated(): void
    {
        $this->runDemoSeeder();

        $profiles = MemberProfile::whereHas('user', fn ($query) => $query->where('email', 'like', 'demo.%@aspec.test'))->get();
        $this->assertNotEmpty($profiles);

        foreach ($profiles as $profile) {
            $validator = Validator::make(
                $profile->only(['postal_code', 'city']),
                [
                    'postal_code' => ['required', 'string', new PortuguesePostalCode],
                    'city' => 'required|string|max:100',
                ],
            );

            $this->assertFalse($validator->fails(), "{$profile->business_name}: ".$validator->errors()->toJson());
            $this->assertNotEmpty($profile->address, $profile->business_name);
            $this->assertStringNotContainsString($profile->postal_code, $profile->address, $profile->business_name);
        }
    }

    #[Test]
    public function every_demo_profile_has_valid_commercial_email_and_e164_phone(): void
    {
        $this->runDemoSeeder();

        $profiles = MemberProfile::whereHas('user', fn ($query) => $query->where('email', 'like', 'demo.%@aspec.test'))->get();
        $this->assertNotEmpty($profiles);

        foreach ($profiles as $profile) {
            $validator = Validator::make(
                $profile->only(['commercial_email', 'commercial_phone']),
                [
                    'commercial_email' => 'required|string|email:rfc|max:255',
                    'commercial_phone' => ['required', 'string', new InternationalPhone],
                ],
            );

            $this->assertFalse($validator->fails(), "{$profile->business_name}: ".$validator->errors()->toJson());
            $this->assertSame(InternationalPhone::normalize($profile->commercial_phone), $profile->commercial_phone, $profile->business_name);
        }
    }

    #[Test]
    public function creates_in_person_online_and_past_events_in_every_category(): void
    {
        $this->runDemoSeeder();

        $events = EventInstance::with('location')->get();

        $today = $this->lisbonToday();
        $inPersonFuture = $events->filter(fn (EventInstance $event) => ! $event->is_online && $event->event_date->gte($today));

        $this->assertCount(22, $events);
        $this->assertCount(17, $inPersonFuture);
        $this->assertSame(EventCategory::count(), $events->pluck('category_id')->unique()->count());
        $this->assertGreaterThanOrEqual(6, $inPersonFuture->pluck('location_id')->unique()->count());

        foreach ($events as $event) {
            $this->assertTrue($event->event_date->between($today->copy()->subDays(60), $today->copy()->addDays(60)), $event->name);
            $this->assertTrue($event->registration_deadline->lessThan($event->event_date), $event->name);
            $this->assertSame('23:59', $event->registration_deadline->format('H:i'), $event->name);
            $this->assertGreaterThanOrEqual(10, $event->max_attendees);
            $this->assertLessThanOrEqual(100, $event->max_attendees);
        }

        foreach ($inPersonFuture as $event) {
            $this->assertNotEmpty($event->address, $event->name);
            $this->assertNotNull($event->place_id, $event->name);
            $this->assertNull($event->latitude, $event->name);
            $this->assertNull($event->longitude, $event->name);
        }

        $this->assertGreaterThanOrEqual(2, $events->whereNotNull('venue_name')->count());

        $replicated = $events->groupBy('name')->filter(fn ($sameFormat) => $sameFormat->pluck('location_id')->unique()->count() > 1);
        $this->assertGreaterThanOrEqual(2, $replicated->count());
    }

    /**
     * Cada evento presencial único (futuro ou passado) tem o place_id da Google da sua morada, válido
     * como o backoffice o aceita e diferente dos outros; as sessões da série partilham o do auditório
     * de Leiria; os online não têm local.
     */
    #[Test]
    public function in_person_events_have_a_distinct_google_place_id_and_online_events_have_none(): void
    {
        $this->runDemoSeeder();

        $events = EventInstance::all();
        $inPerson = $events->where('is_online', false);
        $oneOff = $inPerson->whereNull('series_id');
        $sessions = $inPerson->whereNotNull('series_id');

        $this->assertCount(19, $inPerson);
        $this->assertCount(13, $oneOff);
        $this->assertCount(6, $sessions);

        foreach ($inPerson as $event) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,512}$/D', (string) $event->place_id, $event->name);
        }

        $this->assertCount(13, $oneOff->pluck('place_id')->unique());
        $this->assertSame([self::SERIES_PLACE_ID], $sessions->pluck('place_id')->unique()->values()->all());

        foreach ($events->where('is_online', true) as $event) {
            $this->assertNull($event->place_id, $event->name);
        }
    }

    #[Test]
    public function public_event_api_returns_the_demo_place_id(): void
    {
        $this->runDemoSeeder();

        $event = $this->demoEvent('Pequeno-almoço de Negócios ASPEC', 'Lisboa');

        $this->getJson("/api/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.place_id', 'ChIJJ8bvTYYxGQ0RoC1RSUhyz2k');
    }

    #[Test]
    public function demo_events_use_the_five_real_categories(): void
    {
        $this->runDemoSeeder();

        foreach (['Pequeno-almoço', 'Almoço', 'Jantar', 'Workshop', 'Formação'] as $name) {
            $this->assertTrue(
                EventInstance::whereHas('category', fn ($query) => $query->where('name', $name))->exists(),
                $name
            );
        }

        $this->assertSame(5, EventCategory::count());
        $this->assertFalse(EventInstance::where('name', 'Reunião Regional de Membros')->exists());
    }

    #[Test]
    #[RequiresPhpExtension('gd')]
    public function stores_logos_and_portfolio_images_on_the_public_disk(): void
    {
        $this->runDemoSeeder();

        foreach ($this->activeProfiles() as $profile) {
            $this->assertNotNull($profile->logo_path, $profile->business_name);
            $this->assertStringStartsWith("logos/{$profile->user_id}/", $profile->logo_path);
            Storage::disk('public')->assertExists($profile->logo_path);
        }

        $profilesWithPortfolio = MemberProfile::has('portfolios')->with('portfolios')->get();
        $this->assertGreaterThanOrEqual(4, $profilesWithPortfolio->count());

        foreach ($profilesWithPortfolio as $profile) {
            $this->assertGreaterThanOrEqual(3, $profile->portfolios->count());

            foreach ($profile->portfolios as $image) {
                $this->assertStringStartsWith("portfolios/{$profile->user_id}/", $image->image_path);
                Storage::disk('public')->assertExists($image->image_path);
            }
        }
    }

    #[Test]
    public function running_twice_does_not_duplicate_anything(): void
    {
        $this->runDemoSeeder();

        $counts = fn () => [
            'users' => User::count(),
            'profiles' => MemberProfile::count(),
            'events' => EventInstance::count(),
            'portfolios' => Portfolio::count(),
            'files' => count(Storage::disk('public')->allFiles()),
            'registrations' => EventRegistration::count(),
            'registrations_per_event' => EventInstance::withCount('registrations')->orderBy('id')->pluck('registrations_count', 'id')->all(),
            'event_series' => EventSeries::count(),
        ];

        $first = $counts();

        $this->runDemoSeeder();

        $this->assertSame($first, $counts());
    }

    /**
     * Repetir a demonstração repõe as inscrições mexidas à mão (cancelamentos e inscrições novas)
     * e não toca nos eventos nem nas inscrições que não são da demonstração.
     */
    #[Test]
    public function running_again_resets_demo_registrations_without_touching_other_events(): void
    {
        $this->runDemoSeeder();

        $otherEvent = EventInstance::factory()->create(['name' => 'Evento local de teste']);
        $otherUser = User::factory()->create();
        $otherEvent->registrations()->create(['user_id' => $otherUser->id]);

        $lisbonBreakfast = $this->demoEvent('Pequeno-almoço de Negócios ASPEC', 'Lisboa');
        $expected = $lisbonBreakfast->registrations()->count();
        $lisbonBreakfast->registrations()->delete();

        $this->runDemoSeeder();

        $this->assertSame($expected, $lisbonBreakfast->registrations()->count());
        $this->assertTrue($otherEvent->registrations()->where('user_id', $otherUser->id)->exists());
        $this->assertSame(23, EventInstance::count());
    }

    /**
     * A agenda semanal só sai uma vez por semana a cada conta; repetir a demonstração tem de
     * permitir enviá-la de novo às contas demo, sem mexer nas outras contas.
     */
    #[Test]
    public function running_again_clears_the_weekly_agenda_mark_of_demo_accounts_only(): void
    {
        $this->runDemoSeeder();

        $otherUser = User::factory()->create(['weekly_agenda_sent_at' => now()]);
        User::where('email', 'like', 'demo.%@aspec.test')->update(['weekly_agenda_sent_at' => now()]);

        $this->runDemoSeeder();

        $this->assertSame(0, User::where('email', 'like', 'demo.%@aspec.test')->whereNotNull('weekly_agenda_sent_at')->count());
        $this->assertNotNull($otherUser->fresh()->weekly_agenda_sent_at);
    }

    #[Test]
    public function only_active_members_are_registered_and_never_above_capacity(): void
    {
        $this->runDemoSeeder();

        $registrations = EventRegistration::with(['user.accountStatus', 'user.role', 'eventInstance'])->get();
        $this->assertNotEmpty($registrations);

        foreach ($registrations as $registration) {
            $this->assertSame('Active', $registration->user->accountStatus->name, $registration->user->email);
            $this->assertSame('Member', $registration->user->role->name, $registration->user->email);
        }

        foreach (EventInstance::withCount('registrations')->get() as $event) {
            $this->assertLessThanOrEqual($event->max_attendees, $event->registrations_count, $event->name);
        }
    }

    /**
     * Os três eventos online cobrem os casos do link, vistos pela API pública como o frontend:
     * antes do prazo ninguém o vê; depois do prazo só os inscritos.
     */
    #[Test]
    public function online_events_show_the_link_only_to_registered_members_after_the_deadline(): void
    {
        $this->runDemoSeeder();

        $demoMember = $this->demoUser('demo.membro@aspec.test');
        $notRegistered = $this->demoUser('demo.membro11@aspec.test');
        $registeredInRgpd = $this->demoUser('demo.membro05@aspec.test');

        $open = $this->demoEvent('Webinar: Faturação Eletrónica sem Complicações', 'Lisboa');
        $withDemoMember = $this->demoEvent('Workshop Online: Vender Mais nas Redes Sociais', 'Lisboa');
        $withoutDemoMember = $this->demoEvent('Formação Online: RGPD para Pequenas Empresas', 'Lisboa');

        foreach ([$open, $withDemoMember, $withoutDemoMember] as $event) {
            $this->assertTrue($event->is_online, $event->name);
            $this->assertStringStartsWith('https://meet.example.test/aspec-', $event->online_url);
            $this->assertNull($event->address, $event->name);
            $this->assertNull($event->venue_name, $event->name);
            $this->assertNull($event->latitude, $event->name);
            $this->assertNull($event->longitude, $event->name);
            $this->assertTrue($event->event_date->gt($this->lisbonToday()), $event->name);
        }

        $this->actingAs($demoMember, 'sanctum')->getJson("/api/events/{$open->id}")
            ->assertOk()
            ->assertJsonPath('data.is_online', true)
            ->assertJsonPath('data.registration_open', true)
            ->assertJsonPath('data.is_registered', false)
            ->assertJsonMissingPath('data.online_url');

        $this->actingAs($demoMember, 'sanctum')->getJson("/api/events/{$withDemoMember->id}")
            ->assertOk()
            ->assertJsonPath('data.registration_open', false)
            ->assertJsonPath('data.is_registered', true)
            ->assertJsonPath('data.online_url', $withDemoMember->online_url);

        $this->actingAs($notRegistered, 'sanctum')->getJson("/api/events/{$withDemoMember->id}")
            ->assertOk()
            ->assertJsonPath('data.is_registered', false)
            ->assertJsonMissingPath('data.online_url');

        $this->actingAs($demoMember, 'sanctum')->getJson("/api/events/{$withoutDemoMember->id}")
            ->assertOk()
            ->assertJsonPath('data.registration_open', false)
            ->assertJsonPath('data.is_registered', false)
            ->assertJsonMissingPath('data.online_url');

        $this->actingAs($registeredInRgpd, 'sanctum')->getJson("/api/events/{$withoutDemoMember->id}")
            ->assertOk()
            ->assertJsonPath('data.is_registered', true)
            ->assertJsonPath('data.online_url', $withoutDemoMember->online_url);
    }

    #[Test]
    public function one_event_is_full_and_another_is_almost_full(): void
    {
        $this->runDemoSeeder();

        $full = $this->demoEvent('Workshop: Apresente o seu Negócio em Dois Minutos', 'Porto');
        $almostFull = $this->demoEvent('Pequeno-almoço de Negócios ASPEC', 'Braga');
        $demoMember = $this->demoUser('demo.membro@aspec.test');

        $this->getJson("/api/events/{$full->id}")
            ->assertOk()
            ->assertJsonPath('data.registrations_count', $full->max_attendees)
            ->assertJsonPath('data.available_spots', 0)
            ->assertJsonPath('data.registration_open', false);

        $this->actingAs($demoMember, 'sanctum')
            ->postJson("/api/events/{$full->id}/registrations")
            ->assertConflict();

        $response = $this->getJson("/api/events/{$almostFull->id}")
            ->assertOk()
            ->assertJsonPath('data.registration_open', true);

        $this->assertGreaterThan(0.9, $response->json('data.registrations_count') / $almostFull->max_attendees);
        $this->assertGreaterThanOrEqual(1, $response->json('data.available_spots'));
    }

    /**
     * O demo.membro tem inscrições em 2 ou 3 eventos presenciais futuros, em 5 sessões da série,
     * num online e em eventos passados, para a página "As minhas inscrições" mostrar todos os casos.
     */
    #[Test]
    public function demo_member_has_upcoming_online_and_past_registrations(): void
    {
        $this->runDemoSeeder();

        $today = $this->lisbonToday()->toDateString();

        $events = collect(
            $this->actingAs($this->demoUser('demo.membro@aspec.test'), 'sanctum')
                ->getJson('/api/me/registrations')
                ->assertOk()
                ->json('data')
        )->pluck('event');

        $inPersonUpcoming = $events->filter(fn (array $event) => ! $event['is_online'] && $event['event_date'] >= $today && $event['series_id'] === null);

        $this->assertGreaterThanOrEqual(2, $inPersonUpcoming->count());
        $this->assertLessThanOrEqual(3, $inPersonUpcoming->count());
        $this->assertSame(5, $events->whereNotNull('series_id')->count());
        $this->assertSame(1, $events->where('is_online', true)->count());
        $this->assertGreaterThanOrEqual(1, $events->filter(fn (array $event) => $event['event_date'] < $today)->count());
    }

    #[Test]
    public function past_events_have_registrations_but_stay_out_of_the_public_agenda(): void
    {
        $this->runDemoSeeder();

        $past = EventInstance::withCount('registrations')
            ->where('event_date', '<', $this->lisbonToday()->toDateString())
            ->get();

        $this->assertCount(2, $past);

        foreach ($past as $event) {
            $this->assertGreaterThan(0, $event->registrations_count, $event->name);
        }

        $agendaIds = collect($this->getJson('/api/events?per_page=100')->assertOk()->json('data.items'))->pluck('id');

        foreach ($past as $event) {
            $this->assertNotContains($event->id, $agendaIds, $event->name);
        }
    }

    #[Test]
    public function refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])
            ->expectsOutputToContain('O DemoSeeder não pode correr em produção.')
            ->assertSuccessful();

        $this->assertSame(0, User::where('email', 'like', 'demo.%')->count());
        $this->assertSame(0, MemberProfile::count());
        $this->assertSame(0, EventInstance::count());
        $this->assertSame(0, EventSeries::count());
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    #[Test]
    public function directory_and_agenda_endpoints_return_the_demo_data(): void
    {
        $this->runDemoSeeder();

        $this->getJson('/api/members?per_page=50')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 13)
            ->assertJsonCount(13, 'data.items');

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 19);

        $today = EventInstance::where('name', 'Formação: Finanças para Pequenas Empresas')->sole();
        Sanctum::actingAs($this->demoUser('demo.membro@aspec.test'));

        $this->getJson("/api/events/{$today->id}/prayer-card")
            ->assertOk()
            ->assertJsonPath('data.window.status', 'open')
            ->assertJsonPath('data.card', null);
    }

    /**
     * A demonstração do filtro por avaliação precisa de perfis em "4,5+", outros só em "4+" ou abaixo,
     * e de uma avaliação Hidden e outra Flagged para a moderação.
     */
    #[Test]
    public function creates_visible_hidden_and_flagged_reviews_that_feed_the_rating_filter(): void
    {
        $this->runDemoSeeder();

        $statuses = Review::with('status')->get()->countBy(fn (Review $review) => $review->status->name);

        $this->assertGreaterThanOrEqual(10, $statuses['Visible'] ?? 0);
        $this->assertSame(1, $statuses['Hidden'] ?? 0);
        $this->assertSame(1, $statuses['Flagged'] ?? 0);
        $this->assertSame(0, Review::whereColumn('reviewer_profile_id', 'reviewed_profile_id')->count());

        $highest = $this->getJson('/api/members?min_rating=4.5&per_page=50')->assertOk()->json('data.items');
        $fourPlus = $this->getJson('/api/members?min_rating=4&per_page=50')->assertOk()->json('data.items');

        $this->assertNotEmpty($highest);
        $this->assertGreaterThan(count($highest), count($fourPlus));
        $this->assertTrue(collect($highest)->every(fn (array $item) => $item['average_rating'] >= 4.5));
    }

    /**
     * Avaliações moderadas à mão durante uma demonstração voltam ao estado original;
     * avaliações que não são entre perfis de demonstração ficam como estão.
     */
    #[Test]
    public function running_again_resets_demo_reviews_without_touching_other_reviews(): void
    {
        $this->runDemoSeeder();

        $demoCount = Review::count();
        $demoMemberProfileId = $this->demoUser('demo.membro@aspec.test')->memberProfile->id;
        Review::where('reviewed_profile_id', $demoMemberProfileId)->delete();
        $other = Review::factory()->create(['reviewed_profile_id' => $demoMemberProfileId]);

        $this->runDemoSeeder();

        $this->assertSame($demoCount + 1, Review::count());
        $this->assertModelExists($other);
        $this->assertSame(4, Review::where('reviewed_profile_id', $demoMemberProfileId)->count());
    }

    #[Test]
    public function demo_member_can_log_in_with_the_demo_password(): void
    {
        $this->runDemoSeeder();

        $this->postJson('/api/auth/token', [
            'email' => 'demo.membro@aspec.test',
            'password' => DemoSeeder::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('Demo#2026', DemoSeeder::PASSWORD);
    }

    /**
     * O mapa da página do evento usa só o place_id; a API nunca grava place_id e coordenadas juntos.
     */
    #[Test]
    public function no_demo_event_has_coordinates_and_every_in_person_event_has_a_place_id(): void
    {
        $this->runDemoSeeder();

        $this->assertSame(0, EventInstance::whereNotNull('latitude')->orWhereNotNull('longitude')->count());
        $this->assertSame(0, EventInstance::where('is_online', false)->whereNull('place_id')->count());
    }

    /**
     * Numa base de dados já semeada com as coordenadas antigas, voltar a correr o seeder limpa-as.
     */
    #[Test]
    public function running_again_clears_coordinates_saved_before(): void
    {
        $this->runDemoSeeder();

        $event = $this->demoEvent('Pequeno-almoço de Negócios ASPEC', 'Lisboa');
        $event->forceFill(['latitude' => 38.7676, 'longitude' => -9.0973])->save();

        $this->runDemoSeeder();

        $event->refresh();
        $this->assertNull($event->latitude);
        $this->assertNull($event->longitude);
        $this->assertSame('ChIJJ8bvTYYxGQ0RoC1RSUhyz2k', $event->place_id);
    }

    /**
     * Série semanal de formação em Leiria: seis sessões a partir de depois de amanhã, com os mesmos
     * campos que o backoffice grava numa série (prazo na véspera às 23:59, sem anúncio).
     */
    #[Test]
    public function creates_a_weekly_series_with_six_sessions_like_the_backoffice(): void
    {
        $this->runDemoSeeder();

        $series = $this->demoSeries();
        $sessions = $this->seriesSessions();
        $firstDate = $this->lisbonToday()->addDays(2);

        $this->assertSame('weekly', $series->frequency);
        $this->assertSame(1, $series->registration_deadline_days_before);
        $this->assertCount(6, $sessions);
        $this->assertSame($sessions->last()->event_date->toDateString(), $series->ends_on->toDateString());

        foreach ($sessions as $index => $session) {
            $date = $firstDate->copy()->addWeeks($index);
            $label = "sessão {$index}";

            $this->assertSame($series->id, $session->series_id, $label);
            $this->assertSame($date->toDateString(), $session->event_date->toDateString(), $label);
            $this->assertSame($date->copy()->subDay()->toDateString().' 23:59', $session->registration_deadline->format('Y-m-d H:i'), $label);
            $this->assertSame('18:30', substr($session->event_start_time, 0, 5), $label);
            $this->assertSame('20:30', substr($session->event_end_time, 0, 5), $label);
            $this->assertSame(20, $session->max_attendees, $label);
            $this->assertSame('Formação', $session->category->name, $label);
            $this->assertFalse($session->is_online, $label);
            $this->assertSame('Auditório, Rua Barão de Viamonte 20, 2400-262 Leiria', $session->address, $label);
            $this->assertSame(self::SERIES_PLACE_ID, $session->place_id, $label);
            $this->assertNull($session->latitude, $label);
            $this->assertNull($session->longitude, $label);
            $this->assertFalse($session->prayer_cards_enabled, $label);
            $this->assertNull($session->announced_at, $label);
            $this->assertSame(0, $session->prayerCards()->withTrashed()->count(), $label);
        }
    }

    #[Test]
    public function public_agenda_lists_the_six_series_sessions(): void
    {
        $this->runDemoSeeder();

        $seriesId = $this->demoSeries()->id;
        $items = collect($this->getJson('/api/events?per_page=100')->assertOk()->json('data.items'));

        $sessions = $items->where('name', self::SERIES);

        $this->assertCount(6, $sessions);
        $this->assertSame([$seriesId], $sessions->pluck('series_id')->unique()->values()->all());
    }

    /**
     * O demo.membro está inscrito "nesta e nas seguintes" a partir da sessão 2 (a 1 fica para se
     * inscrever ao vivo); outros membros cobrem a série toda, uma sessão só e as duas primeiras.
     */
    #[Test]
    public function series_registrations_cover_every_case(): void
    {
        $this->runDemoSeeder();

        $sessions = $this->seriesSessions();
        $member = fn (int $number) => sprintf('demo.membro%02d@aspec.test', $number);
        $demoMember = 'demo.membro@aspec.test';

        $expected = [
            [$member(1), $member(4), $member(10)],
            [$demoMember, $member(1), $member(4), $member(10)],
            [$demoMember, $member(1), $member(4), $member(9)],
            [$demoMember, $member(1), $member(4)],
            [$demoMember, $member(1), $member(4)],
            [$demoMember, $member(1), $member(4)],
        ];

        $this->assertCount(6, $sessions);

        foreach ($sessions as $index => $session) {
            $emails = $expected[$index];
            sort($emails);

            $this->assertSame($emails, $this->registrantEmails($session), "sessão {$index}");
            $this->assertLessThanOrEqual($session->max_attendees, $session->registrations()->count());
        }
    }

    /**
     * A série nasce sem anúncio (o anúncio só sai do backoffice e nunca para séries) e o seeder
     * não envia notificações.
     */
    #[Test]
    public function seeding_never_announces_events_nor_sends_notifications(): void
    {
        Queue::fake([AnnounceNewEventJob::class]);
        Notification::fake();

        $this->runDemoSeeder();

        $this->assertCount(6, $this->seriesSessions());
        Queue::assertNotPushed(AnnounceNewEventJob::class);
        Notification::assertNothingSent();
    }

    /**
     * Repetir a demonstração mantém a série e os ids das sessões (links do frontend), repõe as
     * inscrições mexidas à mão e volta a criar uma sessão apagada "só nesta sessão".
     */
    #[Test]
    public function running_again_keeps_the_series_and_restores_its_sessions_and_registrations(): void
    {
        $this->runDemoSeeder();

        $seriesId = $this->demoSeries()->id;
        $sessions = $this->seriesSessions();
        $originalIds = $sessions->pluck('id')->all();
        $originalDates = $sessions->map(fn (EventInstance $session) => $session->event_date->toDateString())->all();
        $firstSessionEmails = $this->registrantEmails($sessions[0]);
        $thirdSessionEmails = $this->registrantEmails($sessions[2]);

        $sessions[0]->registrations()->create(['user_id' => $this->demoUser('demo.membro11@aspec.test')->id]);
        $sessions[2]->registrations()->where('user_id', $this->demoUser('demo.membro@aspec.test')->id)->delete();
        $sessions[5]->registrations()->delete();
        $sessions[5]->delete();

        $this->runDemoSeeder();

        $after = $this->seriesSessions();

        $this->assertSame($seriesId, $this->demoSeries()->id);
        $this->assertCount(6, $after);
        $this->assertSame(array_slice($originalIds, 0, 5), $after->take(5)->pluck('id')->all());
        $this->assertSame($originalDates, $after->map(fn (EventInstance $session) => $session->event_date->toDateString())->all());
        $this->assertSame([$seriesId], $after->pluck('series_id')->unique()->values()->all());
        $this->assertSame($firstSessionEmails, $this->registrantEmails($after[0]));
        $this->assertSame($thirdSessionEmails, $this->registrantEmails($after[2]));
        $this->assertSame(3, $after[5]->registrations()->count());
    }

    #[Test]
    public function running_on_another_day_moves_the_series_dates_and_keeps_the_ids(): void
    {
        $this->runDemoSeeder();

        $originalIds = $this->seriesSessions()->pluck('id')->all();

        $this->travel(3)->days();
        $this->runDemoSeeder();

        $sessions = $this->seriesSessions();
        $firstDate = $this->lisbonToday()->addDays(2);

        $this->assertSame(1, EventSeries::count());
        $this->assertSame($originalIds, $sessions->pluck('id')->all());

        foreach ($sessions as $index => $session) {
            $this->assertSame($firstDate->copy()->addWeeks($index)->toDateString(), $session->event_date->toDateString(), "sessão {$index}");
        }

        $this->assertSame($sessions->last()->event_date->toDateString(), $this->demoSeries()->ends_on->toDateString());
    }
}
