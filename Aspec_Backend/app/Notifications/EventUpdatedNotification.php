<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $eventName,
        public ?string $justification = null,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Evento atualizado: {$this->eventName}")
            ->greeting('Olá,')
            ->line("O evento {$this->eventName} em que está inscrito foi atualizado.");

        if ($this->justification !== null && trim($this->justification) !== '') {
            $mail->line("Justificação: {$this->justification}");
        }

        return $mail;
    }
}
