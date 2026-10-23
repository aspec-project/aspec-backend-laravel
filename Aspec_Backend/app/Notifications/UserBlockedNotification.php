<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserBlockedNotification extends Notification
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
            ->subject('A sua conta ASPEC foi bloqueada')
            ->greeting('Olá!')
            ->line('A sua conta de membro ASPEC foi bloqueada.');

        if ($this->reason !== null && trim($this->reason) !== '') {
            $mail->line('Motivo: '.trim($this->reason));
        }

        return $mail->salutation("Cumprimentos,\nASPEC");
    }
}
