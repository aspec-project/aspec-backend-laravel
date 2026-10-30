<?php

namespace App\Console\Commands;

use App\Models\EventInstance;
use App\Services\PrayerCards\PrayerCardDistributor;
use Illuminate\Console\Command;
use Throwable;

/**
 * Distribuição automática dos cartões de oração no fim do prazo (agendada de hora a hora em
 * routes/console.php; também pode correr à mão).
 */
class DistributePrayerCards extends Command
{
    protected $signature = 'prayer-cards:distribute';

    protected $description = 'Distribui os cartões de oração dos eventos cujo prazo de preenchimento já terminou.';

    /**
     * Pega nos eventos com cartões ativos ainda não distribuídos cujo dia já passou (hora de Lisboa):
     * o prazo fecha às 23:59 do dia do evento. Apanha também os atrasados (servidor desligado à
     * meia-noite). Cada evento corre na sua transação; uma falha fica no log e não trava os outros,
     * e o comando termina com FAILURE para aparecer no log do scheduler. Correr outra vez não muda
     * nada: só a primeira distribuição de cada evento conta.
     */
    public function handle(PrayerCardDistributor $distributor): int
    {
        $today = now(config('app.event_timezone', 'Europe/Lisbon'))->toDateString();
        $distributed = $errors = 0;

        $events = EventInstance::query()
            ->where('prayer_cards_enabled', true)
            ->whereNull('prayer_cards_distributed_at')
            ->whereDate('event_date', '<', $today)
            ->orderBy('event_date')
            ->get();

        foreach ($events as $event) {
            try {
                if (! $distributor->distribute($event)->alreadyDistributed) {
                    $distributed++;
                }
            } catch (Throwable $e) {
                $errors++;
                report($e);
            }
        }

        $this->info("Eventos distribuídos: {$distributed}; erros: {$errors}");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
