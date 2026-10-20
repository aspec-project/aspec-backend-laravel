<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $eventName,
        public string $eventDate,
        public string $eventStartTime,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Evento cancelado: {$this->eventName}")
            ->greeting('Olá,')
            ->line(
                "O evento {$this->eventName}, previsto para {$this->eventDate} às {$this->eventStartTime}, foi cancelado."
            )
            ->line('A sua inscrição foi cancelada.')
            ->line('Lamentamos o incómodo.');
    }
}
