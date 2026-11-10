<?php

namespace App\Notifications;

use App\Notifications\Concerns\EscapesMarkdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserBlockedNotification extends Notification
{
    use EscapesMarkdown;

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
            $mail->line('Motivo: '.$this->markdownText(trim($this->reason)));
        }

        return $mail->salutation("Cumprimentos,\nASPEC");
    }
}
