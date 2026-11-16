<?php

namespace App\Console\Commands;

use App\Models\User;
use Closure;
use Database\Seeders\LoadSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RunBenchmark extends Command
{
    protected $signature = 'aspec:benchmark
        {--runs=30 : Corridas medidas por cenário}
        {--warmup=3 : Corridas de aquecimento descartadas por cenário}
        {--label= : Etiqueta do relatório (ex.: antes, depois)}
        {--queries : Mostra as 3 consultas mais lentas de cada cenário}
        {--skip-agenda : Não mede a agenda semanal}';

    protected $description = 'Mede os pedidos principais da API sobre os dados de carga. Só em local/testing.';

    private const AGENDA_RUNS = 3;

    private const CSV_COLUMNS = ['cenario', 'http', 'mediana_ms', 'p95_ms', 'min_ms', 'max_ms', 'consultas', 'sql_mediana_ms', 'kb'];

    /** Se as consultas executadas estão a ser registadas (só durante o pedido medido). */
    private bool $recording = false;

    /** @var list<array{sql: string, bindings: array, time: float}> */
    private array $queries = [];

    /**
     * Mede cada cenário com pedidos dentro do processo (HTTP Kernel, como nos testes): aquecimento,
     * corridas, tempos (mediana, p95, mínimo, máximo), número de consultas, tempo SQL e tamanho.
     * Mede ainda a agenda semanal (numa transação desfeita, sem enviar emails) e escreve o resultado
     * em storage/app/benchmarks (.md e .csv).
     *
     * @return int SUCCESS; FAILURE se o ambiente for recusado, não houver dados de carga ou algum cenário não der 200.
     */
    public function handle(): int
    {
        $refusal = LoadSeeder::refusal();

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $scenarios = $this->scenarios();

        if ($scenarios === null) {
            $this->error('Não há dados de carga. Corra primeiro php artisan aspec:load-data.');

            return self::FAILURE;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->warn('Base de dados SQLite: os tempos não são representativos (use o MySQL local).');
        }

        $runs = max(1, (int) $this->option('runs'));
        $warmup = max(0, (int) $this->option('warmup'));

        $this->disableRateLimiters();
        DB::listen(function (QueryExecuted $query) {
            if ($this->recording) {
                $this->queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'time' => $query->time];
            }
        });

        $results = [];

        foreach ($scenarios as $name => $scenario) {
            $results[$name] = $this->measure($name, $scenario, $runs, $warmup);
        }

        $this->table(['Cenário', 'HTTP', 'Mediana (ms)', 'p95 (ms)', 'Mín. (ms)', 'Máx. (ms)', 'Consultas', 'SQL mediana (ms)', 'KB'], array_values($results));

        $agenda = $this->option('skip-agenda') ? null : $this->measureWeeklyAgenda();

        $failed = array_filter($results, fn (array $row) => $row['http'] !== Response::HTTP_OK);

        if ($failed !== []) {
            $this->error('Cenários sem resposta 200 (a medição não é válida): '.implode(', ', array_keys($failed)));

            return self::FAILURE;
        }

        [$markdown, $csv] = $this->writeReport($results, $agenda, $runs, $warmup);
        $this->info("Relatório: {$markdown}");
        $this->info("CSV: {$csv}");

        return self::SUCCESS;
    }

    /**
     * Pedidos medidos. Os ids vêm dos dados de carga (nunca fixos); estas consultas não contam.
     *
     * @return array<string, array{uri: string, user: ?string}>|null Null se não houver dados de carga.
     */
    private function scenarios(): ?array
    {
        $admin = User::query()->where('email', LoadSeeder::ADMIN_EMAIL)->value('id');
        $member = User::query()
            ->where('email', 'like', LoadSeeder::EMAIL_PATTERN)
            ->where('email', 'not like', 'carga.admin%')
            ->activeWithProfile()
            ->orderBy('email')
            ->value('id');
        $bigEvent = DB::table('event_instances')->where('name', LoadSeeder::BIG_EVENT_NAME)->value('id');

        if ($admin === null || $member === null || $bigEvent === null) {
            return null;
        }

        $popularProfile = DB::table('reviews')
            ->join('review_statuses', 'review_statuses.id', '=', 'reviews.status_id')
            ->where('review_statuses.name', 'Visible')
            ->select('reviews.reviewed_profile_id')
            ->groupBy('reviews.reviewed_profile_id')
            ->orderByRaw('count(*) desc')
            ->value('reviewed_profile_id');
        $profilePair = DB::table('member_profiles')
            ->join('users', 'users.id', '=', 'member_profiles.user_id')
            ->join('account_statuses', 'account_statuses.id', '=', 'users.account_status_id')
            ->where('account_statuses.name', 'Active')
            ->whereNull('member_profiles.deleted_at')
            ->select('member_profiles.sector_id', 'member_profiles.location_id')
            ->groupBy('member_profiles.sector_id', 'member_profiles.location_id')
            ->orderByRaw('count(*) desc')
            ->first();
        $eventPair = DB::table('event_instances')
            ->where('event_date', '>', now(config('app.event_timezone', 'Europe/Lisbon'))->toDateString())
            ->select('category_id', 'location_id')
            ->groupBy('category_id', 'location_id')
            ->orderByRaw('count(*) desc')
            ->first();

        if ($popularProfile === null || $profilePair === null || $eventPair === null) {
            return null;
        }

        $directoryFilters = http_build_query([
            'search' => LoadSeeder::SEARCH_TERM,
            'sector_id' => $profilePair->sector_id,
            'location_id' => $profilePair->location_id,
            'min_rating' => 4,
            'per_page' => 12,
        ]);
        $eventFilters = http_build_query(['category_id' => $eventPair->category_id, 'location_id' => $eventPair->location_id]);

        return [
            'Diretório, página 1' => ['uri' => '/api/members', 'user' => null],
            'Diretório com todos os filtros' => ['uri' => "/api/members?{$directoryFilters}", 'user' => null],
            'Perfil com mais avaliações' => ['uri' => "/api/members/{$popularProfile}", 'user' => $member],
            'Avaliações desse perfil' => ['uri' => "/api/members/{$popularProfile}/reviews", 'user' => $member],
            'Agenda por categoria e distrito' => ['uri' => "/api/events?{$eventFilters}", 'user' => null],
            'Evento grande' => ['uri' => "/api/events/{$bigEvent}", 'user' => $member],
            'Backoffice: utilizadores' => ['uri' => '/api/admin/users', 'user' => $admin],
            'Backoffice: em carência com pesquisa' => ['uri' => '/api/admin/users?subscription_state=grace&search=carga', 'user' => $admin],
            'Backoffice: dashboard' => ['uri' => '/api/admin/dashboard', 'user' => $admin],
            'Exportação CSV do evento grande' => ['uri' => "/api/admin/events/{$bigEvent}/attendees.csv", 'user' => $admin],
        ];
    }

    /**
     * Só neste processo: os limitadores deixam passar tudo. As corridas ultrapassariam os limites por
     * minuto e o custo do limitador não depende do volume de dados (fica fora da medição).
     */
    private function disableRateLimiters(): void
    {
        $this->laravel->instance(ThrottleRequests::class, new class
        {
            public function handle(Request $request, Closure $next, mixed ...$parameters): mixed
            {
                return $next($request);
            }
        });
    }

    /**
     * Aquecimento e corridas de um cenário.
     *
     * @param  array{uri: string, user: ?string}  $scenario
     * @return array{cenario: string, http: int, mediana_ms: float, p95_ms: float, min_ms: float, max_ms: float, consultas: int, sql_mediana_ms: float, kb: float}
     */
    private function measure(string $name, array $scenario, int $runs, int $warmup): array
    {
        for ($run = 0; $run < $warmup; $run++) {
            $this->request($scenario);
        }

        $times = [];
        $sqlTimes = [];
        $queryCounts = [];

        for ($run = 0; $run < $runs; $run++) {
            $result = $this->request($scenario);
            $times[] = $result['ms'];
            $sqlTimes[] = array_sum(array_column($this->queries, 'time'));
            $queryCounts[] = count($this->queries);
        }

        if (count(array_unique($queryCounts)) > 1) {
            $this->warn("{$name}: o número de consultas variou entre corridas (".implode(', ', array_unique($queryCounts)).').');
        }

        if ($this->option('queries')) {
            $this->showSlowestQueries($name);
        }

        sort($times);

        return [
            'cenario' => $name,
            'http' => $result['status'],
            'mediana_ms' => round($this->median($times), 1),
            'p95_ms' => round($times[(int) ceil(0.95 * count($times)) - 1], 1),
            'min_ms' => round($times[0], 1),
            'max_ms' => round(end($times), 1),
            'consultas' => end($queryCounts),
            'sql_mediana_ms' => round($this->median($sqlTimes), 1),
            'kb' => round($result['bytes'] / 1024, 1),
        ];
    }

    /**
     * Um pedido pelo HTTP Kernel, autenticado como o actingAs() dos testes (guard web, que o sanctum
     * aceita primeiro): não escreve nada e não junta o custo da sessão. O utilizador é lido de novo
     * fora da medição, como o guard faria em cada pedido real. As respostas em stream (CSV) são
     * geradas dentro do tempo medido.
     *
     * @param  array{uri: string, user: ?string}  $scenario
     * @return array{status: int, ms: float, bytes: int}
     */
    private function request(array $scenario): array
    {
        $kernel = $this->laravel->make(HttpKernel::class);
        $user = $scenario['user'] === null ? null : User::query()->find($scenario['user']);

        $this->laravel['auth']->forgetGuards();

        if ($user !== null) {
            $this->laravel['auth']->guard('web')->setUser($user);
        }

        $this->laravel['auth']->shouldUse('web');

        $request = Request::create($scenario['uri'], 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
        $this->queries = [];
        $this->recording = true;
        $start = hrtime(true);

        $response = $kernel->handle($request);

        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();
            $content = (string) ob_get_clean();
        } else {
            $content = (string) $response->getContent();
        }

        $elapsed = (hrtime(true) - $start) / 1e6;
        $this->recording = false;

        $kernel->terminate($request, $response);

        return ['status' => $response->getStatusCode(), 'ms' => $elapsed, 'bytes' => strlen($content)];
    }

    /**
     * As 3 consultas mais lentas da última corrida, com os valores, para analisar com EXPLAIN ANALYZE.
     */
    private function showSlowestQueries(string $name): void
    {
        $slowest = collect($this->queries)->sortByDesc('time')->take(3);

        $this->line("<comment>{$name}</comment>: consultas mais lentas da última corrida");

        foreach ($slowest as $query) {
            $this->line(sprintf('  %.2f ms  %s', $query['time'], $query['sql']));
            $this->line('           valores: '.json_encode($query['bindings'], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Agenda semanal (tarefa agendada) dentro de uma transação sempre desfeita: os emails são mesmo
     * construídos e entregues a um mailer que os descarta (log para o canal null), e o
     * weekly_agenda_sent_at volta ao que era, por isso a medição pode repetir-se. Força a fila sync e
     * esse mailer, porque o .env local envia emails reais (Gmail ou Brevo). As contas de carga têm a
     * agenda desligada e só a ligam dentro da transação, por isso o rollback também a volta a desligar.
     *
     * @return array{ms: float, emails: int, ms_por_email: float, memoria_mb: float}
     */
    private function measureWeeklyAgenda(): array
    {
        $sent = 0;
        $counting = false;
        Event::listen(NotificationSent::class, function () use (&$sent, &$counting) {
            if ($counting) {
                $sent++;
            }
        });

        $times = [];
        $emails = 0;
        $memory = 0;

        for ($run = 0; $run < self::AGENDA_RUNS; $run++) {
            $original = ['queue.default' => config('queue.default'), 'mail.default' => config('mail.default')];
            config([
                'queue.default' => 'sync',
                'mail.mailers.aspec-benchmark' => ['transport' => 'log', 'channel' => 'null'],
                'mail.default' => 'aspec-benchmark',
            ]);
            $sent = 0;
            $counting = true;
            memory_reset_peak_usage();
            DB::beginTransaction();

            try {
                DB::table('users')->where('email', 'like', LoadSeeder::EMAIL_PATTERN)->update(['notify_weekly_agenda' => true]);

                $start = hrtime(true);
                Artisan::call('events:send-weekly-agenda', [], new BufferedOutput);
                $times[] = (hrtime(true) - $start) / 1e6;
            } finally {
                DB::rollBack();
                $counting = false;
                config($original);
            }

            $emails = $sent;
            $memory = max($memory, memory_get_peak_usage(true));
        }

        $agenda = [
            'ms' => round($this->median($times), 1),
            'emails' => $emails,
            'ms_por_email' => $emails > 0 ? round($this->median($times) / $emails, 2) : 0.0,
            'memoria_mb' => round($memory / 1048576, 1),
        ];

        $this->info($this->agendaLine($agenda));

        return $agenda;
    }

    private function agendaLine(array $agenda): string
    {
        return sprintf(
            'Agenda semanal (mediana de %d corridas): %s ms, %d emails, %s ms por email, memória máxima %s MB.',
            self::AGENDA_RUNS,
            $agenda['ms'],
            $agenda['emails'],
            $agenda['ms_por_email'],
            $agenda['memoria_mb'],
        );
    }

    /**
     * Escreve o relatório em Markdown (com o contexto da medição) e o CSV (uma linha por cenário HTTP).
     *
     * @return array{0: string, 1: string} Caminhos do .md e do .csv.
     */
    private function writeReport(array $results, ?array $agenda, int $runs, int $warmup): array
    {
        $directory = storage_path('app/benchmarks');
        File::ensureDirectoryExists($directory);

        $label = Str::slug((string) $this->option('label'));
        $basename = 'benchmark-'.($label !== '' ? "{$label}-" : '').now()->format('Ymd-Hi');
        $markdownPath = "{$directory}/{$basename}.md";
        $csvPath = "{$directory}/{$basename}.csv";

        $handle = fopen($csvPath, 'w');
        fputcsv($handle, self::CSV_COLUMNS, ',', '"', '');

        foreach ($results as $row) {
            fputcsv($handle, array_values($row), ',', '"', '');
        }

        fclose($handle);

        $lines = [
            '# Medição de desempenho (aspec:benchmark)',
            '',
            '- Data: '.now(config('app.event_timezone', 'Europe/Lisbon'))->format('Y-m-d H:i'),
            '- Etiqueta: '.($label !== '' ? $label : '(nenhuma)'),
            '- APP_ENV: '.$this->laravel->environment(),
            '- PHP: '.PHP_VERSION,
            '- Base de dados: '.DB::getDriverName().' '.DB::connection()->getServerVersion(),
            "- Corridas por cenário: {$runs} (aquecimento: {$warmup})",
            '- Volume: '.implode(', ', array_map(
                fn (string $label, int $count) => "{$label} {$count}",
                array_keys($this->tableCounts()),
                $this->tableCounts(),
            )),
            '',
            '| Cenário | HTTP | Mediana (ms) | p95 (ms) | Mín. (ms) | Máx. (ms) | Consultas | SQL mediana (ms) | KB |',
            '|---|---|---|---|---|---|---|---|---|',
            ...array_map(fn (array $row) => '| '.implode(' | ', $row).' |', array_values($results)),
            '',
            $agenda === null ? 'Agenda semanal: não medida (--skip-agenda).' : $this->agendaLine($agenda),
            '',
            'Pedidos dentro do processo (HTTP Kernel). Ficam de fora: arranque do framework, servidor web, rede, TLS, frontend, sessão e limitadores de pedidos.',
            '',
        ];

        File::put($markdownPath, implode(PHP_EOL, $lines));

        return [$markdownPath, $csvPath];
    }

    /**
     * Contagens das tabelas principais, para a tabela do relatório se explicar sozinha.
     *
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        return [
            'membros ativos' => User::query()->activeWithProfile()->count(),
            'avaliações' => DB::table('reviews')->count(),
            'inscrições' => DB::table('event_registrations')->count(),
            'eventos' => DB::table('event_instances')->count(),
        ];
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
