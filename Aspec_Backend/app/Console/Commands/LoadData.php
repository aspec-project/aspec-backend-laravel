<?php

namespace App\Console\Commands;

use Database\Seeders\LoadSeeder;
use Illuminate\Console\Command;

class LoadData extends Command
{
    protected $signature = 'aspec:load-data
        {--level=realistic : Volume: realistic (1 000 membros ativos) ou stress (10 000)}
        {--members= : Número de membros ativos (sobrepõe-se ao nível)}
        {--clean : Só apaga os dados de carga}';

    protected $description = 'Cria (ou apaga) dados de carga para medir o desempenho. Só em local/testing.';

    /**
     * Apaga os dados de carga e, sem --clean, volta a criá-los com o volume pedido (correr outra vez
     * repõe os mesmos dados). Recusa produção, staging e bases de dados fora desta máquina (o .env do
     * TiDB tem APP_ENV=staging e um host remoto), por isso nunca corre contra a base de dados do deploy.
     *
     * @return int SUCCESS se os dados foram criados ou apagados; FAILURE se o ambiente ou as opções forem recusados.
     */
    public function handle(): int
    {
        $refusal = LoadSeeder::refusal();

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $members = $this->option('clean') ? null : $this->activeMembers();

        if (! $this->option('clean') && $members === null) {
            return self::FAILURE;
        }

        $seeder = $this->laravel->make(LoadSeeder::class)->setContainer($this->laravel)->setCommand($this);
        $start = hrtime(true);

        $seeder->clean();

        if ($members === null) {
            $this->info(sprintf('Dados de carga apagados em %.1f s.', (hrtime(true) - $start) / 1e9));

            return self::SUCCESS;
        }

        $counts = $seeder->generate($members);

        $this->table(['Tabela', 'Linhas'], collect($counts)->map(fn (int $rows, string $table) => [$table, $rows])->values()->all());
        $this->info(sprintf('Dados de carga criados em %.1f s (%d membros ativos, %d linhas).', (hrtime(true) - $start) / 1e9, $members, array_sum($counts)));
        $this->warn('As imagens dos perfis de carga não existem (aparecem partidas no frontend). Antes de mostrar a demo: php artisan aspec:load-data --clean');

        return self::SUCCESS;
    }

    /**
     * Membros ativos pedidos: --members (inteiro, mínimo LoadSeeder::MIN_MEMBERS) ou o volume do nível.
     */
    private function activeMembers(): ?int
    {
        $members = $this->option('members');

        if ($members !== null) {
            if (! ctype_digit((string) $members) || (int) $members < LoadSeeder::MIN_MEMBERS) {
                $this->error('--members tem de ser um número inteiro igual ou superior a '.LoadSeeder::MIN_MEMBERS.'.');

                return null;
            }

            return (int) $members;
        }

        $level = (string) $this->option('level');

        if (! array_key_exists($level, LoadSeeder::LEVELS)) {
            $this->error('Nível desconhecido. Use --level=realistic ou --level=stress.');

            return null;
        }

        return LoadSeeder::LEVELS[$level];
    }
}
