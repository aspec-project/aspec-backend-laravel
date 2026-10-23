<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserRejectedNotification extends Notification
{
    public function __construct(public ?string $reason = null) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('A sua candidatura à ASPEC não foi aceite')
            ->greeting('Olá!')
            ->line('A sua candidatura à ASPEC não foi aceite.');

        if ($this->reason !== null && trim($this->reason) !== '') {
            $mail->line('Motivo: '.trim($this->reason));
        }

        return $mail
            ->line('Poderá apresentar uma nova candidatura com o mesmo endereço de email.')
            ->salutation("Cumprimentos,\nASPEC");
    }
}
