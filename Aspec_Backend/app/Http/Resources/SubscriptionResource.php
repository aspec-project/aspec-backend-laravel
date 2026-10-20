<?php

namespace App\Http\Resources;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Stripe\Subscription as StripeSubscription;

/**
 * Subscrição e dados de faturação mostrados só ao próprio membro (rota sem id). Nunca expõe ids
 * do Stripe, o preço do Stripe, o cartão, a sessão de Checkout nem ids internos.
 */
class SubscriptionResource extends JsonResource
{
    /**
     * Espera a relação owner já definida. O trial_ends_at é o da subscrição (o Cashier mantém-no
     * sincronizado); o grace_ends_at é o do utilizador. cancel_at_period_end diz ao frontend se
     * mostra "Cancelar" ou "Retomar": subscrição a correr com fim agendado no futuro (uma
     * subscrição terminada também tem ends_at, por isso conta o estado).
     *
     * O trial começa quando a subscrição é criada (o Stripe conta os dias a partir daí) e a
     * duração vem das datas reais, não da configuração. Uma reativação não tem trial: os três
     * campos trial_* vêm a null.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->owner;
        $hasTrial = $this->trial_ends_at !== null;

        return [
            'status' => $this->stripe_status,
            'trial_starts_at' => $hasTrial ? $this->created_at->toIso8601String() : null,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'trial_days' => $hasTrial ? $this->trialDays() : null,
            'next_payment_at' => $this->nextPaymentAt()?->toIso8601String(),
            'grace_ends_at' => $user->grace_ends_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'cancel_at_period_end' => in_array($this->stripe_status, [StripeSubscription::STATUS_TRIALING, StripeSubscription::STATUS_ACTIVE], true)
                && $this->onGracePeriod(),
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

    /**
     * Duração do trial em dias, arredondada: a linha local é criada pelo webhook segundos depois
     * de o Stripe começar a contar, e esse atraso não pode tirar um dia ao trial.
     */
    private function trialDays(): int
    {
        return max(1, (int) round($this->created_at->diffInDays($this->trial_ends_at)));
    }

    /**
     * Data prevista da próxima cobrança, calculada só com dados locais (o Cashier não guarda o fim
     * do período). Em trial é o fim do trial; ativa é a fatura mais recente do membro (qualquer
     * estado, a linha nasce quando o Stripe confirma o pagamento) mais um intervalo, sem
     * transbordar para o mês seguinte (31/01 + 1 mês = 28/02). Null se não vai haver cobrança:
     * cancelamento agendado, pagamento em atraso, subscrição terminada ou ativa ainda sem faturas.
     */
    private function nextPaymentAt(): ?CarbonInterface
    {
        if ($this->ends_at !== null) {
            return null;
        }

        if ($this->stripe_status === StripeSubscription::STATUS_TRIALING) {
            return $this->trial_ends_at;
        }

        if ($this->stripe_status !== StripeSubscription::STATUS_ACTIVE) {
            return null;
        }

        $lastPaidAt = $this->owner->issuedInvoices()
            ->latest()
            ->orderByDesc('id')
            ->first()
            ?->created_at;

        if ($lastPaidAt === null) {
            return null;
        }

        return config('subscription.interval') === 'year'
            ? $lastPaidAt->copy()->addYearNoOverflow()
            : $lastPaidAt->copy()->addMonthNoOverflow();
    }
}
