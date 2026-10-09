<?php

namespace Tests\Concerns;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Webhooks do Stripe assinados à mão, no formato da API 2025-06-30.basil.
 * Nenhum teste fala com o Stripe: o corpo e a assinatura são gerados aqui com o segredo de teste.
 */
trait SignsStripeWebhooks
{
    protected const STRIPE_API_VERSION = '2025-06-30.basil';

    /**
     * Envia o payload assinado para o webhook.
     *
     * Usa call() e não postJson(): a assinatura é calculada sobre o corpo exato, e o postJson
     * voltaria a codificar o array. time() e não now(): o SDK do Stripe valida com time(), que
     * o travel() não altera.
     */
    protected function postStripeWebhook(array $payload, ?string $secret = null, ?int $timestamp = null): TestResponse
    {
        $json = json_encode($payload);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$json}", $secret ?? 'whsec_test');

        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $json);
    }

    protected function stripeEvent(string $type, array $object, ?string $id = null): array
    {
        return [
            'id' => $id ?? 'evt_test_'.Str::random(14),
            'object' => 'event',
            'api_version' => self::STRIPE_API_VERSION,
            'created' => time(),
            'type' => $type,
            'data' => ['object' => $object],
        ];
    }

    /**
     * Fatura paga de uma subscrição. Sem o metadata is_on_session_checkout: com ele, o handler
     * do Cashier chamaria a API do Stripe.
     */
    protected function stripeInvoice(string $customer, int $amountPaid = 6000, ?string $id = null): array
    {
        return [
            'id' => $id ?? 'in_test_'.Str::random(14),
            'object' => 'invoice',
            'customer' => $customer,
            'amount_paid' => $amountPaid,
            'currency' => 'eur',
            'status' => 'paid',
            'billing_reason' => 'subscription_cycle',
            'created' => time(),
            'parent' => [
                'type' => 'subscription_details',
                'subscription_details' => [
                    'subscription' => 'sub_test_1',
                    'metadata' => [],
                ],
            ],
        ];
    }

    /**
     * Subscrição no formato do evento. O id do item deriva do id da subscrição, porque
     * subscription_items.stripe_id é único (duas subscrições precisam de itens diferentes).
     */
    protected function stripeSubscription(string $customer, string $status = 'trialing', ?string $id = null): array
    {
        $id ??= 'sub_test_1';

        return [
            'id' => $id,
            'object' => 'subscription',
            'customer' => $customer,
            'status' => $status,
            'trial_end' => $status === 'trialing' ? time() + 30 * 24 * 3600 : null,
            'cancel_at_period_end' => false,
            'items' => [
                'data' => [[
                    'id' => 'si_'.Str::after($id, 'sub_'),
                    'price' => ['id' => 'price_test', 'product' => 'prod_test'],
                    'quantity' => 1,
                ]],
            ],
            'metadata' => [],
        ];
    }
}
