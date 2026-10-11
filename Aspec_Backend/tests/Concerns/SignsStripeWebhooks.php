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

    /**
     * Evento do Stripe. $previousAttributes vai para data.previous_attributes (só nos eventos
     * *.updated, e só se não for vazio), com os valores antes da alteração.
     */
    protected function stripeEvent(string $type, array $object, ?string $id = null, array $previousAttributes = []): array
    {
        $data = ['object' => $object];

        if ($previousAttributes !== []) {
            $data['previous_attributes'] = $previousAttributes;
        }

        return [
            'id' => $id ?? 'evt_test_'.Str::random(14),
            'object' => 'event',
            'api_version' => self::STRIPE_API_VERSION,
            'created' => time(),
            'type' => $type,
            'data' => $data,
        ];
    }

    /**
     * Fatura de uma subscrição (paga por omissão). Sem o metadata is_on_session_checkout: com ele,
     * o handler do Cashier chamaria a API do Stripe.
     *
     * Numa fatura não paga (ex. "open" num invoice.payment_failed) o valor fica em amount_due e o
     * amount_paid é 0, como no Stripe. Sem subscrição (null) é uma fatura avulsa: parent null.
     */
    protected function stripeInvoice(
        string $customer,
        int $amountPaid = 6000,
        ?string $id = null,
        string $status = 'paid',
        ?string $subscription = 'sub_test_1',
    ): array {
        $paid = $status === 'paid';

        return [
            'id' => $id ?? 'in_test_'.Str::random(14),
            'object' => 'invoice',
            'customer' => $customer,
            'amount_due' => $amountPaid,
            'amount_paid' => $paid ? $amountPaid : 0,
            'currency' => 'eur',
            'status' => $status,
            'billing_reason' => $subscription ? 'subscription_cycle' : 'manual',
            'created' => time(),
            'parent' => $subscription ? [
                'type' => 'subscription_details',
                'subscription_details' => [
                    'subscription' => $subscription,
                    'metadata' => [],
                ],
            ] : null,
        ];
    }

    /**
     * Subscrição no formato do evento. O id do item deriva do id da subscrição, porque
     * subscription_items.stripe_id é único (duas subscrições precisam de itens diferentes).
     * $overrides substitui chaves de topo (ex. cancel_at_period_end, cancellation_details, trial_end).
     */
    protected function stripeSubscription(string $customer, string $status = 'trialing', ?string $id = null, array $overrides = []): array
    {
        $id ??= 'sub_test_1';

        return array_merge([
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
        ], $overrides);
    }
}
