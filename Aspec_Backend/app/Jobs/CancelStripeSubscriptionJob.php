<?php

namespace App\Jobs;

use App\Services\Payments\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class CancelStripeSubscriptionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Guarda o retrato do que o bloqueio tinha para cancelar: id do utilizador e ids do Stripe
     * (sub_…, cs_…), sem dados pessoais, porque o payload fica nas tabelas jobs/failed_jobs.
     *
     * @param  array<int, string>  $subscriptionStripeIds
     */
    public function __construct(
        public string $userId,
        public array $subscriptionStripeIds,
        public ?string $checkoutSessionId,
    ) {}

    /**
     * Esperas crescentes entre tentativas (segundos): 1 min, 5 min, 15 min, 1 h.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    /**
     * Repete o cancelamento que falhou no bloqueio, exatamente para o retrato recebido e sem olhar
     * para o estado atual da conta: a subscrição antiga não pode continuar a cobrar se a conta
     * voltou a Active, e uma sessão ou subscrição nova (depois de um desbloqueio) não é tocada.
     * Idempotente (o que já está cancelado ou fechado não volta a falhar).
     *
     * @throws \Stripe\Exception\ApiErrorException Propaga para a fila voltar a tentar.
     */
    public function handle(SubscriptionService $subscriptions): void
    {
        $subscriptions->cancelOrFail($this->userId, $this->subscriptionStripeIds, $this->checkoutSessionId);
    }

    /**
     * Esgotadas as tentativas, regista os ids do Stripe (sem email nem NIF) para um admin cancelar
     * à mão no dashboard do Stripe.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Não foi possível cancelar a subscrição Stripe; cancelar manualmente no dashboard.', [
            'user_id' => $this->userId,
            'subscription_stripe_ids' => $this->subscriptionStripeIds,
            'checkout_session_id' => $this->checkoutSessionId,
            'exception' => $e::class,
        ]);
    }
}
