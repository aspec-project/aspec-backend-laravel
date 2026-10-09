<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Subscrição e dados de faturação mostrados só ao próprio membro (rota sem id). Nunca expõe ids
 * do Stripe, o preço do Stripe, o cartão, a sessão de Checkout nem ids internos.
 */
class SubscriptionResource extends JsonResource
{
    /**
     * Espera a relação owner já definida. O trial_ends_at é o da subscrição (o Cashier mantém-no
     * sincronizado); o grace_ends_at é o do utilizador.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->owner;

        return [
            'status' => $this->stripe_status,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'grace_ends_at' => $user->grace_ends_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'price' => [
                'amount' => (float) config('subscription.price_amount'),
                'currency' => config('subscription.currency'),
                'interval' => config('subscription.interval'),
            ],
            'billing' => [
                'billing_name' => $user->billing_name,
                'nif' => $user->nif,
                'billing_address' => $user->billing_address,
                'billing_postal_code' => $user->billing_postal_code,
                'billing_city' => $user->billing_city,
            ],
        ];
    }
}
