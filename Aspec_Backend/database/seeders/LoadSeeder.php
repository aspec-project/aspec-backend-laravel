<?php

namespace Database\Seeders;

use App\Enums\InactiveReason;
use App\Enums\SubscriptionInterval;
use App\Models\ReviewReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Dados de carga para medir o desempenho muito acima do volume real (a ASPEC prevê ~500 membros):
 *
 * contas carga.*@aspec.test em todos os estados, perfis, eventos "Carga: ", inscrições, avaliações,
 * cartões de oração, subscrições e faturas. Só para o MySQL local; nunca chama o Stripe, a
 * InvoiceExpress, o R2 nem envia emails (só DB::table, sem eventos de modelo nem jobs). As contas
 * de carga ficam com os emails de eventos desligados, para o anúncio de um evento novo ou a agenda
 * semanal corridos à mão nunca enviarem milhares de emails pelo SMTP real do .env.
 *
 * php artisan db:seed --class=LoadSeeder corre o nível realista; o comando aspec:load-data escolhe
 * o nível e também limpa. Os dados de carga e os da demonstração são independentes.
 */
class LoadSeeder extends Seeder
{
    /** Membros ativos por nível: o dobro dos ~500 previstos e 20 vezes esse valor. */
    public const LEVELS = ['realistic' => 1000, 'stress' => 10000];

    /** Mínimo de membros ativos para haver pelo menos uma conta em cada estado. */
    public const MIN_MEMBERS = 10;

    public const EMAIL_PATTERN = 'carga.%@aspec.test';

    public const ADMIN_EMAIL = 'carga.admin01@aspec.test';

    public const EVENT_PREFIX = 'Carga: ';

    public const BIG_EVENT_NAME = 'Carga: Grande Conferência ASPEC';

    /**
     * Marca dos eventos de carga (organizer_notes): a limpeza exige o prefixo do nome e esta marca, para
     * nunca apagar um evento real cujo nome comece por "Carga: ".
     */
    public const EVENT_MARKER = '[aspec:load-data] Evento de carga gerado automaticamente.';

    /**
     * created_at fixo das séries de carga (no passado distante, que o backoffice nunca grava): identifica
     * a série pela própria linha, mesmo que tenha ficado sem sessões.
     */
    public const SERIES_CREATED_AT = '2000-01-01 00:00:00';

    /** Hosts aceites para a base de dados (só a máquina local). */
    private const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    /** Termo presente em ~10% das descrições, para a pesquisa do diretório ter resultados. */
    public const SEARCH_TERM = 'consultoria';

    private const SEED = 453;

    private const BATCH_SIZE = 500;

    private const CLEAN_BATCH_SIZE = 1000;

    /** Plano gerado por plan() para este volume. */
    private array $plan = [];

    /** Por conta do plano: ids, datas, conteúdo do perfil e datas da subscrição. */
    private array $accounts = [];

    /** Timestamps de criação dos membros ativos (índices 0..N-1 do plano). */
    private array $activeCreatedAt = [];

    /** Índices dos membros ativos, para a amostragem sem repetição. */
    private array $activePool = [];

    /** Ids das subscrições (bigint do Cashier) por stripe_id, lidos depois de cada lote. */
    private array $subscriptionIds = [];

    /** Inscritos (índices de membros ativos) dos eventos com cartões de oração, por índice do evento. */
    private array $cardRegistrants = [];

    /** Ids dos eventos por índice do plano. */
    private array $eventIds = [];

    /** Data (Lisboa), hora de início e prazo de inscrição (Lisboa) dos eventos, por índice do plano. */
    private array $eventDates = [];

    private array $eventStartHours = [];

    private array $eventDeadlines = [];

    private array $lookupIds = [];

    private CarbonImmutable $now;

    private CarbonImmutable $today;

    /**
     * Nível realista, depois de apagar os dados de carga anteriores. Recusa correr fora de local/testing.
     */
    public function run(): void
    {
        $refusal = self::refusal();

        if ($refusal !== null) {
            $this->command?->error($refusal);

            return;
        }

        $this->clean();
        $counts = $this->generate(self::LEVELS['realistic']);

        $this->command?->table(['Tabela', 'Linhas'], collect($counts)->map(fn (int $rows, string $table) => [$table, $rows])->values()->all());
    }

    /**
     * Motivo para recusar os dados de carga (null = pode correr): só em local/testing e só com SQLite
     * ou uma base de dados nesta máquina (host e, se existir, o host do DB_URL; ligações read/write são
     * sempre recusadas). Lê apenas a configuração, sem abrir a ligação, para nunca
     * tocar numa base de dados remota (ex.: o TiDB do deploy).
     */
    public static function refusal(): ?string
    {
        if (! app()->environment('local', 'testing')) {
            return 'Os dados de carga só podem ser usados em local ou testing (nunca em produção nem staging).';
        }

        $connection = (array) config('database.connections.'.config('database.default'), []);

        if (($connection['driver'] ?? null) === 'sqlite') {
            return null;
        }

        $url = (string) ($connection['url'] ?? '');
        $urlHost = $url === '' ? null : parse_url($url, PHP_URL_HOST);
        $remote = ! in_array($connection['host'] ?? null, self::LOCAL_HOSTS, true)
            || ($url !== '' && ! (is_string($urlHost) && in_array(trim($urlHost, '[]'), self::LOCAL_HOSTS, true)))
            // Ligações com "read"/"write" têm hosts próprios: recusadas sempre, por simplicidade.
            || array_key_exists('read', $connection)
            || array_key_exists('write', $connection);

        return $remote
            ? 'Os dados de carga só podem ser usados com uma base de dados local (127.0.0.1, localhost ou ::1, sem DB_URL remoto nem ligações read/write).'
            : null;
    }

    /**
     * Número exato de linhas que generate() cria em cada tabela para N membros ativos. Vem do mesmo
     * plano determinístico (semente fixa), por isso os testes podem comparar as contagens.
     *
     * @return array<string, int>
     */
    public static function volumes(int $activeMembers): array
    {
        $plan = self::plan($activeMembers);
        $subscribed = count(array_filter($plan['accounts'], fn (array $account) => $account['subscription'] !== null));
        $reviews = array_sum($plan['reviews']);

        return [
            'users' => count($plan['accounts']),
            'member_profiles' => count($plan['accounts']),
            'business_hours' => array_sum(array_map(fn (array $account) => [0, 5, 6][$account['hours']], $plan['accounts'])),
            'social_links' => array_sum(array_column($plan['accounts'], 'social')),
            'portfolios' => array_sum(array_column($plan['accounts'], 'portfolio')),
            'event_series' => count($plan['series']),
            'event_instances' => count($plan['events']),
            'event_registrations' => array_sum(array_column($plan['events'], 'registrations')),
            'reviews' => $reviews,
            'review_reports' => self::flaggedReviews($reviews) + self::memberReports($reviews),
            'prayer_cards' => array_sum(array_column($plan['events'], 'prayer_cards')),
            'subscriptions' => $subscribed,
            'subscription_items' => $subscribed,
            'invoices' => array_sum(array_column($plan['accounts'], 'invoices')),
            'account_anonymizations' => 0,
        ];
    }

    /**
     * Cria os dados de carga para N membros ativos, tabela a tabela pela ordem das chaves estrangeiras,
     * em lotes de 500 e numa transação por tabela. Não apaga nada: chamar clean() antes.
     *
     * @return array<string, int> Linhas criadas por tabela.
     *
     * @throws RuntimeException Se faltarem plataformas sociais para os links gerados.
     */
    public function generate(int $activeMembers): array
    {
        if ($this->lookupsMissing()) {
            $this->call(DatabaseSeeder::class);
        }

        mt_srand(self::SEED);
        fake('pt_PT')->seed(self::SEED);

        try {
            return $this->generateTables($activeMembers);
        } finally {
            mt_srand();
            fake('pt_PT')->seed();
        }
    }

    /**
     * Apaga todos os dados de carga (contas carga.*, eventos "Carga: " e tudo o que depende deles),
     * incluindo o que testes manuais misturaram com a demonstração: avaliações e inscrições em que só
     * um dos lados é de carga. Nunca apaga ficheiros.
     */
    public function clean(): void
    {
        DB::transaction(function () {
            $users = DB::table('users')->where('email', 'like', self::EMAIL_PATTERN)->pluck('email', 'id')->all();
            $userIds = array_keys($users);
            $profileIds = $this->chunked($userIds, fn (array $ids) => DB::table('member_profiles')->whereIn('user_id', $ids)->pluck('id')->all());
            $eventIds = DB::table('event_instances')
                ->where('name', 'like', self::EVENT_PREFIX.'%')
                ->where('organizer_notes', self::EVENT_MARKER)
                ->pluck('id')
                ->all();

            $this->chunked($profileIds, fn (array $ids) => DB::table('reviews')
                ->whereIn('reviewer_profile_id', $ids)
                ->orWhereIn('reviewed_profile_id', $ids)
                ->delete());

            $this->chunked($eventIds, fn (array $ids) => DB::table('prayer_cards')->whereIn('event_instance_id', $ids)->delete());
            $this->chunked($userIds, fn (array $ids) => DB::table('prayer_cards')
                ->whereIn('author_user_id', $ids)
                ->orWhereIn('recipient_user_id', $ids)
                ->delete());

            $this->chunked($eventIds, fn (array $ids) => DB::table('event_registrations')->whereIn('event_instance_id', $ids)->delete());
            $this->chunked($userIds, fn (array $ids) => DB::table('event_registrations')->whereIn('user_id', $ids)->delete());

            $this->chunked($eventIds, fn (array $ids) => DB::table('event_instances')->whereIn('id', $ids)->delete());
            DB::table('event_series')
                ->where('created_at', self::SERIES_CREATED_AT)
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                    ->from('event_instances')
                    ->whereColumn('event_instances.series_id', 'event_series.id'))
                ->delete();

            $this->chunked($userIds, function (array $ids) {
                $subscriptionIds = DB::table('subscriptions')->whereIn('user_id', $ids)->pluck('id')->all();

                DB::table('invoices')->whereIn('user_id', $ids)->delete();
                DB::table('subscription_items')->whereIn('subscription_id', $subscriptionIds)->delete();
                DB::table('subscriptions')->whereIn('id', $subscriptionIds)->delete();
            });

            $this->chunked($userIds, function (array $ids) use ($users) {
                DB::table('sessions')->whereIn('user_id', $ids)->delete();
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $ids)->delete();
                DB::table('password_reset_tokens')->whereIn('email', array_map(fn (string $id) => $users[$id], $ids))->delete();
            });

            $this->chunked($userIds, fn (array $ids) => DB::table('member_profiles')->whereIn('user_id', $ids)->delete());
            $this->chunked($userIds, fn (array $ids) => DB::table('users')->whereIn('id', $ids)->delete());
        });
    }

    /**
     * Plano determinístico (gerador próprio com semente fixa, independente do mt_rand e do Faker): o
     * que decide quantas linhas há em cada tabela. O conteúdo (nomes, datas, quem se inscreve) é
     * escolhido depois, na geração, sem mudar as contagens.
     *
     * @return array{accounts: list<array>, series: list<array>, events: list<array>, reviews: list<int>}
     */
    private static function plan(int $members): array
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $interval = SubscriptionInterval::configured();
        $maxPeriods = $interval === SubscriptionInterval::Year ? 3 : 36;
        $maxInvoices = $interval === SubscriptionInterval::Year ? 3 : 12;

        $admins = max(1, intdiv($members, 300));
        $trial = max(1, (int) round($members * 0.15));
        $grace = max(1, (int) round($members * 0.02));
        $scheduled = max(1, (int) round($members * 0.05));

        $kinds = $random->shuffleArray([
            ...array_fill(0, $trial, 'trial'),
            ...array_fill(0, $grace, 'grace'),
            ...array_fill(0, $scheduled, 'scheduled'),
            ...array_fill(0, $members - $trial - $grace - $scheduled, 'paid'),
        ]);

        $inactive = max(3, (int) round($members * 0.10));
        $blocked = max(1, (int) round($inactive * 0.2));
        $cancelled = max(1, (int) round($inactive * 0.3));

        $states = [
            ...array_map(fn (string $kind) => ['Active', null, $kind], $kinds),
            ...array_fill(0, max(1, (int) round($members * 0.05)), ['Pending', null, null]),
            ...array_fill(0, max(1, (int) round($members * 0.01)), ['Approved', null, null]),
            ...array_fill(0, $inactive - $blocked - $cancelled, ['Inactive', InactiveReason::Unpaid, 'unpaid']),
            ...array_fill(0, $cancelled, ['Inactive', InactiveReason::Cancelled, 'cancelled']),
            ...array_fill(0, $blocked, ['Inactive', InactiveReason::Blocked, 'blocked']),
        ];

        $accounts = [];
        $memberNumber = 0;

        foreach ($states as $index => [$status, $reason, $subscription]) {
            $isAdmin = $index < $admins;
            $periods = match ($subscription) {
                null, 'trial' => 0,
                'paid', 'scheduled', 'grace' => min($random->getInt(1, $maxPeriods), $random->getInt(1, $maxPeriods)),
                'unpaid', 'blocked' => min($random->getInt(0, 2), $maxPeriods),
                'cancelled' => min($random->getInt(1, 3), $maxPeriods),
            };
            $hasHours = $random->getInt(1, 100) <= 70;

            $accounts[] = [
                'email' => $isAdmin
                    ? sprintf('carga.admin%02d@aspec.test', $index + 1)
                    : sprintf('carga.%04d@aspec.test', ++$memberNumber),
                'admin' => $isAdmin,
                'status' => $status,
                'reason' => $reason,
                'subscription' => $subscription,
                'periods' => $periods,
                'invoices' => min($periods, $maxInvoices),
                'hours' => $hasHours ? ($random->getInt(1, 100) <= 30 ? 2 : 1) : 0,
                'social' => $random->getInt(0, 4),
                'portfolio' => $random->getInt(1, 2) === 1 ? $random->getInt(1, 10) : 0,
            ];
        }

        $oneOff = max(3, (int) round($members * 0.3));
        $past = max(1, (int) round($oneOff * 2 / 3));
        $events = [];

        for ($event = 0; $event < $oneOff; $event++) {
            $isPast = $event < $past;
            $capacity = min($random->getInt(20, 80), $members);

            $events[] = [
                'kind' => $isPast ? 'past' : 'future',
                'day' => $isPast ? -$random->getInt(1, 730) : $random->getInt(1, 180),
                'capacity' => $capacity,
                'registrations' => self::plannedRegistrations($random, $capacity, $isPast),
                'online' => $random->getInt(1, 10) === 1,
                'prayer_cards' => 0,
            ];
        }

        $series = [];

        for ($index = 0; $index < max(1, intdiv($members, 50)); $index++) {
            $series[] = ['frequency' => ['weekly', 'biweekly', 'monthly'][$index % 3]];

            for ($position = 0; $position < 12; $position++) {
                $capacity = min($random->getInt(20, 80), $members);

                $events[] = [
                    'kind' => 'session',
                    'series' => $index,
                    'position' => $position,
                    'capacity' => $capacity,
                    'registrations' => self::plannedRegistrations($random, $capacity, $position < 6),
                    'online' => false,
                    'prayer_cards' => 0,
                ];
            }
        }

        $bigCapacity = min(max(400, intdiv($members, 5)), $members);
        $events[] = ['kind' => 'big', 'capacity' => $bigCapacity, 'registrations' => $bigCapacity, 'online' => false, 'prayer_cards' => 0];

        foreach ((array) $random->pickArrayKeys(range(0, $past - 1), max(1, (int) round($past * 0.15))) as $event) {
            $events[$event]['prayer_cards'] = intdiv($events[$event]['registrations'] * 7, 10);
        }

        $reviews = [];

        for ($member = 0; $member < $members; $member++) {
            $reviews[] = min($random->getInt(0, 40), $members - 1);
        }

        $reviews[$random->getInt(0, $members - 1)] = min(max(500, intdiv($members * 3, 10)), $members - 1);

        return ['accounts' => $accounts, 'series' => $series, 'events' => $events, 'reviews' => $reviews];
    }

    /**
     * Inscrições de um evento: passados 50–100% cheios; futuros 15% cheios e os restantes 0–100%.
     */
    private static function plannedRegistrations(Randomizer $random, int $capacity, bool $past): int
    {
        if ($past) {
            return (int) round($capacity * $random->getInt(50, 100) / 100);
        }

        return $random->getInt(1, 100) <= 15 ? $capacity : (int) round($capacity * $random->getInt(0, 100) / 100);
    }

    /**
     * Estado da avaliação pela posição na geração (4% Flagged, 6% Hidden, 90% Visible): uma regra fixa
     * em vez de sorteio, para o número de sinalizações automáticas ser exato.
     */
    private static function reviewStatus(int $position): string
    {
        return match (true) {
            $position % 50 < 2 => 'Flagged',
            $position % 50 < 5 => 'Hidden',
            default => 'Visible',
        };
    }

    /**
     * Se a avaliação nesta posição tem uma sinalização feita por um membro (1% das avaliações).
     */
    private static function hasMemberReport(int $position): bool
    {
        return $position % 100 === 7;
    }

    private static function flaggedReviews(int $reviews): int
    {
        return intdiv($reviews, 50) * 2 + min($reviews % 50, 2);
    }

    private static function memberReports(int $reviews): int
    {
        return intdiv($reviews, 100) + ($reviews % 100 > 7 ? 1 : 0);
    }

    /**
     * Gera todas as tabelas a partir do plano.
     *
     * @return array<string, int>
     */
    private function generateTables(int $activeMembers): array
    {
        $this->plan = self::plan($activeMembers);
        $this->now = CarbonImmutable::now('UTC')->startOfSecond();
        $this->today = CarbonImmutable::now(config('app.event_timezone', 'Europe/Lisbon'))->startOfDay();
        $this->activePool = range(0, $activeMembers - 1);
        $this->subscriptionIds = [];
        $this->cardRegistrants = [];
        $this->eventIds = [];
        $this->eventDates = [];
        $this->eventStartHours = [];
        $this->eventDeadlines = [];

        if (count($this->lookups('social_platforms')) < 4) {
            throw new RuntimeException('Os dados de carga precisam de pelo menos 4 plataformas sociais.');
        }

        $this->prepareAccounts();

        $counts = [];
        $counts['users'] = $this->insertInBatches('users', $this->userRows());
        $counts['member_profiles'] = $this->insertInBatches('member_profiles', $this->profileRows());
        $counts['business_hours'] = $this->insertInBatches('business_hours', $this->businessHourRows());
        $counts['social_links'] = $this->insertInBatches('social_links', $this->socialLinkRows());
        $counts['portfolios'] = $this->insertInBatches('portfolios', $this->portfolioRows());

        $seriesIds = array_map(fn () => (string) Str::uuid7(), $this->plan['series']);
        // Séries e sessões na mesma transação: uma falha a meio nunca deixa séries sem sessões.
        DB::transaction(function () use ($seriesIds, &$counts) {
            $counts['event_series'] = $this->insertInBatches('event_series', $this->seriesRows($seriesIds));
            $counts['event_instances'] = $this->insertInBatches('event_instances', $this->eventRows($seriesIds));
        });
        $counts['event_registrations'] = $this->insertInBatches('event_registrations', $this->registrationRows());
        $counts['prayer_cards'] = $this->insertInBatches('prayer_cards', $this->prayerCardRows());

        $reports = [];
        $counts['reviews'] = $this->insertInBatches('reviews', $this->reviewRows($reports));
        $counts['review_reports'] = $this->insertInBatches('review_reports', $reports);

        $counts['subscriptions'] = $this->insertInBatches('subscriptions', $this->subscriptionRows(), function (array $batch) {
            $this->subscriptionIds += DB::table('subscriptions')
                ->whereIn('stripe_id', array_column($batch, 'stripe_id'))
                ->pluck('id', 'stripe_id')
                ->all();
        });
        $counts['subscription_items'] = $this->insertInBatches('subscription_items', $this->subscriptionItemRows());
        $counts['invoices'] = $this->insertInBatches('invoices', $this->invoiceRows());
        $counts['account_anonymizations'] = 0;

        return $counts;
    }

    /**
     * Ids, data de criação, dados do perfil e datas da subscrição de cada conta do plano.
     */
    private function prepareAccounts(): void
    {
        $faker = fake('pt_PT');
        $this->accounts = [];
        $this->activeCreatedAt = [];

        foreach ($this->plan['accounts'] as $index => $account) {
            $subscription = $account['subscription'] === null ? null : $this->subscriptionDates($account);
            $createdAt = $subscription !== null
                ? $subscription['created_at']->subDays(mt_rand(1, 14))->subMinutes(mt_rand(0, 1439))
                : $this->now->subDays(mt_rand(0, 29))->subMinutes(mt_rand(0, 1439));

            $this->accounts[$index] = [
                'id' => (string) Str::uuid7(),
                'profile_id' => (string) Str::uuid7(),
                'created_at' => $createdAt,
                'subscription' => $subscription,
                'name' => $faker->name(),
                'business_name' => $faker->company(),
                'address' => $faker->streetAddress(),
                'postal_code' => mt_rand(1000, 9999).'-'.sprintf('%03d', mt_rand(0, 999)),
                'city' => $faker->city(),
            ];

            if ($account['status'] === 'Active') {
                $this->activeCreatedAt[$index] = $createdAt->getTimestamp();
            }
        }
    }

    /**
     * Datas da subscrição de uma conta, relativas a agora e à configuração (trial, carência e
     * periodicidade da quota), como o Stripe as deixaria em cada cenário. Os períodos devolvidos são
     * só os faturados (os mais recentes).
     *
     * @return array{status: string, created_at: CarbonImmutable, trial_ends_at: CarbonImmutable, ends_at: ?CarbonImmutable, grace_ends_at: ?CarbonImmutable, periods: list<array{CarbonImmutable, CarbonImmutable}>}
     */
    private function subscriptionDates(array $account): array
    {
        $kind = $account['subscription'];
        $trialDays = (int) config('subscription.trial_days');
        $graceDays = (int) config('subscription.grace_days');
        $daysInPeriod = SubscriptionInterval::configured() === SubscriptionInterval::Year ? 360 : 27;
        $randomTime = fn (CarbonImmutable $date) => $date->subMinutes(mt_rand(0, 1439));
        $endsAt = null;
        $graceEndsAt = null;
        $paid = [];

        if ($kind === 'trial') {
            $trialEndsAt = $randomTime($this->now->addDays(mt_rand(1, max(1, $trialDays - 1))));
        } else {
            if (in_array($kind, ['paid', 'scheduled'], true)) {
                $lastStart = $randomTime($this->now->subDays(mt_rand(1, $daysInPeriod)));
            } else {
                // Momento em que a subscrição deixou de estar paga: a renovação falhou, o membro cancelou ou foi bloqueado.
                $stoppedAt = match ($kind) {
                    'grace' => $this->now->subDays(mt_rand(0, max(0, $graceDays - 1)))->subHours(mt_rand(1, 23)),
                    'unpaid' => $randomTime($this->now->subDays(mt_rand($graceDays + 2, 200))),
                    default => $randomTime($this->now->subDays(mt_rand(1, 200))),
                };
                $lastStart = $this->previousPeriodStart($stoppedAt);
                $graceEndsAt = $kind === 'grace' ? $stoppedAt->addDays($graceDays) : null;
                $endsAt = match ($kind) {
                    'unpaid' => $stoppedAt->addDays($graceDays),
                    'cancelled', 'blocked' => $stoppedAt,
                    default => null,
                };
            }

            if ($account['periods'] === 0) {
                $trialEndsAt = $stoppedAt;
            } else {
                $paid = $this->paidPeriods($lastStart, $account['periods']);
                $trialEndsAt = $paid[0][0];
            }

            if ($kind === 'scheduled') {
                $endsAt = end($paid)[1];
            }
        }

        return [
            'status' => ['trial' => 'trialing', 'paid' => 'active', 'scheduled' => 'active', 'grace' => 'past_due'][$kind] ?? 'canceled',
            'created_at' => $trialEndsAt->subDays($trialDays),
            'trial_ends_at' => $trialEndsAt,
            'ends_at' => $endsAt,
            'grace_ends_at' => $graceEndsAt,
            'periods' => $account['invoices'] > 0 ? array_slice($paid, -$account['invoices']) : [],
        ];
    }

    /**
     * N períodos pagos seguidos, o último a começar em $lastStart, construídos para a frente a partir do
     * primeiro (fim = início + 1 período), como o Stripe os renova.
     *
     * @return list<array{CarbonImmutable, CarbonImmutable}>
     */
    private function paidPeriods(CarbonImmutable $lastStart, int $count): array
    {
        $interval = SubscriptionInterval::configured();
        $start = $lastStart;

        for ($period = 1; $period < $count; $period++) {
            $start = $this->previousPeriodStart($start);
        }

        $periods = [];

        for ($period = 0; $period < $count; $period++) {
            $end = CarbonImmutable::instance($interval->addTo($start));
            $periods[] = [$start, $end];
            $start = $end;
        }

        return $periods;
    }

    private function previousPeriodStart(CarbonImmutable $date): CarbonImmutable
    {
        return match (SubscriptionInterval::configured()) {
            SubscriptionInterval::Month => $date->subMonthNoOverflow(),
            SubscriptionInterval::Year => $date->subYearNoOverflow(),
        };
    }

    private function userRows(): Generator
    {
        $password = Hash::make($this->password());
        $roles = $this->lookups('roles');
        $statuses = $this->lookups('account_statuses');

        foreach ($this->plan['accounts'] as $index => $account) {
            $data = $this->accounts[$index];
            $subscription = $data['subscription'];
            $billed = $subscription !== null;

            yield [
                'id' => $data['id'],
                'email' => $account['email'],
                'email_verified_at' => $this->timestamp($data['created_at']),
                'password' => $password,
                'phone' => '+3519'.[1, 2, 3, 6][mt_rand(0, 3)].sprintf('%07d', mt_rand(0, 9999999)),
                'role_id' => $roles[$account['admin'] ? 'Admin' : 'Member'],
                'account_status_id' => $statuses[$account['status']],
                'inactive_reason' => $account['reason']?->value,
                'trial_ends_at' => $billed ? $this->timestamp($subscription['trial_ends_at']) : null,
                'grace_ends_at' => $billed ? $this->timestamp($subscription['grace_ends_at']) : null,
                'billing_name' => $billed ? $data['business_name'] : null,
                'nif' => $billed ? '5'.sprintf('%08d', mt_rand(0, 99999999)) : null,
                'billing_address' => $billed ? $data['address'] : null,
                'billing_postal_code' => $billed ? $data['postal_code'] : null,
                'billing_city' => $billed ? $data['city'] : null,
                'terms_accepted_at' => $this->timestamp($data['created_at']),
                'subscription_exempt' => false,
                'notify_new_events' => false,
                'notify_weekly_agenda' => false,
                'created_at' => $this->timestamp($data['created_at']),
                'updated_at' => $this->timestamp($data['created_at']),
            ];
        }
    }

    private function profileRows(): Generator
    {
        $sectors = array_values($this->lookups('sectors'));
        $locations = array_values($this->lookups('locations'));
        $roles = ['Membro', 'Diácono', 'Presbítero', 'Líder de jovens', 'Pastor', 'Tesoureiro'];
        $phrases = [
            'Trabalhamos com clientes de todo o país há mais de dez anos.',
            'Acompanhamos cada projeto do início ao fim, com uma equipa dedicada.',
            'Atendemos particulares e empresas, com orçamento sem compromisso.',
            'Privilegiamos a qualidade, a honestidade e o cumprimento de prazos.',
            'Contamos com parceiros locais para responder depressa a cada pedido.',
            'Formamos a nossa equipa todos os anos para acompanhar o mercado.',
        ];

        foreach ($this->plan['accounts'] as $index => $account) {
            $data = $this->accounts[$index];
            $description = $data['business_name'].'. '.$phrases[mt_rand(0, 5)].' '.$phrases[mt_rand(0, 5)];

            if (mt_rand(1, 10) === 1) {
                $description .= ' Serviços de '.self::SEARCH_TERM.' para pequenas e médias empresas.';
            }

            yield [
                'id' => $data['profile_id'],
                'user_id' => $data['id'],
                'sector_id' => $sectors[mt_rand(0, count($sectors) - 1)],
                'location_id' => $locations[mt_rand(0, count($locations) - 1)],
                'name' => $data['name'],
                'business_name' => $data['business_name'],
                'congregation' => 'Igreja Evangélica de '.$data['city'],
                'role_in_congregation' => $roles[mt_rand(0, count($roles) - 1)],
                'logo_path' => mt_rand(1, 10) <= 6 ? 'carga/logos/logo-'.mt_rand(1, 5).'.png' : null,
                'description' => $description,
                'website_url' => "https://empresa-{$index}.aspec.test",
                'commercial_email' => "contacto.{$index}@empresa-carga.aspec.test",
                'commercial_phone' => '+3512'.sprintf('%08d', mt_rand(0, 99999999)),
                'address' => $data['address'],
                'postal_code' => $data['postal_code'],
                'city' => $data['city'],
                'created_at' => $this->timestamp($data['created_at']),
                'updated_at' => $this->timestamp($data['created_at']),
            ];
        }
    }

    private function businessHourRows(): Generator
    {
        foreach ($this->plan['accounts'] as $index => $account) {
            $days = [0 => [], 1 => range(1, 5), 2 => range(1, 6)][$account['hours']];

            foreach ($days as $weekDay) {
                yield [
                    'id' => (string) Str::uuid7(),
                    'profile_id' => $this->accounts[$index]['profile_id'],
                    'week_day_id' => $weekDay,
                    'open_time' => '09:00:00',
                    'close_time' => $weekDay === 6 ? '13:00:00' : '18:00:00',
                    'created_at' => $this->timestamp($this->accounts[$index]['created_at']),
                    'updated_at' => $this->timestamp($this->accounts[$index]['created_at']),
                ];
            }
        }
    }

    private function socialLinkRows(): Generator
    {
        $platforms = $this->lookups('social_platforms');

        foreach ($this->plan['accounts'] as $index => $account) {
            if ($account['social'] === 0) {
                continue;
            }

            foreach ((array) array_rand($platforms, $account['social']) as $platform) {
                yield [
                    'id' => (string) Str::uuid7(),
                    'profile_id' => $this->accounts[$index]['profile_id'],
                    'platform_id' => $platforms[$platform],
                    'url' => 'https://www.'.Str::lower($platform).".com/empresa-carga-{$index}",
                    'created_at' => $this->timestamp($this->accounts[$index]['created_at']),
                    'updated_at' => $this->timestamp($this->accounts[$index]['created_at']),
                ];
            }
        }
    }

    private function portfolioRows(): Generator
    {
        foreach ($this->plan['accounts'] as $index => $account) {
            for ($image = 0; $image < $account['portfolio']; $image++) {
                yield [
                    'id' => (string) Str::uuid7(),
                    'profile_id' => $this->accounts[$index]['profile_id'],
                    'image_path' => 'carga/portfolio/imagem-'.mt_rand(1, 5).'.jpg',
                    'created_at' => $this->timestamp($this->accounts[$index]['created_at']),
                    'updated_at' => $this->timestamp($this->accounts[$index]['created_at']),
                ];
            }
        }
    }

    /**
     * @param  list<string>  $seriesIds
     */
    private function seriesRows(array $seriesIds): Generator
    {
        foreach ($this->plan['series'] as $index => $series) {
            $dates = $this->sessionDates($index, $series['frequency']);

            yield [
                'id' => $seriesIds[$index],
                'frequency' => $series['frequency'],
                'ends_on' => end($dates)->toDateString(),
                'registration_deadline_days_before' => 1 + $index % 3,
                'created_at' => self::SERIES_CREATED_AT,
                'updated_at' => self::SERIES_CREATED_AT,
            ];
        }
    }

    /**
     * Datas das 12 sessões de uma série, com as mesmas regras do backoffice: as 6 primeiras antes de
     * hoje e as 6 seguintes depois. O desvio (em dias) é fixo por série, para as datas serem iguais
     * nas duas chamadas (tabela das séries e das sessões).
     *
     * @return list<CarbonImmutable>
     */
    private function sessionDates(int $series, string $frequency): array
    {
        $offset = 1 + $series % ['weekly' => 6, 'biweekly' => 13, 'monthly' => 20][$frequency];
        $first = match ($frequency) {
            'weekly' => $this->today->subWeeks(6),
            'biweekly' => $this->today->subWeeks(12),
            'monthly' => $this->today->subMonthsNoOverflow(6),
        };
        $first = $first->addDays($offset);

        return array_map(fn (int $index) => match ($frequency) {
            'weekly' => $first->addWeeks($index),
            'biweekly' => $first->addWeeks($index * 2),
            'monthly' => $first->addMonthsNoOverflow($index),
        }, range(0, 11));
    }

    /**
     * @param  list<string>  $seriesIds
     */
    private function eventRows(array $seriesIds): Generator
    {
        $categories = $this->lookups('event_categories');
        $categoryNames = array_keys($categories);
        $locations = $this->lookups('locations');
        $locationNames = array_keys($locations);
        $verses = [
            '"Alegrai-vos na esperança, sede pacientes na tribulação, perseverai na oração." (Romanos 12:12)',
            '"Lança o teu cuidado sobre o Senhor, e ele te susterá." (Salmos 55:22)',
            '"Tudo posso naquele que me fortalece." (Filipenses 4:13)',
        ];
        $seriesData = [];

        foreach ($this->plan['events'] as $index => $event) {
            $id = (string) Str::uuid7();
            $this->eventIds[$index] = $id;
            $category = $categoryNames[mt_rand(0, count($categoryNames) - 1)];
            $location = $locationNames[mt_rand(0, count($locationNames) - 1)];
            $startHour = mt_rand(8, 20);
            $deadlineDays = 1;
            $seriesId = null;

            if ($event['kind'] === 'session') {
                $seriesData[$event['series']] ??= [
                    'dates' => $this->sessionDates($event['series'], $this->plan['series'][$event['series']]['frequency']),
                    'category' => $category,
                    'location' => $location,
                    'hour' => $startHour,
                ];
                $data = $seriesData[$event['series']];
                [$category, $location, $startHour] = [$data['category'], $data['location'], $data['hour']];
                $date = $data['dates'][$event['position']];
                $deadlineDays = 1 + $event['series'] % 3;
                $seriesId = $seriesIds[$event['series']];
                $name = self::EVENT_PREFIX.'Ciclo de '.$category.' '.($event['series'] + 1);
            } elseif ($event['kind'] === 'big') {
                $date = $this->today->addDays(5);
                $startHour = 18;
                $deadlineDays = 6;
                $name = self::BIG_EVENT_NAME;
            } else {
                $date = $this->today->addDays($event['day']);
                $name = self::EVENT_PREFIX.$category.' em '.$location.' '.($index + 1);
            }

            $deadline = $date->subDays($deadlineDays)->setTime(23, 59);
            $this->eventDates[$index] = $date;
            $this->eventStartHours[$index] = $startHour;
            $this->eventDeadlines[$index] = $deadline;
            $createdAt = $this->registrationWindowEnd($deadline)->subDays(45);
            $hasCards = $event['prayer_cards'] > 0;

            yield [
                'id' => $id,
                'series_id' => $seriesId,
                'category_id' => $categories[$category],
                'location_id' => $locations[$location],
                'name' => $name,
                'event_date' => $date->toDateString(),
                'event_start_time' => sprintf('%02d:00:00', $startHour),
                'event_end_time' => sprintf('%02d:00:00', $startHour + 2),
                'description' => "{$category} da ASPEC em {$location}: apresentação dos participantes, partilha de experiências e tempo para networking.",
                'address' => $event['online'] ? null : 'Rua da Carga, '.mt_rand(1, 300).', '.$location,
                'venue_name' => $event['online'] ? null : 'Auditório '.$location,
                'place_id' => null,
                'latitude' => null,
                'longitude' => null,
                'is_online' => $event['online'],
                'online_url' => $event['online'] ? "https://meet.aspec.test/carga-{$index}" : null,
                'max_attendees' => $event['capacity'],
                'registration_deadline' => $deadline->format('Y-m-d H:i:s'),
                'organizer_notes' => self::EVENT_MARKER,
                'announced_at' => $seriesId === null ? $this->timestamp($createdAt) : null,
                'prayer_cards_enabled' => $hasCards,
                'prayer_verse' => $hasCards ? $verses[mt_rand(0, count($verses) - 1)] : null,
                'prayer_cards_distributed_at' => $hasCards ? $this->timestamp($date->setTime(23, 59)->addMinutes(5)) : null,
                'created_at' => $this->timestamp($createdAt),
                'updated_at' => $this->timestamp($createdAt),
            ];
        }
    }

    /**
     * Última data possível de inscrição (UTC): o prazo, ou agora se o prazo ainda não passou.
     */
    private function registrationWindowEnd(CarbonImmutable $deadline): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp(min($deadline->getTimestamp(), $this->now->getTimestamp()), 'UTC');
    }

    /**
     * Inscrições de membros ativos (sem repetição e até à lotação), todas antes do prazo. Guarda os
     * inscritos dos eventos com cartões de oração, que são os únicos que podem escrever cartões.
     */
    private function registrationRows(): Generator
    {
        foreach ($this->plan['events'] as $index => $event) {
            $registrants = $this->sampleActiveMembers($event['registrations']);

            if ($event['prayer_cards'] > 0) {
                $this->cardRegistrants[$index] = $registrants;
            }

            $windowEnd = $this->registrationWindowEnd($this->eventDeadlines[$index])->getTimestamp();

            foreach ($registrants as $member) {
                yield [
                    'id' => (string) Str::uuid7(),
                    'event_instance_id' => $this->eventIds[$index],
                    'user_id' => $this->accounts[$member]['id'],
                    'created_at' => $this->timestamp(CarbonImmutable::createFromTimestamp($windowEnd - mt_rand(0, 40 * 86400), 'UTC')),
                ];
            }
        }
    }

    /**
     * Cartões de oração dos eventos passados com cartões: 70% dos inscritos escrevem e a corrente é
     * distribuída (o autor na posição i recebe o cartão do autor seguinte). Os de eventos com mais de
     * 6 meses ficam como a expiração os deixa: soft deleted, sem conteúdo nem destinatário.
     */
    private function prayerCardRows(): Generator
    {
        $retention = (int) config('prayer_cards.retention_months', 6);
        $expiredBefore = $this->today->subMonthsNoOverflow($retention);
        $requests = [
            ['Pela saúde da minha família.', 'Sabedoria nas decisões da empresa.', null],
            [null, 'Pelos novos clientes deste trimestre.', 'Pelo meu sócio, para que encontre a paz.'],
            ['Pela recuperação de um amigo internado.', null, 'Pelos meus vizinhos.'],
        ];

        foreach ($this->plan['events'] as $index => $event) {
            if ($event['prayer_cards'] === 0) {
                continue;
            }

            $registrants = $this->cardRegistrants[$index];
            $authors = array_values(array_intersect_key($registrants, array_flip((array) array_rand($registrants, $event['prayer_cards']))));
            $date = $this->eventDates[$index];
            $expired = $date->lessThan($expiredBefore);
            $distributedAt = $this->timestamp($date->setTime(23, 59)->addMinutes(5));
            $deletedAt = $expired ? $this->timestamp($date->addMonthsNoOverflow($retention)->setTime(3, 0)) : null;

            foreach ($authors as $position => $member) {
                $submittedAt = $this->timestamp($date->setTime($this->eventStartHours[$index], 0)->addMinutes(mt_rand(15, 179)));
                $recipient = count($authors) > 1 && ! $expired ? $authors[($position - 1 + count($authors)) % count($authors)] : null;
                [$personal, $work, $salvation] = $expired ? [null, null, null] : $requests[mt_rand(0, count($requests) - 1)];

                yield [
                    'id' => (string) Str::uuid7(),
                    'event_instance_id' => $this->eventIds[$index],
                    'author_user_id' => $this->accounts[$member]['id'],
                    'recipient_user_id' => $recipient === null ? null : $this->accounts[$recipient]['id'],
                    'personal_request' => $personal === null ? null : Crypt::encryptString($personal),
                    'work_request' => $work === null ? null : Crypt::encryptString($work),
                    'salvation_request' => $salvation === null ? null : Crypt::encryptString($salvation),
                    'consent_given_at' => $submittedAt,
                    'received_at' => $recipient === null ? null : $distributedAt,
                    'created_at' => $submittedAt,
                    'updated_at' => $submittedAt,
                    'deleted_at' => $deletedAt,
                ];
            }
        }
    }

    /**
     * Avaliações sobre os perfis ativos, escritas por outros perfis ativos (sem pares repetidos nem
     * autoavaliações), depois de os dois perfis existirem. Junta em $reports as sinalizações:
     * a automática de cada Flagged e as de membros (feitas pela empresa avaliada).
     *
     * @param  array<int, array<string, mixed>>  $reports
     */
    private function reviewRows(array &$reports): Generator
    {
        $statuses = $this->lookups('review_statuses');
        $ratings = [5, 5, 5, 5, 4, 4, 4, 3, 2, 1];
        $comments = [
            'Serviço rápido e muito profissional. Recomendo.',
            'Cumpriram o prazo e o orçamento combinados.',
            'Boa comunicação durante todo o projeto.',
            'Atendimento simpático, mas a entrega atrasou uns dias.',
            'Voltaremos a trabalhar juntos no próximo ano.',
        ];
        $now = $this->now->getTimestamp();
        $position = 0;

        foreach ($this->plan['reviews'] as $reviewed => $count) {
            if ($count === 0) {
                continue;
            }

            $reviewers = array_slice(array_values(array_diff($this->sampleActiveMembers($count + 1), [$reviewed])), 0, $count);

            foreach ($reviewers as $reviewer) {
                $id = (string) Str::uuid7();
                $status = self::reviewStatus($position);
                $since = max($this->activeCreatedAt[$reviewer], $this->activeCreatedAt[$reviewed]);
                $createdAt = $this->timestamp(CarbonImmutable::createFromTimestamp(mt_rand($since, $now), 'UTC'));
                $reviewedProfile = $this->accounts[$reviewed]['profile_id'];

                yield [
                    'id' => $id,
                    'reviewer_profile_id' => $this->accounts[$reviewer]['profile_id'],
                    'reviewed_profile_id' => $reviewedProfile,
                    'rating' => $ratings[mt_rand(0, count($ratings) - 1)],
                    'comment' => $comments[mt_rand(0, count($comments) - 1)],
                    'status_id' => $statuses[$status],
                    'hidden_reason' => $status === 'Hidden' ? 'Linguagem desrespeitosa e sem relação comercial demonstrada.' : null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                if ($status === 'Flagged') {
                    $reports[] = $this->reportRow($id, null, 'language_filter', null, ReviewReport::SOURCE_AUTOMATIC, null, $createdAt);
                }

                if (self::hasMemberReport($position)) {
                    $reports[] = $this->reportRow(
                        $id,
                        $reviewedProfile,
                        ReviewReport::REASONS[mt_rand(0, count(ReviewReport::REASONS) - 1)],
                        'Não reconhecemos este trabalho.',
                        ReviewReport::SOURCE_MEMBER,
                        mt_rand(1, 3) === 1 ? $this->timestamp($this->now) : null,
                        $createdAt,
                    );
                }

                $position++;
            }
        }
    }

    private function reportRow(string $reviewId, ?string $reporterProfileId, string $reason, ?string $comment, string $source, ?string $resolvedAt, string $createdAt): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'review_id' => $reviewId,
            'reporter_profile_id' => $reporterProfileId,
            'reason' => $reason,
            'comment' => $comment,
            'source' => $source,
            'resolved_at' => $resolvedAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    private function subscriptionRows(): Generator
    {
        $price = config('subscription.price_id') ?: 'price_load';

        foreach ($this->plan['accounts'] as $index => $account) {
            $dates = $this->accounts[$index]['subscription'];

            if ($dates === null) {
                continue;
            }

            yield [
                'user_id' => $this->accounts[$index]['id'],
                'type' => 'default',
                'stripe_id' => $this->stripeId('sub', $index),
                'stripe_status' => $dates['status'],
                'stripe_price' => $price,
                'quantity' => 1,
                'trial_ends_at' => $this->timestamp($dates['trial_ends_at']),
                'ends_at' => $this->timestamp($dates['ends_at']),
                'created_at' => $this->timestamp($dates['created_at']),
                'updated_at' => $this->timestamp($dates['created_at']),
            ];
        }
    }

    private function subscriptionItemRows(): Generator
    {
        $price = config('subscription.price_id') ?: 'price_load';

        foreach ($this->plan['accounts'] as $index => $account) {
            $dates = $this->accounts[$index]['subscription'];

            if ($dates === null) {
                continue;
            }

            yield [
                'subscription_id' => $this->subscriptionIds[$this->stripeId('sub', $index)],
                'stripe_id' => $this->stripeId('si', $index),
                'stripe_product' => 'prod_load',
                'stripe_price' => $price,
                'quantity' => 1,
                'created_at' => $this->timestamp($dates['created_at']),
                'updated_at' => $this->timestamp($dates['created_at']),
            ];
        }
    }

    /**
     * Uma fatura demo emitida por período pago, sem PDF, numerada CARGA-AAAA-NNNN.
     */
    private function invoiceRows(): Generator
    {
        $amount = (int) round(config('subscription.price_amount') * 100);
        $vatRate = (int) config('services.invoicing.vat_rate');
        $number = 0;

        foreach ($this->plan['accounts'] as $index => $account) {
            $dates = $this->accounts[$index]['subscription'];

            foreach ($dates['periods'] ?? [] as $period => [$start, $end]) {
                $issuedAt = $start->addMinutes(mt_rand(1, 30));

                yield [
                    'id' => (string) Str::uuid7(),
                    'user_id' => $this->accounts[$index]['id'],
                    'stripe_invoice_id' => $this->stripeId('in', $index).'_'.($period + 1),
                    'provider' => 'demo',
                    'provider_invoice_id' => null,
                    'number' => sprintf('CARGA-%d-%04d', $issuedAt->year, ++$number),
                    'amount' => $amount,
                    'currency' => config('subscription.currency'),
                    'vat_rate' => $vatRate,
                    'vat_exemption_code' => $vatRate === 0 ? (config('services.invoicing.vat_exemption_code') ?: null) : null,
                    'vat_exemption_reason' => $vatRate === 0 ? config('services.invoicing.vat_exemption_reason') : null,
                    'period_start' => $this->timestamp($start),
                    'period_end' => $this->timestamp($end),
                    'status' => 'issued',
                    'pdf_url' => null,
                    'issued_at' => $this->timestamp($issuedAt),
                    'created_at' => $this->timestamp($issuedAt),
                    'updated_at' => $this->timestamp($issuedAt),
                ];
            }
        }
    }

    /**
     * Insere as linhas em lotes de 500 numa só transação (um commit por tabela em vez de um por lote).
     * As linhas vêm de um gerador, por isso nunca estão todas em memória.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @param  (Closure(list<array<string, mixed>>): void)|null  $afterBatch
     */
    private function insertInBatches(string $table, iterable $rows, ?Closure $afterBatch = null): int
    {
        return DB::transaction(function () use ($table, $rows, $afterBatch) {
            $batch = [];
            $total = 0;

            foreach ($rows as $row) {
                $batch[] = $row;

                if (count($batch) === self::BATCH_SIZE) {
                    $total += $this->insertBatch($table, $batch, $afterBatch);
                    $batch = [];
                }
            }

            return $total + ($batch === [] ? 0 : $this->insertBatch($table, $batch, $afterBatch));
        });
    }

    private function insertBatch(string $table, array $batch, ?Closure $afterBatch): int
    {
        DB::table($table)->insert($batch);

        if ($afterBatch !== null) {
            $afterBatch($batch);
        }

        return count($batch);
    }

    /**
     * Corre $callback por blocos de 1 000 ids e junta o que devolver (se for uma lista).
     *
     * @param  list<string|int>  $ids
     * @return list<mixed>
     */
    private function chunked(array $ids, Closure $callback): array
    {
        $result = [];

        foreach (array_chunk($ids, self::CLEAN_BATCH_SIZE) as $chunk) {
            $value = $callback($chunk);

            if (is_array($value)) {
                $result = [...$result, ...$value];
            }
        }

        return $result;
    }

    /**
     * K membros ativos diferentes (índices do plano), escolhidos ao acaso.
     *
     * @return list<int>
     */
    private function sampleActiveMembers(int $count): array
    {
        $count = min($count, count($this->activePool));

        return match (true) {
            $count <= 0 => [],
            $count === 1 => [array_rand($this->activePool)],
            default => array_rand($this->activePool, $count),
        };
    }

    /**
     * Id falso do Stripe derivado do id da conta (sub_load_…, si_load_…, in_load_…): único e reconhecível na limpeza.
     */
    private function stripeId(string $prefix, int $index): string
    {
        return "{$prefix}_load_".str_replace('-', '', $this->accounts[$index]['id']);
    }

    /**
     * Ids de uma tabela de lookup por nome.
     *
     * @return array<string, string|int>
     */
    private function lookups(string $table): array
    {
        return $this->lookupIds[$table] ??= DB::table($table)->orderBy('name')->pluck('id', 'name')->all();
    }

    private function lookupsMissing(): bool
    {
        $tables = ['roles', 'account_statuses', 'review_statuses', 'sectors', 'locations', 'week_days', 'social_platforms', 'event_categories'];

        return collect($tables)->contains(fn (string $table) => DB::table($table)->doesntExist());
    }

    /**
     * Password das contas de carga: a mesma do DemoSeeder (DEMO_PASSWORD ou a pública Demo#2026),
     * porque os dados de carga só existem em local/testing.
     */
    private function password(): string
    {
        $password = (string) config('aspec.demo_password');

        return $password !== '' ? $password : DemoSeeder::PASSWORD;
    }

    /**
     * Data em UTC no formato da base de dados (null fica null).
     */
    /**
     * Data em UTC para as colunas TIMESTAMP. Um MySQL com o fuso do sistema em Lisboa
     * recusa as horas que não existem na mudança para a hora de verão (último domingo
     * de março, 01:00–01:59), por isso essas horas avançam uma hora.
     */
    private function timestamp(?CarbonImmutable $date): ?string
    {
        if ($date === null) {
            return null;
        }

        $value = $date->utc()->format('Y-m-d H:i:s');

        if (CarbonImmutable::parse($value, 'Europe/Lisbon')->format('Y-m-d H:i:s') !== $value) {
            return $date->utc()->addHour()->format('Y-m-d H:i:s');
        }

        return $value;
    }
}
