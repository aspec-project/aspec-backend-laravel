<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsPaymentMail;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmação de que o membro retomou a subscrição (as cobranças voltam). Como no cancelamento,
 * avisa o membro se não foi ele. Em fila e só depois do commit.
 */
class SubscriptionResumedNotification extends Notification implements ShouldQueue
{
    use BuildsPaymentMail, Queueable;

    /**
     * @param  CarbonInterface  $renewsAt  Próxima renovação (fim do trial ou do período pago).
     */
    public function __construct(public CarbonInterface $renewsAt)
    {
        $this->afterCommit();
    }

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
     * Data e valor da próxima cobrança e botão para a página da subscrição (URL gerado no envio).
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->paymentMail($notifiable)
            ->subject('A sua subscrição ASPEC foi retomada')
            ->line('A sua subscrição foi retomada e continua ativa.')
            ->line("A renovação mantém-se a {$this->renewsAt->format('d/m/Y')}, com a cobrança da quota de {$this->formattedPrice()} €.")
            ->action('Gerir subscrição', rtrim(config('app.frontend_url'), '/').'/conta/subscricao')
            ->line('Se não foi você, altere a password e cancele de novo a subscrição.');
    }
}
