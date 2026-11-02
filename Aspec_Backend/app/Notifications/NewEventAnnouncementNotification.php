<?php

namespace App\Notifications;

use App\Models\EventInstance;
use App\Models\User;
use App\Notifications\Concerns\DescribesEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Anúncio de um evento pontual acabado de criar, enviado pelo AnnounceNewEventJob aos membros
 * com a preferência ligada. Em fila: um email por membro, por isso uma falha só repete esse email.
 */
class NewEventAnnouncementNotification extends Notification implements ShouldQueue
{
    use DescribesEvent, Queueable;

    /**
     * Se o evento for apagado antes do envio, o email é descartado sem erro.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Recebe o evento (relido da base de dados no envio, por isso mostra os dados atuais).
     */
    public function __construct(public EventInstance $event) {}

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
     * desligou os anúncios enquanto o email esperava na fila, não é enviado.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return User::query()
            ->receivesEventEmails('notify_new_events')
            ->whereKey($notifiable->getKey())
            ->exists();
    }

    /**
     * Nome, data, horas, local e prazo de inscrição, com botão para a página do evento.
     * Nunca inclui o link da reunião online: só os inscritos o recebem, depois do fecho das inscrições.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->eventMail($notifiable)
            ->subject("Novo evento ASPEC: {$this->event->name}")
            ->line("A ASPEC tem um novo evento: **{$this->event->name}**.")
            ->line($this->eventSummary($this->event))
            ->line("Inscrições abertas até {$this->registrationDeadlineDate($this->event)}.")
            ->action('Ver o evento', $this->eventUrl($this->event))
            ->line($this->preferencesFooter());
    }
}
