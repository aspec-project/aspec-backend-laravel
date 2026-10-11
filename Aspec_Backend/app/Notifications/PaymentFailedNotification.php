<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsPaymentMail;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de que a cobrança da quota falhou: a conta continua ativa até ao fim do período de
 * carência e o membro deve atualizar o cartão. Em fila e só depois do commit do webhook.
 */
class PaymentFailedNotification extends Notification implements ShouldQueue
{
    use BuildsPaymentMail, Queueable;

    /**
     * @param  CarbonInterface  $graceEndsAt  Fim do período de carência.
     */
    public function __construct(public CarbonInterface $graceEndsAt)
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
     * Prazo da carência e botão para a página da subscrição (o URL é gerado no envio).
     */
    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->graceEndsAt->format('d/m/Y');

        return $this->paymentMail($notifiable)
            ->subject('Não foi possível cobrar a quota ASPEC')
            ->line("Não conseguimos cobrar a quota mensal de {$this->formattedPrice()} €.")
            ->line("A sua conta continua ativa até {$date}. Atualize o cartão até essa data para não perder o acesso; voltaremos a tentar a cobrança.")
            ->action('Atualizar cartão', rtrim(config('app.frontend_url'), '/').'/conta/subscricao')
            ->line("Se a quota não for paga até {$date}, a conta fica inativa e deixa de aparecer no diretório.");
    }
}
