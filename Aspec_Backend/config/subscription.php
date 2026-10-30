<?php

/*
| Valores de negócio da subscrição, definidos pelo cliente a 30/10/2026: quota de 30 € por ano e
| 30 dias de período experimental. O preço cobrado é o do Price no Stripe, price_amount tem de bater
| certo com ele. Sem segredos aqui: as chaves do Stripe ficam só no .env.
*/

return [
    'price_id' => env('STRIPE_PRICE_ID'),
    // Só para mostrar ao membro; quem cobra é o Price do Stripe.
    'price_amount' => (float) env('SUBSCRIPTION_PRICE_AMOUNT', 30.00),
    'currency' => env('CASHIER_CURRENCY', 'eur'),
    // month ou year (App\Enums\SubscriptionInterval); tem de bater certo com o recurring.interval
    // do Price do Stripe (verificar com php artisan subscriptions:check-price).
    'interval' => env('SUBSCRIPTION_INTERVAL', 'year'),
    'trial_days' => (int) env('SUBSCRIPTION_TRIAL_DAYS', 30),
    'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 7),
    'activation_link_days' => (int) env('ACTIVATION_LINK_DAYS', 7),
    'reactivation_link_days' => (int) env('REACTIVATION_LINK_DAYS', 7),
    // Obrigatório fora de local/testing.
    'billing_portal_configuration' => env('STRIPE_BILLING_PORTAL_CONFIGURATION'),
    // O job acrescenta o período pago (ex. "Quota Anual ASPEC — período de 28/10/2026 a 27/10/2027").
    'invoice_description' => env('SUBSCRIPTION_INVOICE_DESCRIPTION', 'Quota Anual ASPEC'),
];
