<?php

namespace App\Notifications\Concerns;

use App\Enums\SubscriptionInterval;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Partes comuns aos emails de pagamento: saudação com o nome profissional, despedida e preço
 * (com a periodicidade só onde se diz o valor a pagar).
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
     * Preço da quota configurado, no formato português (ex. "20,00").
     */
    protected function formattedPrice(): string
    {
        return number_format((float) config('subscription.price_amount'), 2, ',', '');
    }

    /**
     * Preço da quota com a periodicidade configurada (ex. "20,00 € por ano").
     */
    protected function formattedPriceWithInterval(): string
    {
        return "{$this->formattedPrice()} € ".SubscriptionInterval::configured()->perPeriod();
    }
}
