<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsPaymentMail;
use App\Services\Payments\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email com o link de reativação de uma conta inativa por falta de pagamento (sem novo trial).
 * Em fila e só depois do commit.
 */
class ReactivationLinkNotification extends Notification implements ShouldQueue
{
    use BuildsPaymentMail, Queueable;

    public function __construct()
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
     * O link assinado é gerado no envio, para a fila nunca guardar um link válido em claro.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $linkDays = config('subscription.reactivation_link_days');
        $newLinkUrl = rtrim(config('app.frontend_url'), '/').'/ativacao/novo-link';

        return $this->paymentMail($notifiable)
            ->subject('Reative a sua conta ASPEC')
            ->line('A sua conta está inativa por falta de pagamento da quota.')
            ->line("Para a reativar, indique um cartão válido; a quota de {$this->formattedPrice()} € é cobrada de imediato (sem novo período experimental).")
            ->action('Reativar conta', app(SubscriptionService::class)->activationUrl($notifiable))
            ->line("O link é válido durante {$linkDays} dias. Se expirar, peça um novo em {$newLinkUrl}.");
    }
}
