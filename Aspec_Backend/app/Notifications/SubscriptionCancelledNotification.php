<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsPaymentMail;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmação de que o membro agendou o cancelamento da subscrição. Também protege contra uma
 * sessão roubada: o membro fica a saber e pode retomar. Em fila e só depois do commit.
 */
class SubscriptionCancelledNotification extends Notification implements ShouldQueue
{
    use BuildsPaymentMail, Queueable;

    /**
     * @param  CarbonInterface  $endsAt  Fim do acesso (fim do trial ou do período pago).
     */
    public function __construct(public CarbonInterface $endsAt)
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
     * Data do fim do acesso e botão para a página da subscrição (o URL é gerado no envio).
     */
    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->endsAt->format('d/m/Y');

        return $this->paymentMail($notifiable)
            ->subject('Cancelamento da subscrição ASPEC agendado')
            ->line("Pediu o cancelamento da subscrição. Mantém o acesso até {$date}; depois a conta fica inativa e não há mais cobranças.")
            ->line('Mudou de ideias? Pode retomar a subscrição até essa data.')
            ->action('Gerir subscrição', rtrim(config('app.frontend_url'), '/').'/conta/subscricao')
            ->line('Se não foi você, altere a password e retome a subscrição.');
    }
}
