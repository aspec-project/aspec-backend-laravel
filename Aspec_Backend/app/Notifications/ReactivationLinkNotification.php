<?php

namespace App\Notifications;

use App\Enums\InactiveReason;
use App\Notifications\Concerns\BuildsPaymentMail;
use App\Services\Payments\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email com o link de reativação de uma conta inativa por falta de pagamento ou por cancelamento
 * da subscrição (sem novo trial).
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
     * O texto depende do motivo lido no envio (serve o fim da carência, o fim de uma subscrição
     * cancelada, o reenvio e o desbloqueio). O link assinado é gerado no envio, para a fila nunca
     * guardar um link válido em claro.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $linkDays = config('subscription.reactivation_link_days');
        $newLinkUrl = rtrim(config('app.frontend_url'), '/').'/ativacao/novo-link';
        $mail = $this->paymentMail($notifiable);

        $mail = $notifiable->inactive_reason === InactiveReason::Cancelled
            ? $mail->subject('A sua subscrição ASPEC terminou')
                ->line('A sua subscrição terminou e a conta está inativa.')
                ->line("Pode reativá-la quando quiser: indique um cartão válido; a quota de {$this->formattedPrice()} € é cobrada de imediato (sem novo período experimental).")
            : $mail->subject('Reative a sua conta ASPEC')
                ->line('A sua conta está inativa por falta de pagamento da quota.')
                ->line("Para a reativar, indique um cartão válido; a quota de {$this->formattedPrice()} € é cobrada de imediato (sem novo período experimental).");

        return $mail
            ->action('Reativar conta', app(SubscriptionService::class)->activationUrl($notifiable))
            ->line("O link é válido durante {$linkDays} dias. Se expirar, peça um novo em {$newLinkUrl}.");
    }
}
