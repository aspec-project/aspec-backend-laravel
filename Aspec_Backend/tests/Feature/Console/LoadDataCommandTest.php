<?php

namespace Tests\Feature\Console;

use App\Enums\InactiveReason;
use App\Enums\SubscriptionState;
use App\Jobs\AnnounceNewEventJob;
use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\EventSeries;
use App\Models\MemberProfile;
use App\Models\Review;
use App\Models\User;
use App\Notifications\NewEventAnnouncementNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Database\Seeders\LoadSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;
use Throwable;

/**
 * php artisan aspec:load-data gera dados de carga (contas carga.*, eventos "Carga: ") para medir o
 * desempenho muito acima do volume real. Os testes usam um volume mínimo (--members=20) e verificam
 * as contagens, as regras de integridade, a ausência de efeitos externos e a limpeza sem tocar na demo.
 */
class LoadDataCommandTest extends TestCase
{
    use RefreshDatabase;

    private const MEMBERS = 20;

    /** Tabelas geradas pelo seeder de carga (as chaves de LoadSeeder::volumes()). */
    private const TABLES = [
        'users',
        'member_profiles',
        'business_hours',
        'social_links',
        'portfolios',
        'event_series',
        'event_instances',
        'event_registrations',
        'reviews',
        'review_reports',
        'prayer_cards',
        'subscriptions',
        'subscription_items',
        'invoices',
        'account_anonymizations',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.media_disk'));
        Storage::fake('local');
    }

    private function loadData(array $options = []): void
    {
        $this->artisan('aspec:load-data', ['--members' => self::MEMBERS, ...$options])->assertSuccessful();
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    private function loadUsers()
    {
        return DB::table('users')->where('email', 'like', 'carga.%@aspec.test');
    }

    private function lookupId(string $table, string $name): string
    {
        return DB::table($table)->where('name', $name)->value('id');
    }

    private function activeStatusId(): string
    {
        return $this->lookupId('account_statuses', 'Active');
    }

    #[Test]
    public function volumes_cover_every_generated_table(): void
    {
        $volumes = LoadSeeder::volumes(self::MEMBERS);

        $this->assertEqualsCanonicalizing(self::TABLES, array_keys($volumes));
        $this->assertSame(0, $volumes['account_anonymizations']);
        $this->assertGreaterThan(self::MEMBERS, $volumes['users']);
        $this->assertSame($volumes['users'], $volumes['member_profiles']);
    }

    #[Test]
    public function table_counts_match_the_volumes_for_the_requested_members(): void
    {
        $this->loadData();

        $volumes = LoadSeeder::volumes(self::MEMBERS);
        $counts = $this->tableCounts();
        ksort($volumes);
        ksort($counts);

        $this->assertSame($volumes, $counts);
    }

    #[Test]
    public function every_account_is_a_load_account_with_the_demo_password(): void
    {
        $this->loadData();

        $this->assertSame(0, DB::table('users')->where('email', 'not like', 'carga.%@aspec.test')->count());
        $this->assertSame(1, DB::table('users')->distinct()->count('password'));

        $user = User::where('email', 'like', 'carga.%@aspec.test')->firstOrFail();
        $this->assertTrue(Hash::check(DemoSeeder::PASSWORD, $user->password));
    }

    #[Test]
    public function load_events_are_named_with_the_load_prefix(): void
    {
        $this->loadData();

        $this->assertGreaterThan(0, DB::table('event_instances')->count());
        $this->assertSame(0, DB::table('event_instances')->where('name', 'not like', 'Carga: %')->count());
    }

    #[Test]
    public function account_states_and_inactive_reasons_are_all_present(): void
    {
        $this->loadData();

        $byStatus = $this->loadUsers()
            ->join('account_statuses', 'account_statuses.id', '=', 'users.account_status_id')
            ->selectRaw('account_statuses.name as status, count(*) as total')
            ->groupBy('account_statuses.name')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total);

        $this->assertSame(self::MEMBERS, $byStatus->get('Active'));
        $this->assertGreaterThanOrEqual(1, $byStatus->get('Pending', 0));
        $this->assertGreaterThanOrEqual(1, $byStatus->get('Approved', 0));
        $this->assertGreaterThanOrEqual(3, $byStatus->get('Inactive', 0));

        $inactiveId = $this->lookupId('account_statuses', 'Inactive');

        foreach ([InactiveReason::Unpaid, InactiveReason::Cancelled, InactiveReason::Blocked] as $reason) {
            $this->assertGreaterThanOrEqual(1, $this->loadUsers()
                ->where('account_status_id', $inactiveId)
                ->where('inactive_reason', $reason->value)
                ->count(), $reason->value);
        }

        $this->assertSame(0, $this->loadUsers()
            ->where('account_status_id', $inactiveId)
            ->where(fn ($query) => $query->whereNull('inactive_reason')
                ->orWhereNotIn('inactive_reason', [InactiveReason::Unpaid->value, InactiveReason::Cancelled->value, InactiveReason::Blocked->value]))
            ->count());
        $this->assertSame(0, $this->loadUsers()
            ->where('account_status_id', '!=', $inactiveId)
            ->whereNotNull('inactive_reason')
            ->count());
    }

    #[Test]
    public function admins_are_active_load_accounts_and_nobody_is_subscription_exempt(): void
    {
        $this->loadData();

        $adminRoleId = $this->lookupId('roles', 'Admin');

        $this->assertSame(1, DB::table('users')->where('role_id', $adminRoleId)->count());
        $this->assertDatabaseHas('users', [
            'email' => 'carga.admin01@aspec.test',
            'role_id' => $adminRoleId,
            'account_status_id' => $this->activeStatusId(),
        ]);
        $this->assertSame(0, DB::table('users')->where('subscription_exempt', true)->count());
    }

    #[Test]
    public function subscription_state_counts_include_trial_active_and_grace(): void
    {
        $this->loadData();

        $counts = User::subscriptionStateCounts(User::query()->where('email', 'like', 'carga.%@aspec.test'));

        $this->assertGreaterThanOrEqual(1, $counts[SubscriptionState::Trial->value]);
        $this->assertGreaterThanOrEqual(1, $counts[SubscriptionState::Active->value]);
        $this->assertGreaterThanOrEqual(1, $counts[SubscriptionState::Grace->value]);
        $this->assertGreaterThanOrEqual(1, $counts[SubscriptionState::Pending->value]);
        $this->assertGreaterThanOrEqual(1, $counts[SubscriptionState::AwaitingActivation->value]);
        $this->assertGreaterThanOrEqual(1, $counts[SubscriptionState::Blocked->value]);
        $this->assertGreaterThanOrEqual(2, $counts[SubscriptionState::Lapsed->value]);
        $this->assertSame(0, $counts[SubscriptionState::Exempt->value]);
        $this->assertSame(0, $counts[SubscriptionState::Inactive->value]);
        $this->assertSame($this->loadUsers()->count(), array_sum($counts));
    }

    #[Test]
    public function active_accounts_have_an_ongoing_subscription_and_inactive_ones_an_ended_one(): void
    {
        $this->loadData();

        $users = User::where('email', 'like', 'carga.%@aspec.test')->with('accountStatus')->get();

        foreach ($users->filter(fn (User $user) => $user->accountStatus->name === 'Active') as $user) {
            $this->assertTrue($user->hasOngoingSubscription(), $user->email);
            $this->assertSame(1, DB::table('subscriptions')->where('user_id', $user->id)->count(), $user->email);
        }

        foreach ($users->filter(fn (User $user) => $user->accountStatus->name === 'Inactive') as $user) {
            $this->assertFalse($user->hasOngoingSubscription(), $user->email);
        }

        $this->assertSame(0, DB::table('subscriptions')->where('stripe_id', 'not like', 'sub_load_%')->count());
        $this->assertSame(0, DB::table('subscription_items')->where('stripe_id', 'not like', 'si_load_%')->count());
        $this->assertSame(0, $this->loadUsers()->whereNotNull('grace_ends_at')->where('grace_ends_at', '<=', now())->count());
    }

    #[Test]
    public function invoices_are_issued_demo_invoices_with_the_load_number(): void
    {
        $this->loadData();

        $invoices = DB::table('invoices')->get();

        $this->assertNotEmpty($invoices);

        foreach ($invoices as $invoice) {
            $this->assertMatchesRegularExpression('/^CARGA-\d{4}-\d{4,}$/', $invoice->number);
            $this->assertStringStartsWith('in_load_', $invoice->stripe_invoice_id);
            $this->assertSame('demo', $invoice->provider);
            $this->assertSame('issued', $invoice->status);
            $this->assertNull($invoice->pdf_url);
        }
    }

    #[Test]
    public function reviews_have_no_repeated_pairs_no_self_reviews_and_active_authors(): void
    {
        $this->loadData();

        $this->assertGreaterThan(0, DB::table('reviews')->count());
        $this->assertSame(0, DB::table('reviews')->whereColumn('reviewer_profile_id', 'reviewed_profile_id')->count());
        $this->assertSame(0, DB::table('reviews')
            ->select('reviewer_profile_id', 'reviewed_profile_id')
            ->groupBy('reviewer_profile_id', 'reviewed_profile_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count());
        $this->assertSame(0, DB::table('reviews')
            ->join('member_profiles', 'member_profiles.id', '=', 'reviews.reviewer_profile_id')
            ->join('users', 'users.id', '=', 'member_profiles.user_id')
            ->where('users.account_status_id', '!=', $this->activeStatusId())
            ->count());
    }

    #[Test]
    public function registrations_respect_capacity_active_accounts_and_the_deadline(): void
    {
        $this->loadData();

        $this->assertGreaterThan(0, DB::table('event_registrations')->count());

        $overbooked = DB::table('event_instances')
            ->leftJoin('event_registrations', 'event_registrations.event_instance_id', '=', 'event_instances.id')
            ->select('event_instances.id', 'event_instances.max_attendees')
            ->groupBy('event_instances.id', 'event_instances.max_attendees')
            ->havingRaw('count(event_registrations.id) > event_instances.max_attendees')
            ->get();
        $this->assertCount(0, $overbooked);

        $this->assertSame(0, DB::table('event_registrations')
            ->join('users', 'users.id', '=', 'event_registrations.user_id')
            ->where('users.account_status_id', '!=', $this->activeStatusId())
            ->count());

        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $registrations = DB::table('event_registrations')
            ->join('event_instances', 'event_instances.id', '=', 'event_registrations.event_instance_id')
            ->select('event_registrations.created_at', 'event_instances.registration_deadline')
            ->get();

        foreach ($registrations as $registration) {
            // O prazo está guardado na hora de Lisboa e o created_at em UTC.
            $this->assertTrue(
                Carbon::parse($registration->created_at, 'UTC')
                    ->lessThanOrEqualTo(Carbon::parse($registration->registration_deadline, $timezone)),
                "{$registration->created_at} > {$registration->registration_deadline}",
            );
        }
    }

    #[Test]
    public function prayer_card_authors_are_registered_and_never_their_own_recipient(): void
    {
        $this->loadData();

        $this->assertGreaterThan(0, DB::table('prayer_cards')->count());

        $this->assertSame(0, DB::table('prayer_cards')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('event_registrations')
                ->whereColumn('event_registrations.event_instance_id', 'prayer_cards.event_instance_id')
                ->whereColumn('event_registrations.user_id', 'prayer_cards.author_user_id'))
            ->count());
        $this->assertSame(0, DB::table('prayer_cards')->whereColumn('recipient_user_id', 'author_user_id')->count());
        $this->assertSame(0, DB::table('prayer_cards')
            ->join('users', 'users.id', '=', 'prayer_cards.author_user_id')
            ->where('users.account_status_id', '!=', $this->activeStatusId())
            ->count());
    }

    #[Test]
    public function generating_load_data_has_no_external_side_effects(): void
    {
        Notification::fake();
        Mail::fake();
        Queue::fake();
        Http::fake()->preventStrayRequests();

        $this->loadData();

        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertEmpty(Storage::disk(config('filesystems.media_disk'))->allFiles());
        $this->assertEmpty(Storage::disk('local')->allFiles());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public static function refusedEnvironments(): array
    {
        return [
            'production' => ['production'],
            'staging' => ['staging'],
        ];
    }

    #[Test]
    #[DataProvider('refusedEnvironments')]
    public function refuses_to_run_outside_local_and_testing(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);

        try {
            $exitCode = $this->artisan('aspec:load-data', ['--members' => self::MEMBERS])->run();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertNotSame(0, $exitCode);
        $this->assertSame(array_fill_keys(self::TABLES, 0), $this->tableCounts());
    }

    #[Test]
    #[DataProvider('refusedEnvironments')]
    public function clean_also_refuses_to_run_outside_local_and_testing(string $environment): void
    {
        $this->loadData();
        $before = $this->tableCounts();

        $this->app->detectEnvironment(fn () => $environment);

        try {
            $exitCode = $this->artisan('aspec:load-data', ['--clean' => true])->run();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertNotSame(0, $exitCode);
        $this->assertSame($before, $this->tableCounts());
    }

    #[Test]
    public function running_twice_resets_the_data_to_the_same_counts(): void
    {
        $this->loadData();
        $first = $this->tableCounts();

        $this->loadData();

        $this->assertSame($first, $this->tableCounts());
    }

    #[Test]
    public function clean_removes_all_load_data(): void
    {
        $this->loadData();

        $this->loadData(['--clean' => true]);

        $this->assertSame(array_fill_keys(self::TABLES, 0), $this->tableCounts());
    }

    #[Test]
    public function clean_keeps_the_demo_data_even_when_mixed_with_load_data(): void
    {
        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->assertSuccessful();
        $demoCounts = $this->tableCounts();
        $demoUsers = DB::table('users')->where('email', 'like', 'demo.%')->pluck('id')->sort()->values()->all();

        $this->loadData();
        $this->mixDemoAndLoadData();

        $this->loadData(['--clean' => true]);

        $this->assertSame($demoCounts, $this->tableCounts());
        $this->assertSame($demoUsers, DB::table('users')->where('email', 'like', 'demo.%')->pluck('id')->sort()->values()->all());
        $this->assertSame(0, DB::table('users')->where('email', 'like', 'carga.%')->count());
        $this->assertSame(0, DB::table('event_instances')->where('name', 'like', 'Carga: %')->count());
        $this->assertSame(0, DB::table('subscriptions')->where('stripe_id', 'like', 'sub_load_%')->count());
        $this->assertSame(0, DB::table('subscription_items')->where('stripe_id', 'like', 'si_load_%')->count());
        $this->assertSame(0, DB::table('invoices')->where('stripe_invoice_id', 'like', 'in_load_%')->count());
    }

    #[Test]
    public function load_data_does_not_touch_the_demo_data(): void
    {
        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->assertSuccessful();
        $demoCounts = $this->tableCounts();

        $this->loadData();

        $volumes = LoadSeeder::volumes(self::MEMBERS);
        foreach (self::TABLES as $table) {
            $this->assertSame($demoCounts[$table] + $volumes[$table], DB::table($table)->count(), $table);
        }
    }

    /**
     * Testes manuais misturados: demo.membro inscrito num evento de carga, review demo sobre um
     * perfil de carga, e o contrário (conta de carga inscrita num evento demo e com review sobre
     * um perfil demo). Sem limpar os "dois lados", as FK restrict impediam apagar as contas.
     */
    private function mixDemoAndLoadData(): void
    {
        $demoUser = User::where('email', 'demo.membro@aspec.test')->with('memberProfile')->firstOrFail();
        $loadUser = User::where('email', 'like', 'carga.%@aspec.test')
            ->where('account_status_id', $this->activeStatusId())
            ->whereHas('memberProfile')
            ->with('memberProfile')
            ->orderBy('email')
            ->firstOrFail();

        $loadEventId = DB::table('event_instances')->where('name', 'like', 'Carga: %')->value('id');
        $demoEventId = DB::table('event_instances')->where('name', 'not like', 'Carga: %')->value('id');

        DB::table('event_registrations')->insert([
            ['id' => (string) Str::uuid7(), 'event_instance_id' => $loadEventId, 'user_id' => $demoUser->id, 'created_at' => now()],
            ['id' => (string) Str::uuid7(), 'event_instance_id' => $demoEventId, 'user_id' => $loadUser->id, 'created_at' => now()],
        ]);

        Review::factory()->create([
            'reviewer_profile_id' => $demoUser->memberProfile->id,
            'reviewed_profile_id' => $loadUser->memberProfile->id,
        ]);
        Review::factory()->create([
            'reviewer_profile_id' => $loadUser->memberProfile->id,
            'reviewed_profile_id' => $demoUser->memberProfile->id,
        ]);
    }

    private function memberWithProfile(): User
    {
        $user = User::factory()->create();
        MemberProfile::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    private function openOneOffEvent(string $name = 'Evento aberto'): EventInstance
    {
        $eventDate = now(config('app.event_timezone', 'Europe/Lisbon'))->addDays(3);

        return EventInstance::factory()->create([
            'name' => $name,
            'event_date' => $eventDate->toDateString(),
            'registration_deadline' => $eventDate->copy()->subDay()->setTime(23, 59)->format('Y-m-d H:i:s'),
            'max_attendees' => 50,
        ]);
    }

    #[Test]
    public function load_accounts_have_event_emails_turned_off(): void
    {
        $this->loadData();

        $this->assertGreaterThan(0, $this->loadUsers()->count());
        $this->assertSame(0, $this->loadUsers()
            ->where(fn ($query) => $query->where('notify_new_events', true)->orWhere('notify_weekly_agenda', true))
            ->count());
    }

    #[Test]
    public function weekly_agenda_never_emails_load_accounts(): void
    {
        config(['mail.default' => 'array']);
        $this->loadData();
        $realMember = $this->memberWithProfile();
        $this->openOneOffEvent();

        $this->artisan('events:send-weekly-agenda')->assertSuccessful();

        $recipients = collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
            ->flatMap(fn (SentMessage $message) => array_map(fn ($address) => $address->getAddress(), $message->getEnvelope()->getRecipients()))
            ->all();

        $this->assertContains($realMember->email, $recipients);
        $this->assertEmpty(array_filter($recipients, fn (string $email) => str_starts_with($email, 'carga.')));
        $this->assertSame(0, $this->loadUsers()->whereNotNull('weekly_agenda_sent_at')->count());
    }

    #[Test]
    public function new_event_announcement_never_targets_load_accounts(): void
    {
        Notification::fake();
        $this->loadData();
        $realMember = $this->memberWithProfile();
        $event = $this->openOneOffEvent();

        AnnounceNewEventJob::dispatchSync($event->id);

        Notification::assertSentTo($realMember, NewEventAnnouncementNotification::class);
        Notification::assertNothingSentTo(User::where('email', 'like', 'carga.%@aspec.test')->get());
    }

    #[Test]
    public function clean_keeps_a_real_event_whose_name_starts_with_the_load_prefix(): void
    {
        $this->loadData();
        $member = $this->memberWithProfile();
        $event = $this->openOneOffEvent('Carga: teste manual');
        EventRegistration::create(['event_instance_id' => $event->id, 'user_id' => $member->id]);

        $this->loadData(['--clean' => true]);

        $this->assertDatabaseHas('event_instances', ['id' => $event->id]);
        $this->assertDatabaseHas('event_registrations', ['event_instance_id' => $event->id, 'user_id' => $member->id]);
        $this->assertSame(1, DB::table('event_instances')->count());
    }

    #[Test]
    public function clean_removes_an_orphan_load_series_but_keeps_other_series_without_sessions(): void
    {
        $this->loadData();

        $loadSeriesId = DB::table('event_instances')->where('name', 'like', 'Carga: %')->whereNotNull('series_id')->value('series_id');
        $orphan = (array) DB::table('event_series')->where('id', $loadSeriesId)->first();
        $orphan['id'] = (string) Str::uuid7();
        DB::table('event_series')->insert($orphan);

        $other = EventSeries::create([
            'frequency' => 'weekly',
            'ends_on' => now()->addMonths(2)->toDateString(),
            'registration_deadline_days_before' => 1,
        ]);

        $this->loadData(['--clean' => true]);

        $this->assertDatabaseMissing('event_series', ['id' => $orphan['id']]);
        $this->assertDatabaseHas('event_series', ['id' => $other->id]);
        $this->assertSame(1, DB::table('event_series')->count());
    }

    public static function loadDataInvocations(): array
    {
        return [
            'load-data' => ['aspec:load-data', ['--members' => self::MEMBERS]],
            'load-data --clean' => ['aspec:load-data', ['--clean' => true]],
            'db:seed LoadSeeder' => ['db:seed', ['--class' => LoadSeeder::class, '--force' => true]],
        ];
    }

    /**
     * Configurações da ligação mysql que apontam para fora desta máquina. A porta 9 e o host que não
     * resolve garantem que, se a guarda falhar, a ligação falha logo em vez de chegar a uma base de dados.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function remoteDatabaseConfigs(): array
    {
        return [
            'host remoto' => [['host' => 'remote.example.com']],
            'url remoto com host local' => [['host' => '127.0.0.1', 'url' => 'mysql://u:p@remote.example.com:9/aspec']],
            'read remoto com host local' => [['host' => '127.0.0.1', 'read' => ['host' => ['remote.example.com']], 'write' => ['host' => ['127.0.0.1']]]],
        ];
    }

    public static function loadDataInvocationsWithRemoteDatabases(): array
    {
        $cases = [];

        foreach (self::loadDataInvocations() as $invocation => [$command, $parameters]) {
            foreach (self::remoteDatabaseConfigs() as $remote => [$connection]) {
                $cases["{$invocation}, {$remote}"] = [$command, $parameters, $connection];
            }
        }

        return $cases;
    }

    /**
     * Aponta a ligação por omissão para o mysql com a configuração dada (sem abrir a ligação).
     */
    private function useMysqlConnection(array $connection): void
    {
        config(collect(['port' => 9, 'options' => [PDO::ATTR_TIMEOUT => 1], ...$connection])
            ->mapWithKeys(fn ($value, string $key) => ["database.connections.mysql.{$key}" => $value])
            ->put('database.default', 'mysql')
            ->all());
    }

    #[Test]
    public function a_local_database_url_is_not_refused(): void
    {
        $sqlite = config('database.default');
        $this->useMysqlConnection(['host' => '127.0.0.1', 'url' => 'mysql://u:p@127.0.0.1:3306/aspec']);

        try {
            $refusal = LoadSeeder::refusal();
        } finally {
            config(['database.default' => $sqlite]);
        }

        $this->assertNull($refusal);
    }

    /**
     * A guarda tem de recusar antes de abrir a ligação: se o comando tentar ligar-se ao host remoto,
     * a exceção é convertida numa falha do teste (nunca chega a haver rede: o host não resolve).
     */
    #[Test]
    #[DataProvider('loadDataInvocationsWithRemoteDatabases')]
    public function refuses_a_remote_database_host_even_in_testing(string $command, array $parameters, array $connection): void
    {
        $sqlite = config('database.default');
        $this->useMysqlConnection($connection);

        try {
            $exitCode = $this->artisan($command, $parameters)->run();
        } catch (Throwable $exception) {
            $this->fail('O comando tentou usar a base de dados remota: '.$exception->getMessage());
        } finally {
            config(['database.default' => $sqlite]);
            DB::purge('mysql');
        }

        if ($command !== 'db:seed') {
            $this->assertNotSame(0, $exitCode);
        }

        $this->assertSame(array_fill_keys(self::TABLES, 0), $this->tableCounts());
    }

    #[Test]
    #[DataProvider('refusedEnvironments')]
    public function load_seeder_refuses_to_run_outside_local_and_testing(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);

        try {
            $this->artisan('db:seed', ['--class' => LoadSeeder::class, '--force' => true])->run();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertSame(array_fill_keys(self::TABLES, 0), $this->tableCounts());
    }

    #[Test]
    public function times_missing_from_the_lisbon_clock_on_the_dst_change_move_forward_one_hour(): void
    {
        $timestamp = new \ReflectionMethod(LoadSeeder::class, 'timestamp');
        $seeder = new LoadSeeder;

        $this->assertSame('2025-03-30 02:44:52', $timestamp->invoke($seeder, CarbonImmutable::parse('2025-03-30 01:44:52', 'UTC')));
        $this->assertSame('2025-03-30 00:59:59', $timestamp->invoke($seeder, CarbonImmutable::parse('2025-03-30 00:59:59', 'UTC')));
        $this->assertSame('2025-10-26 01:30:00', $timestamp->invoke($seeder, CarbonImmutable::parse('2025-10-26 01:30:00', 'UTC')));
        $this->assertNull($timestamp->invoke($seeder, null));
    }
}
