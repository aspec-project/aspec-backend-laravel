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
     * Secção "A sua semana" e depois "Inscrições abertas" (cada uma só se tiver eventos), botão
     * para a agenda e rodapé das preferências. O link da reunião online só aparece na primeira
     * secção, com a regra da página do evento: o membro continua inscrito e o prazo já passou.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = $this->eventMail($notifiable)
            ->subject('Agenda semanal de eventos ASPEC')
            ->line('Estes são os eventos da ASPEC para os próximos dias.');

        if ($this->weekEvents->isNotEmpty()) {
            $mail->line('**A sua semana**');

            foreach ($this->weekEvents as $event) {
                $this->addEvent($mail, $event);
                $this->addOnlineLink($mail, $event, $notifiable);
            }
        }

        if ($this->openEvents->isNotEmpty()) {
            $mail->line('**Inscrições abertas**');

            foreach ($this->openEvents as $event) {
                $this->addEvent($mail, $event);
            }

            $mail->action('Ver a agenda', $this->frontendUrl('/eventos'));
        }

        return $mail->line($this->preferencesFooter());
    }

    private function addEvent(MailMessage $mail, EventInstance $event): void
    {
        $mail->line("**{$event->name}**: {$this->eventSummary($event)}. [Ver o evento]({$this->eventUrl($event)})");
    }

    private function addOnlineLink(MailMessage $mail, EventInstance $event, object $notifiable): void
    {
        if (! $event->is_online) {
            return;
        }

        if (! $event->registrationDeadlinePassed()) {
            $mail->line(self::LINK_PENDING_TEXT);

            return;
        }

        if ($event->registrations()->where('user_id', $notifiable->id)->exists()) {
            $mail->line("Link da reunião: {$event->online_url}");
        }
    }
}
