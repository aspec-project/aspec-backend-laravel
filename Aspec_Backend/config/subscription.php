<?php

/*
| Valores de negócio da subscrição. Valores a confirmar com o cliente (trial, preço); o preço
| cobrado é o do Price no Stripe, price_amount tem de bater certo com ele. Sem segredos aqui:
| as chaves do Stripe ficam só no .env.
*/

return [
    'price_id' => env('STRIPE_PRICE_ID'),
    // Só para mostrar ao membro; quem cobra é o Price do Stripe.
    'price_amount' => (float) env('SUBSCRIPTION_PRICE_AMOUNT', 60.00),
    'currency' => env('CASHIER_CURRENCY', 'eur'),
    'interval' => 'month',
    'trial_days' => (int) env('SUBSCRIPTION_TRIAL_DAYS', 30),
    'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 7),
    'activation_link_days' => (int) env('ACTIVATION_LINK_DAYS', 7),
    'reactivation_link_days' => (int) env('REACTIVATION_LINK_DAYS', 7),
    // Obrigatório fora de local/testing.
    'billing_portal_configuration' => env('STRIPE_BILLING_PORTAL_CONFIGURATION'),
    // O job acrescenta o mês do pagamento (ex. "Quota mensal ASPEC — outubro 2026").
    'invoice_description' => env('SUBSCRIPTION_INVOICE_DESCRIPTION', 'Quota mensal ASPEC'),
];
