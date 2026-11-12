<?php

namespace Tests\Feature\Console;

use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Throwable;

/**
 * php artisan aspec:benchmark mede os cenários principais (diretório, perfil, avaliações, agenda,
 * backoffice, exportação e agenda semanal) sobre os dados de carga e escreve o resultado em
 * storage/app/benchmarks (.md e .csv). Sem assertivas de tempo: só o contrato do comando.
 */
class BenchmarkCommandTest extends TestCase
{
    use RefreshDatabase;

    private const LABEL = 'teste453';

    /** Colunas do CSV, pela ordem: uma linha por cenário HTTP. */
    private const CSV_COLUMNS = ['cenario', 'http', 'mediana_ms', 'p95_ms', 'min_ms', 'max_ms', 'consultas', 'sql_mediana_ms', 'kb'];

    /** Cenários HTTP medidos (a agenda semanal é à parte e omite-se com --skip-agenda). */
    private const HTTP_SCENARIOS = 10;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.media_disk'));
        Storage::fake('local');
        $this->deleteBenchmarkFiles();
    }

    protected function tearDown(): void
    {
        $this->deleteBenchmarkFiles();

        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function benchmarkFiles(string $extension = '*'): array
    {
        return glob(storage_path('app/benchmarks/benchmark-'.self::LABEL.'-*.'.$extension)) ?: [];
    }

    private function deleteBenchmarkFiles(): void
    {
        foreach ($this->benchmarkFiles() as $file) {
            @unlink($file);
        }
    }

    private function loadData(): void
    {
        $this->artisan('aspec:load-data', ['--members' => 20])->assertSuccessful();
    }

    private function benchmarkOptions(array $extra = []): array
    {
        return ['--runs' => 2, '--warmup' => 0, '--label' => self::LABEL, ...$extra];
    }

    /**
     * @return array{0: list<string>, 1: list<array<string, string>>} Cabeçalho e linhas (por coluna).
     */
    private function readCsv(string $file): array
    {
        $lines = array_values(array_filter(
            preg_split('/\R/', trim(file_get_contents($file))),
            fn (string $line) => $line !== '',
        ));
        $delimiter = str_contains($lines[0], ';') ? ';' : ',';
        $header = array_map(fn (string $column) => trim($column, " \t\"\u{FEFF}"), str_getcsv($lines[0], $delimiter, '"', ''));

        $rows = array_map(
            fn (string $line) => array_combine($header, array_map('trim', str_getcsv($line, $delimiter, '"', ''))),
            array_slice($lines, 1),
        );

        return [$header, $rows];
    }

    #[Test]
    public function measures_every_scenario_with_status_200_and_writes_markdown_and_csv(): void
    {
        $this->loadData();

        $this->artisan('aspec:benchmark', $this->benchmarkOptions(['--skip-agenda' => true]))->assertExitCode(0);

        $markdown = $this->benchmarkFiles('md');
        $csv = $this->benchmarkFiles('csv');
        $this->assertCount(1, $markdown);
        $this->assertCount(1, $csv);
        $this->assertMatchesRegularExpression('/benchmark-'.self::LABEL.'-\d{8}-\d{4}\.md$/', $markdown[0]);
        $this->assertMatchesRegularExpression('/benchmark-'.self::LABEL.'-\d{8}-\d{4}\.csv$/', $csv[0]);

        [$header, $rows] = $this->readCsv($csv[0]);
        $this->assertSame(self::CSV_COLUMNS, $header);
        $this->assertCount(self::HTTP_SCENARIOS, $rows);

        foreach ($rows as $row) {
            $this->assertSame('200', $row['http'], $row['cenario']);
            $this->assertNotSame('', $row['cenario']);
            $this->assertIsNumeric($row['mediana_ms'], $row['cenario']);
            $this->assertIsNumeric($row['p95_ms'], $row['cenario']);
            $this->assertIsNumeric($row['consultas'], $row['cenario']);
            $this->assertIsNumeric($row['kb'], $row['cenario']);
        }

        $this->assertCount(self::HTTP_SCENARIOS, array_unique(array_column($rows, 'cenario')));

        $content = file_get_contents($markdown[0]);
        $this->assertStringContainsString('APP_ENV', $content);
        $this->assertStringContainsString('testing', $content);
        $this->assertStringContainsString(PHP_VERSION, $content);
        $this->assertStringContainsString('p95', $content);
        $this->assertMatchesRegularExpression('/^\|.*\|\s*$/m', $content);
    }

    #[Test]
    public function without_load_data_it_fails_and_asks_to_load_it_first(): void
    {
        $this->artisan('aspec:benchmark', $this->benchmarkOptions())
            ->expectsOutputToContain('Corra primeiro php artisan aspec:load-data.')
            ->assertExitCode(1);

        $this->assertSame([], $this->benchmarkFiles());
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
        $this->loadData();

        $this->app->detectEnvironment(fn () => $environment);

        try {
            $exitCode = $this->artisan('aspec:benchmark', $this->benchmarkOptions(['--skip-agenda' => true]))->run();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertSame(1, $exitCode);
        $this->assertSame([], $this->benchmarkFiles());
    }

    /**
     * Sem Mail::fake (que ignora as notificações): o mailer por omissão é o array, como se fosse o
     * Gmail ou o Brevo do .env local. O benchmark tem de construir os emails da agenda, mas entregá-los
     * a um mailer que os descarta, e desfazer o weekly_agenda_sent_at e as preferências.
     */
    #[Test]
    public function weekly_agenda_scenario_builds_emails_but_delivers_none_and_rolls_back(): void
    {
        config(['mail.default' => 'array']);
        $this->loadData();
        $this->createOpenEventSoEveryActiveMemberGetsAnAgenda();
        $agendaOn = DB::table('users')->where('notify_weekly_agenda', true)->count();

        $notificationsSent = 0;
        Event::listen(NotificationSent::class, function () use (&$notificationsSent) {
            $notificationsSent++;
        });

        $buffer = new BufferedOutput;
        $exitCode = Artisan::call('aspec:benchmark', $this->benchmarkOptions(), $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode, $output);
        $this->assertSame(1, preg_match('/Agenda semanal.*?(\d+) emails/', $output, $matches), $output);
        $this->assertGreaterThan(0, (int) $matches[1]);
        $this->assertGreaterThan(0, $notificationsSent);

        $this->assertEmpty(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages());
        $this->assertSame(0, DB::table('users')->whereNotNull('weekly_agenda_sent_at')->count());
        $this->assertSame($agendaOn, DB::table('users')->where('notify_weekly_agenda', true)->count());
        $this->assertSame(0, DB::table('jobs')->count());
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

    /**
     * A guarda tem de recusar antes de abrir a ligação: se o comando tentar ligar-se ao host remoto,
     * a exceção é convertida numa falha do teste (nunca chega a haver rede: o host não resolve).
     */
    #[Test]
    #[DataProvider('remoteDatabaseConfigs')]
    public function refuses_a_remote_database_host_even_in_testing(array $connection): void
    {
        $this->loadData();
        $sqlite = config('database.default');
        config(collect(['port' => 9, 'options' => [PDO::ATTR_TIMEOUT => 1], ...$connection])
            ->mapWithKeys(fn ($value, string $key) => ["database.connections.mysql.{$key}" => $value])
            ->put('database.default', 'mysql')
            ->all());

        try {
            $exitCode = $this->artisan('aspec:benchmark', $this->benchmarkOptions(['--skip-agenda' => true]))->run();
        } catch (Throwable $exception) {
            $this->fail('O comando tentou usar a base de dados remota: '.$exception->getMessage());
        } finally {
            config(['database.default' => $sqlite]);
            DB::purge('mysql');
        }

        $this->assertNotSame(0, $exitCode);
        $this->assertSame([], $this->benchmarkFiles());
    }

    /**
     * Garante pelo menos um evento pontual com prazo aberto e vagas: assim a agenda semanal tem
     * destinatários (e marcaria weekly_agenda_sent_at se não houvesse rollback), seja qual for a
     * distribuição aleatória dos eventos de carga.
     */
    private function createOpenEventSoEveryActiveMemberGetsAnAgenda(): void
    {
        $eventDate = now(config('app.event_timezone', 'Europe/Lisbon'))->addDays(3);

        EventInstance::factory()->create([
            'category_id' => EventCategory::query()->firstOrFail()->id,
            'location_id' => Location::query()->firstOrFail()->id,
            'name' => 'Carga: Evento aberto para a agenda semanal',
            'event_date' => $eventDate->toDateString(),
            'registration_deadline' => $eventDate->copy()->subDay()->setTime(23, 59)->format('Y-m-d H:i:s'),
            'max_attendees' => 50,
        ]);
    }
}
