<?php

namespace App\Notifications;

use App\Enums\SubscriptionInterval;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\Concerns\BuildsPaymentMail;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Stripe\Subscription as StripeSubscription;

/**
 * Aviso de renovação da quota anual, enviado alguns dias antes da cobrança (evento invoice.upcoming
 * do Stripe): uma cobrança grande uma vez por ano não deve ser surpresa. Em fila e só depois do
 * commit do webhook.
 */
class SubscriptionRenewalReminderNotification extends Notification implements ShouldQueue
{
    use BuildsPaymentMail, Queueable;

    /**
     * @param  CarbonInterface  $renewsAt  Data da cobrança prevista pelo Stripe.
     * @param  int  $amount  Valor a cobrar, em cêntimos (o da fatura do Stripe, não o do .env).
     * @param  string  $subscriptionId  Id Stripe da subscrição que vai ser renovada.
     */
    public function __construct(public CarbonInterface $renewsAt, public int $amount, public string $subscriptionId)
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
     * Re-verifica no envio as mesmas condições do webhook (conta Active, não isenta, subscrição em
     * curso sem cancelamento agendado): o email sai da fila mais tarde e, entretanto, o membro pode
     * ter cancelado ou a conta ter sido desativada.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return User::whereKey($notifiable->getKey())
            ->where('subscription_exempt', false)
            ->whereHas('accountStatus', fn ($query) => $query->where('name', 'Active'))
            ->whereHas('subscriptions', fn ($query) => $query
                ->where('stripe_id', $this->subscriptionId)
                ->whereNotIn('stripe_status', [StripeSubscription::STATUS_CANCELED, StripeSubscription::STATUS_INCOMPLETE_EXPIRED])
                ->whereNull('ends_at'))
            ->exists();
    }

    /**
     * Valor, data da renovação (em Lisboa) e botão para a página da subscrição, onde o membro pode
     * atualizar o cartão ou cancelar (o URL é gerado no envio).
     */
    public function toMail(object $notifiable): MailMessage
    {
        $interval = SubscriptionInterval::configured()->adjective();
        $amount = number_format($this->amount / 100, 2, ',', '');
        $date = $this->renewsAt->copy()->timezone(Invoice::TIMEZONE)->format('d/m/Y');
        $url = rtrim(config('app.frontend_url'), '/').'/conta/subscricao';

        return $this->paymentMail($notifiable)
            ->subject('A sua quota ASPEC vai ser renovada')
            ->line("A sua quota {$interval} de {$amount} € renova a {$date}.")
            ->line("Para atualizar o cartão ou cancelar, aceda a {$url}.")
            ->action('Gerir subscrição', $url);
    }
}
