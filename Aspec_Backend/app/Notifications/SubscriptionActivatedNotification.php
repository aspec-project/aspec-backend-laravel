<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsPaymentMail;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Boas-vindas quando o webhook ativa a conta: com a data do fim do trial (ativação) ou a
 * indicar que a conta foi reativada. Em fila e só depois do commit do webhook.
 */
class SubscriptionActivatedNotification extends Notification implements ShouldQueue
{
    use BuildsPaymentMail, Queueable;

    /**
     * @param  CarbonInterface|null  $trialEndsAt  Fim do período experimental; null na reativação.
     */
    public function __construct(public ?CarbonInterface $trialEndsAt)
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
     * Mensagem de boas-vindas (com trial) ou de reativação (sem trial), com botão para o login.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = $this->paymentMail($notifiable)->subject('A sua conta ASPEC está ativa');

        $mail = $this->trialEndsAt
            ? $mail->line("Bem-vindo(a) à ASPEC! O período experimental termina a {$this->trialEndsAt->format('d/m/Y')}; nessa data é cobrada a primeira quota de {$this->formattedPrice()} €.")
            : $mail->line('A sua conta foi reativada. A quota foi cobrada e a fatura segue num email à parte.');

        return $mail->action('Iniciar sessão', rtrim(config('app.frontend_url'), '/').'/login');
    }
}
