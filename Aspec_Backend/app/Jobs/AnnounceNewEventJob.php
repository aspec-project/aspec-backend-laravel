<?php

namespace App\Jobs;

use App\Models\EventInstance;
use App\Models\User;
use App\Notifications\NewEventAnnouncementNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Anuncia por email um evento pontual acabado de criar no backoffice. Recebe só o id: o evento é
 * relido quando o job corre.
 */
class AnnounceNewEventJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $eventInstanceId) {}

    /**
     * Não envia nada se o evento já não existir, for uma sessão de uma série, já tiver sido
     * anunciado ou o prazo de inscrição já tiver passado. Antes de enviar, reclama o anúncio com um
     * update condicional: só o job que preenche announced_at envia, por isso uma repetição ou um
     * job duplicado nunca enviam o anúncio duas vezes. Os destinatários são lidos em lotes de 200
     * (sem carregar todos os membros em memória) e cada email vai para a fila à parte.
     */
    public function handle(): void
    {
        $event = EventInstance::find($this->eventInstanceId);

        if ($event === null
            || $event->series_id !== null
            || $event->announced_at !== null
            || $event->registrationDeadlinePassed()) {
            return;
        }

        $claimed = EventInstance::query()
            ->whereKey($event->id)
            ->whereNull('announced_at')
            ->update(['announced_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        User::query()
            ->receivesEventEmails('notify_new_events')
            ->chunkById(200, function (Collection $users) use ($event) {
                Notification::send($users, new NewEventAnnouncementNotification($event));
            });
    }
}
