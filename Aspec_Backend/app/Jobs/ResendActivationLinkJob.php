<?php

namespace App\Jobs;

use App\Services\Payments\SubscriptionService;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Procura a conta e envia o novo link de ativação/reativação depois de a resposta sair.
 *
 * Não implementa ShouldQueue de propósito: corre no fim do pedido (dispatchAfterResponse),
 * por isso o email pedido nunca fica guardado nas tabelas jobs/failed_jobs. Correr depois da
 * resposta faz o pedido demorar o mesmo para qualquer email, exista a conta ou não.
 */
class ResendActivationLinkJob
{
    use Dispatchable;

    public function __construct(public string $email) {}

    /**
     * Delega no SubscriptionService a regra de quem recebe o link.
     */
    public function handle(SubscriptionService $subscriptions): void
    {
        $subscriptions->resendLink($this->email);
    }
}
