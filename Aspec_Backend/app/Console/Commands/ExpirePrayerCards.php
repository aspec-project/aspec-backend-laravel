<?php

namespace App\Console\Commands;

use App\Models\EventInstance;
use App\Models\PrayerCard;
use Illuminate\Console\Command;

/**
 * Expiração dos cartões de oração (agendada todos os dias às 03:00 de Lisboa em routes/console.php).
 */
class ExpirePrayerCards extends Command
{
    protected $signature = 'prayer-cards:expire';

    protected $description = 'Apaga o conteúdo dos cartões de oração de eventos com mais de 6 meses (soft delete).';

    /**
     * Cartões de eventos com data anterior a hoje − 6 meses (Lisboa), distribuídos ou não, ficam sem
     * os pedidos nem o destinatário e com soft delete numa só query: a linha fica só como prova de presença.
     * Idempotente: os já apagados ficam de fora pelo SoftDeletes.
     */
    public function handle(): int
    {
        $limit = now(config('app.event_timezone', 'Europe/Lisbon'))
            ->subMonthsNoOverflow((int) config('prayer_cards.retention_months'))
            ->toDateString();

        $expired = PrayerCard::query()
            ->whereIn('event_instance_id', EventInstance::query()->select('id')->whereDate('event_date', '<', $limit))
            ->update([...PrayerCard::erasedAttributes(), 'deleted_at' => now()]);

        $this->info("Cartões expirados: {$expired}");

        return self::SUCCESS;
    }
}
