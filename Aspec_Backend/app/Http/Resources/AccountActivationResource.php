<?php

namespace App\Http\Resources;

use App\Enums\ActivationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dados mostrados na página de ativação: tipo, email, nome e preço. Nunca expõe faturação,
 * NIF nem dados do Stripe (o link não exige login).
 */
class AccountActivationResource extends JsonResource
{
    /**
     * Na reativação trial_days é 0 (não há novo período experimental).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->activationType();

        return [
            'type' => $type?->value,
            'email' => $this->email,
            'name' => $this->memberProfile?->name,
            'trial_days' => $type === ActivationType::Activation ? (int) config('subscription.trial_days') : 0,
            'price' => [
                'amount' => (float) config('subscription.price_amount'),
                'currency' => config('subscription.currency'),
                'interval' => config('subscription.interval'),
            ],
        ];
    }
}
