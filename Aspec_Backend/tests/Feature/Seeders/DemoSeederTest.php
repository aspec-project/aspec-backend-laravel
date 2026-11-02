<?php

namespace Tests\Feature\Seeders;

use App\Contracts\InvoiceService;
use App\Enums\InactiveReason;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionState;
use App\Jobs\AnnounceNewEventJob;
use App\Models\AccountStatus;
use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\EventSeries;
use App\Models\Invoice;
use App\Models\MemberProfile;
use App\Models\Portfolio;
use App\Models\Review;
use App\Models\User;
use App\Rules\InternationalPhone;
use App\Rules\PortugueseNif;
use App\Rules\PortuguesePostalCode;
use App\Services\Invoicing\Data\InvoiceCustomerData;
use App\Services\Invoicing\Data\InvoiceData;
use App\Services\Invoicing\Data\IssuedInvoice;
use App\Services\Payments\StripeCustomerService;
use App\Services\Payments\StripeSubscriptionService;
use Carbon\CarbonInterface;
use Closure;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Laravel\Cashier\Subscription;
use Laravel\Cashier\SubscriptionItem;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stripe\Exception\InvalidRequestException;
use Tests\TestCase;
use Throwable;

/**
 * Seeder de dados de demonstração (php artisan db:seed --class=DemoSeeder).
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const SERIES = 'Ciclo de Formação: Liderar Pequenas Equipas';

    private const SERIES_PLACE_ID = 'ChIJLfCNbWxzIg0RRw7VWELDhHQ';

    /** NIFs de demonstração (dígito de controlo válido). */
    private const DEMO_NIFS = [
        '509000010', '509000029', '509000037', '509000045', '509000053', '509000061', '509000070', '509000088',
        '509000096', '509000100', '509000118', '509000126', '509000134', '509000142', '509000150',
    ];

    /** Contas Active em trial sem cancelamento agendado (o demo.membro02 tem-no). */
    private const TRIAL_MEMBERS = [
        'demo.membro01@aspec.test', 'demo.membro03@aspec.test', 'demo.membro04@aspec.test', 'demo.membro05@aspec.test',
        'demo.membro06@aspec.test', 'demo.membro08@aspec.test', 'demo.membro09@aspec.test', 'demo.membro10@aspec.test',
        'demo.membro11@aspec.test',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        $this->app->instance(InvoiceService::class, $this->fastInvoiceService());
    }

    /**
     * Cada execução do seeder emite 4 faturas demo e cada PDF (dompdf) demora cerca de 0,25 s; com
     * dezenas de execuções neste ficheiro, os testes que não olham para as faturas usam este serviço,
     * que devolve o mesmo que o driver demo sem gerar o PDF nem enviar o email.
     */
    private function fastInvoiceService(): InvoiceService
    {
        return new class implements InvoiceService
        {
            public function issue(InvoiceCustomerData $customer, InvoiceData $invoice, ?string $draftId = null, ?Closure $onDraftCreated = null): IssuedInvoice
            {
                $number = 'DEMO-'.$invoice->date->format('Y').'-'.strtoupper(substr(sha1($invoice->stripeInvoiceId), 0, 8));

                return new IssuedInvoice('demo', "invoices/{$customer->code}/{$number}.pdf", $number, null, IssuedInvoice::ISSUED);
            }

            public function pdf(string $providerInvoiceId): ?string
            {
                return null;
            }
        };
    }

    /**
     * Repõe o serviço de faturação configurado, para o seeder emitir as faturas pelo driver demo real (PDF e email).
     */
    private function useRealInvoiceService(): void
    {
        $this->app->forgetInstance(InvoiceService::class);
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

    /**
     * Corre o seeder e devolve a conta demo indicada.
     */
    private function runAndGet(string $email): User
    {
        $this->runDemoSeeder();

        return $this->demoUser($email);
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

    /**
     * Início do UUID da conta sem hífenes, de onde derivam os ids falsos do Stripe da demonstração.
     */
    private function demoIdFragment(User $user): string
    {
        return str_replace('-', '', $user->id);
    }

    private function subscriptionOf(string $email): Subscription
    {
        $subscriptions = Subscription::where('user_id', $this->demoUser($email)->id)->get();
        $this->assertCount(1, $subscriptions, "{$email}: subscrições");

        return $subscriptions->first();
    }

    private function demoSeries(): EventSeries
    {
        $this->assertSame(1, EventSeries::count(), 'Séries de demonstração');

        return EventSeries::sole();
    }

    /**
     * Faturas da conta, da mais antiga para a mais recente.
     *
     * @return Collection<int, Invoice>
     */
    private function invoicesOf(string $email): Collection
    {
        return Invoice::where('user_id', $this->demoUser($email)->id)->orderBy('period_start')->get()->values();
    }

    /**
     * As datas do seeder são relativas a "agora"; a tolerância (por omissão um dia) aceita que o
     * seeder use o início do dia ou a hora exata.
     */
    private function assertAbout(CarbonInterface $expected, ?CarbonInterface $actual, string $message = '', int $toleranceSeconds = 86400): void
    {
        $this->assertNotNull($actual, $message);
        $this->assertEqualsWithDelta($expected->getTimestamp(), $actual->getTimestamp(), $toleranceSeconds, $message);
    }

    /**
     * Mailer "espião" como mailer por omissão: o seeder tem de trocar para o array durante a
     * emissão das faturas demo, por isso nada pode chegar a este.
     */
    private function useSpyMailerAsDefault(): void
    {
        config([
            'mail.mailers.demo_spy' => ['transport' => 'array'],
            'mail.default' => 'demo_spy',
        ]);
    }

    private function spyMailerMessageCount(): int
    {
        return app('mail.manager')->mailer('demo_spy')->getSymfonyTransport()->messages()->count();
    }

    /**
     * Serviço de faturação que falha sempre e conta as chamadas (o job resolve o contrato pelo container).
     */
    private function failingInvoiceService(): InvoiceService
    {
        return new class implements InvoiceService
        {
            public int $calls = 0;

            public function issue(InvoiceCustomerData $customer, InvoiceData $invoice, ?string $draftId = null, ?Closure $onDraftCreated = null): IssuedInvoice
            {
                $this->calls++;

                throw new RuntimeException('Falha simulada na emissão.');
            }

            public function pdf(string $providerInvoiceId): ?string
            {
                return null;
            }
        };
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
        $this->useRealInvoiceService();
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
            'subscriptions' => Subscription::count(),
            'subscription_items' => SubscriptionItem::count(),
            'invoices' => Invoice::count(),
            'invoice_files' => count(Storage::disk('local')->allFiles()),
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
        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, Invoice::count());
        $this->assertEmpty(Storage::disk('public')->allFiles());
        $this->assertEmpty(Storage::disk('local')->allFiles());
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

    /**
     * Estado derivado de cada conta (o que o backoffice mostra) e estado da subscrição local.
     */
    #[Test]
    public function every_demo_account_has_the_subscription_of_its_scenario(): void
    {
        $this->runDemoSeeder();

        $expected = [
            'demo.membro@aspec.test' => [SubscriptionState::Active, 'active'],
            'demo.admin@aspec.test' => [SubscriptionState::Active, 'active'],
            'demo.membro07@aspec.test' => [SubscriptionState::Grace, 'past_due'],
            'demo.membro02@aspec.test' => [SubscriptionState::Trial, 'trialing'],
            'demo.inativo.pagamento@aspec.test' => [SubscriptionState::Lapsed, 'canceled'],
            'demo.bloqueado@aspec.test' => [SubscriptionState::Blocked, 'canceled'],
            'demo.aprovado@aspec.test' => [SubscriptionState::AwaitingActivation, null],
            'demo.pendente01@aspec.test' => [SubscriptionState::Pending, null],
            'demo.pendente02@aspec.test' => [SubscriptionState::Pending, null],
            'demo.pendente03@aspec.test' => [SubscriptionState::Pending, null],
        ];

        foreach (self::TRIAL_MEMBERS as $email) {
            $expected[$email] = [SubscriptionState::Trial, 'trialing'];
        }

        $this->assertCount(19, $expected);

        foreach ($expected as $email => [$state, $stripeStatus]) {
            $user = $this->demoUser($email);
            $subscriptions = Subscription::with('items')->where('user_id', $user->id)->get();

            $this->assertSame($state, $user->subscriptionState(), $email);
            $this->assertFalse($user->isSubscriptionExempt(), $email);

            if ($stripeStatus === null) {
                $this->assertCount(0, $subscriptions, $email);

                continue;
            }

            $this->assertCount(1, $subscriptions, $email);
            $subscription = $subscriptions->first();

            $this->assertSame('default', $subscription->type, $email);
            $this->assertSame($stripeStatus, $subscription->stripe_status, $email);
            $this->assertSame(1, $subscription->quantity, $email);
            $this->assertCount(1, $subscription->items, $email);
            $this->assertSame(1, $subscription->items->first()->quantity, $email);
            $this->assertSame($subscription->trial_ends_at?->getTimestamp(), $user->trial_ends_at?->getTimestamp(), $email);
        }

        $this->assertSame(0, User::where('subscription_exempt', true)->count());
    }

    /**
     * Datas de cada cenário relativas a "agora" (T): trial, períodos pagos, carência e fim.
     */
    #[Test]
    public function payment_dates_follow_each_scenario(): void
    {
        $this->runDemoSeeder();

        $graceDays = config('subscription.grace_days');
        $trialDays = config('subscription.trial_days');

        $demoMember = $this->subscriptionOf('demo.membro@aspec.test');
        $demoMemberInvoices = $this->invoicesOf('demo.membro@aspec.test');
        $this->assertCount(2, $demoMemberInvoices);
        $this->assertAbout(now()->subDays(5), $demoMemberInvoices[1]->period_start, 'demo.membro: período atual');
        $this->assertSame($demoMemberInvoices[0]->period_end->getTimestamp(), $demoMemberInvoices[1]->period_start->getTimestamp());
        $this->assertNotNull($demoMember->trial_ends_at);
        $this->assertLessThanOrEqual($demoMemberInvoices[0]->period_start->getTimestamp() + 60, $demoMember->trial_ends_at->getTimestamp());
        $this->assertAbout($demoMember->trial_ends_at->copy()->subDays($trialDays), $demoMember->created_at, 'demo.membro: início do trial');
        $this->assertNull($demoMember->ends_at);

        $admin = $this->subscriptionOf('demo.admin@aspec.test');
        $adminInvoices = $this->invoicesOf('demo.admin@aspec.test');
        $this->assertCount(1, $adminInvoices);
        $this->assertAbout(now()->subDays(20), $adminInvoices[0]->period_start, 'demo.admin: período atual');
        $this->assertNotNull($admin->trial_ends_at);
        $this->assertTrue($admin->trial_ends_at->isPast());
        $this->assertNull($admin->ends_at);

        $graceMember = $this->demoUser('demo.membro07@aspec.test');
        $graceInvoices = $this->invoicesOf('demo.membro07@aspec.test');
        $this->assertCount(1, $graceInvoices);
        $this->assertAbout(now()->subDays(2), $graceInvoices[0]->period_end, 'demo.membro07: renovação falhada');
        $this->assertAbout($graceInvoices[0]->period_end->copy()->addDays($graceDays), $graceMember->grace_ends_at, 'demo.membro07: fim da carência', 60);
        $this->assertTrue($graceMember->grace_ends_at->isFuture());
        $this->assertNull($this->subscriptionOf('demo.membro07@aspec.test')->ends_at);

        $scheduledCancel = $this->subscriptionOf('demo.membro02@aspec.test');
        $this->assertAbout(now()->addDays(10), $scheduledCancel->trial_ends_at, 'demo.membro02: fim do trial');
        $this->assertSame($scheduledCancel->trial_ends_at->getTimestamp(), $scheduledCancel->ends_at?->getTimestamp());

        foreach (self::TRIAL_MEMBERS as $email) {
            $subscription = $this->subscriptionOf($email);

            $this->assertNotNull($subscription->trial_ends_at, $email);
            $this->assertGreaterThanOrEqual(now()->addDays(7)->subMinute()->getTimestamp(), $subscription->trial_ends_at->getTimestamp(), $email);
            $this->assertLessThanOrEqual(now()->addDays(29)->getTimestamp(), $subscription->trial_ends_at->getTimestamp(), $email);
            $this->assertAbout($subscription->trial_ends_at->copy()->subDays($trialDays), $subscription->created_at, $email);
            $this->assertNull($subscription->ends_at, $email);
            $this->assertNull($this->demoUser($email)->grace_ends_at, $email);
        }

        $lapsed = $this->subscriptionOf('demo.inativo.pagamento@aspec.test');
        $this->assertAbout(now()->subDays(17), $lapsed->trial_ends_at, 'demo.inativo.pagamento: fim do trial');
        $this->assertAbout($lapsed->trial_ends_at->copy()->addDays($graceDays), $lapsed->ends_at, 'demo.inativo.pagamento: fim', 60);

        $blocked = $this->subscriptionOf('demo.bloqueado@aspec.test');
        $this->assertAbout(now()->subDays(15), $blocked->created_at, 'demo.bloqueado: criada');
        $this->assertAbout(now()->subDays(5), $blocked->ends_at, 'demo.bloqueado: fim');

        foreach (['demo.membro@aspec.test', 'demo.admin@aspec.test', ...self::TRIAL_MEMBERS] as $email) {
            $this->assertNull($this->demoUser($email)->grace_ends_at, $email);
        }
    }

    /**
     * Ids falsos com "_demo_" derivados da conta (nunca do Stripe), sem cliente Stripe na conta,
     * e faturação preenchida só em quem já ativou.
     */
    #[Test]
    public function payment_rows_use_fake_demo_ids_and_billing_only_where_the_account_was_activated(): void
    {
        $this->runDemoSeeder();

        $users = User::with('memberProfile')->where('email', 'like', 'demo.%@aspec.test')->get();
        $this->assertCount(19, $users);

        $withoutBilling = ['demo.aprovado@aspec.test', 'demo.pendente01@aspec.test', 'demo.pendente02@aspec.test', 'demo.pendente03@aspec.test'];

        foreach ($users as $user) {
            $email = $user->email;

            $this->assertNull($user->stripe_id, $email);
            $this->assertNull($user->pm_type, $email);
            $this->assertNull($user->pm_last_four, $email);
            $this->assertNull($user->stripe_checkout_session_id, $email);

            if (in_array($email, $withoutBilling, true)) {
                foreach (['billing_name', 'nif', 'billing_address', 'billing_postal_code', 'billing_city'] as $field) {
                    $this->assertNull($user->{$field}, "{$email}: {$field}");
                }

                continue;
            }

            $fragment = $this->demoIdFragment($user);
            $subscription = Subscription::with('items')->where('user_id', $user->id)->first();
            $this->assertNotNull($subscription, "{$email}: sem subscrição");
            $this->assertCount(1, $subscription->items, "{$email}: items");

            $this->assertSame("sub_demo_{$fragment}", $subscription->stripe_id, $email);
            $this->assertSame("si_demo_{$fragment}", $subscription->items->sole()->stripe_id, $email);
            $this->assertSame('prod_demo', $subscription->items->sole()->stripe_product, $email);
            $this->assertSame('price_demo', $subscription->items->sole()->stripe_price, $email);

            foreach (Invoice::where('user_id', $user->id)->get() as $invoice) {
                $this->assertMatchesRegularExpression("/^in_demo_{$fragment}_\\d+$/", $invoice->stripe_invoice_id, $email);
            }

            $validator = Validator::make(['nif' => $user->nif], ['nif' => ['required', new PortugueseNif]]);
            $this->assertFalse($validator->fails(), "{$email}: NIF {$user->nif}");
            $this->assertContains($user->nif, self::DEMO_NIFS, $email);
            $this->assertSame($user->memberProfile->business_name, $user->billing_name, $email);
            $this->assertSame($user->memberProfile->address, $user->billing_address, $email);
            $this->assertSame($user->memberProfile->postal_code, $user->billing_postal_code, $email);
            $this->assertSame($user->memberProfile->city, $user->billing_city, $email);
        }

        $this->assertSame(15, Subscription::where('stripe_id', 'like', 'sub_demo_%')->count());
        $this->assertSame(15, SubscriptionItem::where('stripe_id', 'like', 'si_demo_%')->count());
        $this->assertSame(4, Invoice::where('stripe_invoice_id', 'like', 'in_demo_%')->count());
    }

    /**
     * O que o membro vê em /conta/subscricao: tudo vem da base de dados, sem chamar o Stripe.
     */
    #[Test]
    public function subscription_endpoint_shows_each_demo_scenario(): void
    {
        $this->mock(StripeSubscriptionService::class);

        $this->runDemoSeeder();

        $latestInvoice = $this->invoicesOf('demo.membro@aspec.test')->last();
        $this->assertNotNull($latestInvoice);

        Sanctum::actingAs($this->demoUser('demo.membro@aspec.test'));
        $response = $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.next_payment_at', $latestInvoice->period_end->toIso8601String())
            ->assertJsonPath('data.grace_ends_at', null)
            ->assertJsonPath('data.cancel_at_period_end', false)
            ->assertJsonPath('data.price.currency', config('subscription.currency'))
            ->assertJsonPath('data.price.interval', config('subscription.interval'))
            ->assertJsonPath('data.billing.billing_name', 'Ferreira Arquitetura e Interiores')
            ->assertJsonPath('data.billing.billing_address', 'Rua de Cedofeita 300')
            ->assertJsonPath('data.billing.billing_postal_code', '4050-174')
            ->assertJsonPath('data.billing.billing_city', 'Porto');

        $this->assertEquals(config('subscription.price_amount'), $response->json('data.price.amount'));

        Sanctum::actingAs($this->demoUser('demo.membro02@aspec.test'));
        $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.cancel_at_period_end', true);

        Sanctum::actingAs($this->demoUser('demo.membro01@aspec.test'));
        $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.trial_days', config('subscription.trial_days'))
            ->assertJsonPath('data.cancel_at_period_end', false);

        Sanctum::actingAs($this->demoUser('demo.membro07@aspec.test'));
        $graceEndsAt = $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('data.status', 'past_due')
            ->json('data.grace_ends_at');

        $this->assertNotNull($graceEndsAt);
        $this->assertTrue(Carbon::parse($graceEndsAt)->isFuture());
    }

    #[Test]
    public function monthly_fee_produces_monthly_periods(): void
    {
        config(['subscription.interval' => 'month']);
        $this->travelTo(now('Europe/Lisbon')->startOfMonth()->addDays(14)->setTime(12, 0));

        $this->runDemoSeeder();

        $invoices = $this->invoicesOf('demo.membro@aspec.test');
        $this->assertCount(2, $invoices);

        foreach ($invoices as $invoice) {
            $this->assertSame($invoice->period_start->copy()->addMonthNoOverflow()->getTimestamp(), $invoice->period_end->getTimestamp());
        }

        $this->assertSame($invoices[0]->period_end->getTimestamp(), $invoices[1]->period_start->getTimestamp());
        $this->assertAbout(now()->subDays(5), $invoices[1]->period_start);

        Sanctum::actingAs($this->demoUser('demo.membro@aspec.test'));
        $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('data.next_payment_at', $invoices[1]->period_end->toIso8601String())
            ->assertJsonPath('data.price.interval', 'month');

        $adminInvoices = $this->invoicesOf('demo.admin@aspec.test');
        $this->assertCount(1, $adminInvoices);
        $adminInvoice = $adminInvoices->first();
        $this->assertSame($adminInvoice->period_start->copy()->addMonthNoOverflow()->getTimestamp(), $adminInvoice->period_end->getTimestamp());
    }

    #[Test]
    public function yearly_fee_produces_yearly_periods(): void
    {
        $this->assertSame('year', config('subscription.interval'));

        $this->runDemoSeeder();

        $invoices = Invoice::where('stripe_invoice_id', 'like', 'in_demo_%')->get();
        $this->assertCount(4, $invoices);

        foreach ($invoices as $invoice) {
            $this->assertSame($invoice->period_start->copy()->addYearNoOverflow()->getTimestamp(), $invoice->period_end->getTimestamp());
        }
    }

    /**
     * Lista de faturas como o membro a vê: duas pagas do demo.membro, uma do admin e do membro em
     * carência, nenhuma em trial.
     */
    #[Test]
    public function invoices_endpoint_lists_the_demo_invoices_of_each_account(): void
    {
        $this->useRealInvoiceService();
        $this->runDemoSeeder();

        Sanctum::actingAs($this->demoUser('demo.membro@aspec.test'));
        $items = $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->json('data.items');

        $this->assertCount(2, $items);
        $this->assertGreaterThan($items[1]['period_start'], $items[0]['period_start']);
        $this->assertSame(Carbon::parse($items[1]['period_end'])->addDay()->toDateString(), $items[0]['period_start']);

        foreach ($items as $item) {
            $this->assertSame('issued', $item['status']);
            $this->assertEquals(config('subscription.price_amount'), $item['amount']);
            $this->assertSame(config('subscription.currency'), $item['currency']);
            $this->assertTrue($item['pdf_available']);
            $this->assertMatchesRegularExpression('/^DEMO-\d{4}-[0-9A-F]{8}$/', $item['number']);
        }

        foreach (['demo.admin@aspec.test' => 1, 'demo.membro07@aspec.test' => 1, 'demo.membro02@aspec.test' => 0, ...array_fill_keys(self::TRIAL_MEMBERS, 0)] as $email => $count) {
            Sanctum::actingAs($this->demoUser($email));

            $this->getJson('/api/invoices')
                ->assertOk()
                ->assertJsonPath('data.pagination.total', $count);
        }
    }

    /**
     * As faturas passam pelo mesmo job do webhook com o driver demo, mas com as datas do pagamento
     * (não as do dia em que o seeder corre).
     */
    #[Test]
    public function demo_invoices_are_issued_by_the_demo_driver_with_the_payment_date(): void
    {
        $this->useRealInvoiceService();
        $this->runDemoSeeder();

        $invoices = Invoice::with('user')->where('stripe_invoice_id', 'like', 'in_demo_%')->get();
        $this->assertCount(4, $invoices);

        foreach ($invoices as $invoice) {
            $label = $invoice->stripe_invoice_id;
            $paidAt = $invoice->period_start;

            $this->assertSame('demo', $invoice->provider, $label);
            $this->assertSame(InvoiceStatus::Issued, $invoice->status, $label);
            $this->assertSame((int) round(config('subscription.price_amount') * 100), $invoice->amount, $label);
            $this->assertSame(config('subscription.currency'), $invoice->currency, $label);
            $this->assertMatchesRegularExpression('/^DEMO-'.$paidAt->format('Y').'-[0-9A-F]{8}$/', $invoice->number, $label);
            $this->assertAbout($paidAt, $invoice->issued_at, $label, 5);
            $this->assertAbout($paidAt, $invoice->created_at, $label, 5);
            $this->assertSame(config('services.invoicing.vat_rate'), $invoice->vat_rate, $label);
            $this->assertSame(config('services.invoicing.vat_exemption_reason'), $invoice->vat_exemption_reason, $label);
            $this->assertStringStartsWith("invoices/{$invoice->user_id}/", $invoice->provider_invoice_id, $label);
            Storage::disk('local')->assertExists($invoice->provider_invoice_id);
        }
    }

    #[Test]
    public function demo_member_downloads_a_demo_invoice_pdf(): void
    {
        $this->useRealInvoiceService();
        $this->runDemoSeeder();

        $invoice = $this->invoicesOf('demo.membro@aspec.test')->last();
        $this->assertNotNull($invoice);

        Sanctum::actingAs($this->demoUser('demo.membro@aspec.test'));

        $this->get("/api/invoices/{$invoice->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    /**
     * O seeder troca o driver de faturação, o mailer e o relógio só durante a emissão: depois volta
     * tudo ao que era e nenhum email sai pelo mailer configurado (NIF e morada no PDF).
     */
    #[Test]
    public function issuing_restores_the_driver_mailer_and_clock_and_sends_no_email(): void
    {
        $this->useRealInvoiceService();
        $this->useSpyMailerAsDefault();
        $this->assertSame('log', config('services.invoicing.driver'));

        $this->runDemoSeeder();

        $this->assertSame(4, Invoice::where('provider', 'demo')->where('stripe_invoice_id', 'like', 'in_demo_%')->count());
        $this->assertSame('log', config('services.invoicing.driver'));
        $this->assertSame('demo_spy', config('mail.default'));
        $this->assertFalse(Carbon::hasTestNow());
        $this->assertSame(0, $this->spyMailerMessageCount());
    }

    #[Test]
    public function issuing_restores_the_driver_mailer_and_clock_when_it_fails(): void
    {
        $this->useSpyMailerAsDefault();
        $failing = $this->failingInvoiceService();
        $this->app->instance(InvoiceService::class, $failing);

        try {
            $this->app->make(DemoSeeder::class)->setContainer($this->app)->__invoke();
        } catch (Throwable) {
            // A falha pode ou não chegar aqui; o que conta é o estado reposto depois.
        }

        $this->assertGreaterThan(0, $failing->calls);
        $this->assertSame('log', config('services.invoicing.driver'));
        $this->assertSame('demo_spy', config('mail.default'));
        $this->assertFalse(Carbon::hasTestNow());
    }

    /**
     * Seeder sem Stripe: os serviços do Stripe são mocks sem expectativas (qualquer chamada falha),
     * nenhum pedido HTTP sai e a rede de segurança do Cashier aponta para uma porta fechada.
     */
    #[Test]
    public function seeding_payments_never_calls_stripe_nor_external_services(): void
    {
        Http::preventStrayRequests();
        $this->mock(StripeSubscriptionService::class);
        $this->mock(StripeCustomerService::class);

        $this->runDemoSeeder();

        $this->assertSame(15, Subscription::count());
        $this->assertSame(4, Invoice::count());
    }

    #[Test]
    public function admin_dashboard_counts_one_member_in_grace_and_one_unpaid(): void
    {
        $this->runDemoSeeder();

        Sanctum::actingAs($this->demoUser('demo.admin@aspec.test'));

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.stats.overdue_payments.total', 2)
            ->assertJsonPath('data.stats.overdue_payments.grace', 1)
            ->assertJsonPath('data.stats.overdue_payments.unpaid', 1);
    }

    /**
     * Bloquear o membro em carência pede ao Stripe o cancelamento da subscrição demo (com chaves
     * de teste o Stripe responde resource_missing); o bloqueio nunca falha por isso.
     */
    #[Test]
    public function blocking_the_member_in_grace_cancels_the_demo_subscription_without_error(): void
    {
        Notification::fake();
        $member = $this->runAndGet('demo.membro07@aspec.test');
        $expectedStripeId = 'sub_demo_'.$this->demoIdFragment($member);

        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) use ($expectedStripeId) {
            $mock->shouldReceive('cancelNow')
                ->once()
                ->withArgs(fn (Subscription $subscription) => $subscription->stripe_id === $expectedStripeId);
            $mock->shouldNotReceive('expireCheckout');
        });

        Sanctum::actingAs($this->demoUser('demo.admin@aspec.test'));

        $this->patchJson("/api/admin/users/{$member->id}/block")->assertOk();

        $this->assertSame(InactiveReason::Blocked, $member->fresh()->inactive_reason);
    }

    /**
     * As contas demo não têm cliente Stripe: o portal responde 404 sem chamar o Stripe, embora a
     * subscrição demo exista.
     */
    #[Test]
    public function billing_portal_for_a_demo_subscription_is_not_found_without_calling_stripe(): void
    {
        $member = $this->runAndGet('demo.membro@aspec.test');
        $this->assertNotNull($member->currentSubscription());

        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('billingPortalUrl');
        });

        Sanctum::actingAs($member);

        $this->postJson('/api/subscription/billing-portal')->assertNotFound();
    }

    /**
     * Cancelar uma subscrição demo: o Stripe não a conhece, por isso o pedido falha de forma
     * controlada (502) sem gravar nada nem enviar o email de confirmação.
     */
    #[Test]
    public function cancelling_a_demo_subscription_fails_with_502_and_changes_nothing(): void
    {
        Notification::fake();
        $member = $this->runAndGet('demo.membro@aspec.test');
        $subscription = $member->currentSubscription();
        $this->assertNotNull($subscription);

        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) use ($subscription) {
            $mock->shouldReceive('cancelAtPeriodEnd')
                ->once()
                ->andThrow(InvalidRequestException::factory("No such subscription: '{$subscription->stripe_id}'", 404, null, null, null, 'resource_missing'));
        });

        Sanctum::actingAs($member);

        $this->postJson('/api/subscription/cancel')
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Não foi possível contactar o serviço de pagamentos. Tente novamente.');

        $subscription->refresh();
        $this->assertSame('active', $subscription->stripe_status);
        $this->assertNull($subscription->ends_at);
        $this->assertSame('Active', $this->demoUser('demo.membro@aspec.test')->accountStatus->name);
        Notification::assertNothingSent();
    }

    /**
     * O end-grace não toca no demo.membro07 antes do fim da carência; depois, com o Stripe a dizer
     * que a subscrição terminou, a conta passa a Inactive por falta de pagamento (estado coerente).
     */
    #[Test]
    public function end_grace_leaves_the_demo_member_alone_until_the_grace_period_ends(): void
    {
        Notification::fake();
        $member = $this->runAndGet('demo.membro07@aspec.test');
        $this->assertNotNull($member->grace_ends_at);

        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('retrieveStatus');
        });

        $this->artisan('subscriptions:end-grace')->assertSuccessful();

        $this->assertSame('Active', $this->demoUser('demo.membro07@aspec.test')->accountStatus->name);

        $this->travelTo($member->grace_ends_at->copy()->addMinute());

        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('retrieveStatus')->once()->andReturn('canceled');
            $mock->shouldReceive('cancelNow')->byDefault();
        });

        $this->artisan('subscriptions:end-grace')->assertSuccessful();

        $member = $this->demoUser('demo.membro07@aspec.test');
        $this->assertSame('Inactive', $member->accountStatus->name);
        $this->assertSame(InactiveReason::Unpaid, $member->inactive_reason);
    }

    /**
     * Anonimizar o demo.membro não chama o Stripe (sem cliente) e guarda as faturas (retenção
     * fiscal); a execução seguinte cria a conta de novo, com ids demo diferentes e sem violar o unique.
     */
    #[Test]
    public function anonymizing_a_demo_member_keeps_its_invoices_and_the_seeder_recreates_it(): void
    {
        Notification::fake();
        $old = $this->runAndGet('demo.membro@aspec.test');
        $oldSubscriptionId = $old->currentSubscription()?->stripe_id;
        $this->assertNotNull($oldSubscriptionId);

        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('delete');
        });
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelNow')->byDefault();
            $mock->shouldNotReceive('expireCheckout');
        });

        Sanctum::actingAs($this->demoUser('demo.admin@aspec.test'));

        $this->postJson("/api/admin/users/{$old->id}/anonymize")->assertOk();

        $this->assertSame(2, Invoice::where('user_id', $old->id)->count());

        $this->runDemoSeeder();

        $new = $this->demoUser('demo.membro@aspec.test');
        $newSubscription = $new->currentSubscription();

        $this->assertNotSame($old->id, $new->id);
        $this->assertNotNull($newSubscription);
        $this->assertNotSame($oldSubscriptionId, $newSubscription->stripe_id);
        $this->assertTrue(Subscription::where('stripe_id', $oldSubscriptionId)->exists());
        $this->assertSame(2, Invoice::where('user_id', $old->id)->count());
        $this->assertSame(2, Invoice::where('user_id', $new->id)->count());
    }

    /**
     * O reset só apaga linhas e PDFs com o prefixo "_demo_" das contas demo: as linhas reais de uma
     * conta demo, os dados de outras contas e ficheiros que não são da demo ficam intactos.
     */
    #[Test]
    public function running_again_never_touches_payments_that_are_not_demo(): void
    {
        $this->runDemoSeeder();

        $other = User::factory()->withBilling()->create();
        $otherSubscription = $other->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_other',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);
        $otherPdf = "invoices/{$other->id}/DEMO-2026-ABCDEF12.pdf";
        Storage::disk('local')->put($otherPdf, 'pdf');
        $otherInvoice = Invoice::factory()->issued()->create([
            'user_id' => $other->id,
            'stripe_invoice_id' => 'in_test_other',
            'provider' => 'demo',
            'provider_invoice_id' => $otherPdf,
        ]);
        Storage::disk('local')->put('invoices/manual/nota.pdf', 'pdf');

        $demoMember = $this->demoUser('demo.membro@aspec.test');
        $endedRealSubscription = $demoMember->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_old_real',
            'stripe_status' => 'canceled',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'ends_at' => now()->subYears(2),
        ]);
        $realInvoice = Invoice::factory()->issued()->create([
            'user_id' => $demoMember->id,
            'stripe_invoice_id' => 'in_test_real_demo_member',
            'created_at' => now()->subYears(2),
        ]);

        $this->runDemoSeeder();

        $this->assertModelExists($otherSubscription);
        $this->assertModelExists($otherInvoice);
        $this->assertModelExists($endedRealSubscription);
        $this->assertModelExists($realInvoice);
        Storage::disk('local')->assertExists($otherPdf);
        Storage::disk('local')->assertExists('invoices/manual/nota.pdf');
        $this->assertSame(2, Invoice::where('user_id', $demoMember->id)->where('stripe_invoice_id', 'like', 'in_demo_%')->count());
    }

    /**
     * Antes de recriar as faturas demo, o seeder apaga o PDF de cada uma (o caminho guardado na
     * linha), para não ficarem ficheiros órfãos no disco privado.
     */
    #[Test]
    public function running_again_deletes_the_pdf_of_each_previous_demo_invoice(): void
    {
        $this->runDemoSeeder();

        $invoice = $this->invoicesOf('demo.membro@aspec.test')->first();
        $this->assertNotNull($invoice);
        $stalePdf = "invoices/{$invoice->user_id}/DEMO-1999-0000000A.pdf";
        Storage::disk('local')->put($stalePdf, 'pdf');
        $invoice->forceFill(['provider_invoice_id' => $stalePdf])->save();

        $this->runDemoSeeder();

        Storage::disk('local')->assertMissing($stalePdf);
        $this->assertModelMissing($invoice);
    }

    /**
     * Uma conta demo reativada a sério num ensaio (subscrição real em curso) fica sem dados de
     * pagamento demo: duas subscrições em curso fariam o webhook tratar a real como duplicado.
     */
    #[Test]
    public function demo_account_with_a_real_running_subscription_gets_no_demo_payments(): void
    {
        $member = $this->runAndGet('demo.inativo.pagamento@aspec.test');

        $real = $member->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_real_123',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);
        $member->forceFill([
            'account_status_id' => AccountStatus::where('name', 'Active')->value('id'),
            'inactive_reason' => null,
            'grace_ends_at' => now()->addDays(3),
            'stripe_checkout_session_id' => 'cs_test_real',
        ])->save();

        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])
            ->expectsOutputToContain('A conta demo.inativo.pagamento@aspec.test tem uma subscrição real do Stripe em curso')
            ->expectsOutputToContain('cancele-a no dashboard de teste do Stripe e volte a correr o seeder.')
            ->assertSuccessful();

        $real->refresh();
        $this->assertSame('active', $real->stripe_status);
        $this->assertNull($real->ends_at);
        $this->assertSame(0, Subscription::where('user_id', $member->id)->where('stripe_id', 'like', 'sub_demo_%')->count());
        $this->assertSame(1, Subscription::where('user_id', $member->id)->count());
        $this->assertSame(14, Subscription::where('stripe_id', 'like', 'sub_demo_%')->count());

        $member->refresh();
        $this->assertSame('Active', $member->accountStatus->name);
        $this->assertNull($member->inactive_reason);
        $this->assertNotNull($member->grace_ends_at);
        $this->assertSame('cs_test_real', $member->stripe_checkout_session_id);
    }

    /**
     * Conjuntos de fotografias do portefólio por perfil, com o número de imagens de cada um.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    private function portfolioSets(): array
    {
        return [
            'Ferreira Arquitetura e Interiores' => ['arquitetura', 4],
            'Carvalho & Filhos Construções' => ['construcao', 3],
            'Sabores do Algarve Restaurante' => ['restaurante', 3],
            'Ideia Viva Comunicação' => ['comunicacao', 4],
            'Quinta do Vale Verde' => ['quinta', 3],
        ];
    }

    /**
     * Caminhos do portefólio pela ordem em que o perfil público os mostra.
     *
     * @return list<string>
     */
    private function portfolioPaths(MemberProfile $profile): array
    {
        return $profile->portfolios()->orderBy('created_at')->orderBy('id')->pluck('image_path')->all();
    }

    private function profileByBusinessName(string $businessName): MemberProfile
    {
        return MemberProfile::where('business_name', $businessName)->firstOrFail();
    }

    #[Test]
    public function only_the_five_showcase_profiles_get_a_portfolio_with_their_photos_in_order(): void
    {
        $this->runDemoSeeder();

        $this->assertEqualsCanonicalizing(
            array_keys($this->portfolioSets()),
            MemberProfile::has('portfolios')->pluck('business_name')->all(),
        );

        foreach ($this->portfolioSets() as $businessName => [$set, $count]) {
            $profile = $this->profileByBusinessName($businessName);

            $expected = array_map(
                fn (int $number) => "portfolios/{$profile->user_id}/demo-{$set}-{$number}.jpg",
                range(1, $count),
            );

            $this->assertSame($expected, $this->portfolioPaths($profile), $businessName);
        }
    }

    #[Test]
    public function demo_member_showcases_the_architecture_portfolio(): void
    {
        $member = $this->runAndGet('demo.membro@aspec.test');

        $this->assertSame('Ferreira Arquitetura e Interiores', $member->memberProfile->business_name);
        $this->assertCount(4, $this->portfolioPaths($member->memberProfile));
    }

    #[Test]
    public function portfolio_files_are_the_source_jpeg_photos_byte_for_byte(): void
    {
        $this->runDemoSeeder();

        $disk = Storage::disk('public');
        $checked = 0;

        foreach ($this->portfolioSets() as $businessName => [$set, $count]) {
            $profile = $this->profileByBusinessName($businessName);

            for ($number = 1; $number <= $count; $number++) {
                $path = "portfolios/{$profile->user_id}/demo-{$set}-{$number}.jpg";
                $source = database_path("seeders/demo/portfolio/{$set}-{$number}.jpg");

                $disk->assertExists($path);
                $stored = $disk->get($path);

                $this->assertSame('image/jpeg', getimagesizefromstring($stored)['mime'] ?? null, $path);
                $this->assertSame(sha1_file($source), sha1($stored), $path);
                $checked++;
            }
        }

        $this->assertSame(17, $checked);
        $this->assertCount(17, glob(database_path('seeders/demo/portfolio/*.jpg')));
    }

    /**
     * As imagens geradas por versões anteriores da demonstração (demo-projeto-N.png) saem ao repetir o
     * seeder; uma imagem carregada pelo próprio membro, no mesmo perfil, fica.
     */
    #[Test]
    public function running_again_removes_old_demo_images_but_keeps_member_uploads(): void
    {
        $this->runDemoSeeder();

        $profile = $this->profileByBusinessName('Ferreira Arquitetura e Interiores');
        $oldDemo = "portfolios/{$profile->user_id}/demo-projeto-1.png";
        $memberUpload = "portfolios/{$profile->user_id}/abc.jpg";

        Storage::disk('public')->put($oldDemo, 'old demo image');
        Storage::disk('public')->put($memberUpload, 'member upload');
        $profile->portfolios()->create(['image_path' => $oldDemo]);
        $profile->portfolios()->create(['image_path' => $memberUpload]);

        $this->runDemoSeeder();

        $this->assertDatabaseMissing('portfolios', ['image_path' => $oldDemo]);
        Storage::disk('public')->assertMissing($oldDemo);

        $this->assertDatabaseHas('portfolios', ['profile_id' => $profile->id, 'image_path' => $memberUpload]);
        Storage::disk('public')->assertExists($memberUpload);

        $this->assertSame(5, $profile->portfolios()->count());

        foreach (range(1, 4) as $number) {
            Storage::disk('public')->assertExists("portfolios/{$profile->user_id}/demo-arquitetura-{$number}.jpg");
        }
    }

    #[Test]
    public function public_profile_returns_the_portfolio_image_urls(): void
    {
        $this->runDemoSeeder();

        $profile = $this->profileByBusinessName('Quinta do Vale Verde');

        $expected = array_map(
            fn (int $number) => Storage::disk('public')->url("portfolios/{$profile->user_id}/demo-quinta-{$number}.jpg"),
            range(1, 3),
        );

        $response = $this->getJson("/api/members/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data.portfolio');

        $this->assertSame($expected, array_column($response->json('data.portfolio'), 'image_url'));
    }
}
