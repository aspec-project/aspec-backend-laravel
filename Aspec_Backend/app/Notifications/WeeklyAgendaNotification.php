<?php

namespace App\Notifications;

use App\Models\EventInstance;
use App\Models\User;
use App\Notifications\Concerns\DescribesEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Agenda semanal de um membro, enviada pelo comando events:send-weekly-agenda. Os eventos são
 * relidos no envio (dados atuais); os que entretanto foram apagados desaparecem das listas.
 */
class WeeklyAgendaNotification extends Notification implements ShouldQueue
{
    use DescribesEvent, Queueable;

    public const LINK_PENDING_TEXT = 'O link será disponibilizado na página do evento após o fecho das inscrições.';

    /**
     * @param  Collection<int, EventInstance>  $weekEvents  "A sua semana": eventos em que está inscrito, por ordem de início.
     * @param  Collection<int, EventInstance>  $openEvents  "Inscrições abertas": até 10 eventos pontuais, por ordem de início.
     */
    public function __construct(
        public Collection $weekEvents,
        public Collection $openEvents,
    ) {}

    /**
     * Enviada só por email.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Volta a confirmar o destinatário no momento do envio: se a conta deixou de estar ativa ou
     * desligou a agenda enquanto o email esperava na fila, não é enviado (nem os links das reuniões).
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return User::query()
            ->receivesEventEmails('notify_weekly_agenda')
            ->whereKey($notifiable->getKey())
            ->exists();
    }

    /**
     * Secção "A sua semana" (cada evento com o título, os detalhes e um ícone pequeno para o
     * calendário; no fim, o botão para as inscrições do membro) e depois "Inscrições abertas" (os
     * eventos no mesmo formato e, no fim, o botão para a agenda); cada secção só aparece se tiver eventos. O link da reunião
     * online só aparece na primeira secção, com a regra da página do evento: o membro continua
     * inscrito e o prazo já passou.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $blocks = ['Estes são os eventos da ASPEC para os próximos dias.'];

        if ($this->weekEvents->isNotEmpty()) {
            $blocks[] = '**A sua semana**';

            foreach ($this->weekEvents as $event) {
                $blocks[] = $this->eventBlock($event);
                $blocks = [...$blocks, ...$this->onlineLinkLines($event, $notifiable)];
            }

            $blocks[] = $this->button('Ver as minhas inscrições', $this->frontendUrl('/perfil/inscricoes'));
        }

        if ($this->openEvents->isNotEmpty()) {
            $blocks[] = '**Inscrições abertas**';

            foreach ($this->openEvents as $event) {
                $blocks[] = $this->eventBlock($event);
            }

            $blocks[] = $this->button('Ver a agenda', $this->frontendUrl('/eventos'));
        }

        $blocks[] = $this->preferencesFooter();

        $mail = $this->eventMail($notifiable)->subject('Agenda semanal de eventos ASPEC');

        return $this->withBlocks($mail, $blocks);
    }

    /**
     * @return array{type: string, title: string, details: string, calendar_url: string}
     */
    private function eventBlock(EventInstance $event): array
    {
        return [
            'type' => 'event',
            'title' => $this->markdownText($event->name),
            'details' => $this->eventSummary($event).'.',
            'calendar_url' => $this->calendarUrl($event),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function onlineLinkLines(EventInstance $event, object $notifiable): array
    {
        if (! $event->is_online) {
            return [];
        }

        if (! $event->registrationDeadlinePassed()) {
            return [self::LINK_PENDING_TEXT];
        }

        if ($event->registrations()->where('user_id', $notifiable->id)->exists()) {
            return ["Link da reunião: {$event->online_url}"];
        }

        return [];
    }
}
