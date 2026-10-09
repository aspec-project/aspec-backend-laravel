<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsPaymentMail;
use App\Services\Payments\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email com o link de ativação, enviado quando um admin aprova a candidatura (ou desbloqueia
 * quem nunca subscreveu). Em fila e só depois do commit: uma aprovação desfeita não envia email.
 */
class ActivationLinkNotification extends Notification implements ShouldQueue
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
     * O link assinado é gerado aqui, no envio, e não no construtor: assim a fila nunca guarda um
     * link válido em claro e a validade conta a partir do envio.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $trialDays = config('subscription.trial_days');
        $linkDays = config('subscription.activation_link_days');
        $newLinkUrl = rtrim(config('app.frontend_url'), '/').'/ativacao/novo-link';

        return $this->paymentMail($notifiable)
            ->subject('Ative a sua conta ASPEC')
            ->line('A sua candidatura à ASPEC foi aprovada.')
            ->line("Para ativar a conta, indique os dados de faturação e o cartão. Tem {$trialDays} dias de período experimental sem custos; depois, a quota é de {$this->formattedPrice()} € por mês.")
            ->action('Ativar conta', app(SubscriptionService::class)->activationUrl($notifiable))
            ->line("O link é válido durante {$linkDays} dias. Se expirar, peça um novo em {$newLinkUrl}.");
    }
}
