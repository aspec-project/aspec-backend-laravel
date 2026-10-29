<?php

namespace Tests\Feature\Seeders;

use App\Enums\InactiveReason;
use App\Enums\SubscriptionState;
use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\MemberProfile;
use App\Models\Portfolio;
use App\Models\User;
use App\Rules\InternationalPhone;
use App\Rules\PortuguesePostalCode;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Seeder de dados de demonstração (php artisan db:seed --class=DemoSeeder).
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

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

        $this->assertCount(16, $events);
        $this->assertCount(11, $inPersonFuture);
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
            $this->assertNotNull($event->latitude, $event->name);
            $this->assertNotNull($event->longitude, $event->name);
        }

        $this->assertGreaterThanOrEqual(2, $events->whereNotNull('venue_name')->count());

        $replicated = $events->groupBy('name')->filter(fn ($sameFormat) => $sameFormat->pluck('location_id')->unique()->count() > 1);
        $this->assertGreaterThanOrEqual(2, $replicated->count());
    }

    /**
     * Cada evento presencial (futuro ou passado) tem o place_id da Google da sua morada, válido como
     * o backoffice o aceita e diferente dos outros; os online não têm local.
     */
    #[Test]
    public function in_person_events_have_a_distinct_google_place_id_and_online_events_have_none(): void
    {
        $this->runDemoSeeder();

        $events = EventInstance::all();
        $inPerson = $events->where('is_online', false);

        $this->assertCount(13, $inPerson);

        foreach ($inPerson as $event) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,512}$/D', (string) $event->place_id, $event->name);
        }

        $this->assertCount(13, $inPerson->pluck('place_id')->unique());

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
        $this->assertSame(17, EventInstance::count());
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
     * O demo.membro tem inscrições em 2 ou 3 eventos presenciais futuros, num online e em eventos
     * passados, para a página "As minhas inscrições" mostrar todos os casos.
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

        $inPersonUpcoming = $events->filter(fn (array $event) => ! $event['is_online'] && $event['event_date'] >= $today);

        $this->assertGreaterThanOrEqual(2, $inPersonUpcoming->count());
        $this->assertLessThanOrEqual(3, $inPersonUpcoming->count());
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
            ->assertJsonPath('data.pagination.total', 14);
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
}
