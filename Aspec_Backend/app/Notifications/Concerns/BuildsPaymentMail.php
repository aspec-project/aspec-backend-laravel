<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Partes comuns aos emails de pagamento: saudação com o nome profissional, despedida e preço.
 */
trait BuildsPaymentMail
{
    /**
     * Email já com saudação ("Olá, {nome}!") e despedida da ASPEC.
     */
    protected function paymentMail(object $notifiable): MailMessage
    {
        $name = $notifiable->memberProfile?->name;

        return (new MailMessage)
            ->greeting($name ? "Olá, {$name}!" : 'Olá!')
            ->salutation("Cumprimentos,\nASPEC");
    }

    /**
     * Preço mensal no formato português (ex. "60,00").
     */
    protected function formattedPrice(): string
    {
        return number_format((float) config('subscription.price_amount'), 2, ',', '');
    }
}
