<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Payments\StripeCustomerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteStripeCustomerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Guarda só o id do utilizador: o payload fica nas tabelas jobs/failed_jobs, sem dados pessoais.
     */
    public function __construct(public string $userId)
    {
    }

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
     * Apaga o cliente Stripe de um utilizador anonimizado e limpa o stripe_id.
     * Idempotente: sem utilizador ou sem stripe_id não faz nada.
     *
     * @throws \Stripe\Exception\ApiErrorException Propaga para a fila voltar a tentar.
     */
    public function handle(StripeCustomerService $customers): void
    {
        $user = User::withTrashed()->find($this->userId);

        if (! $user || blank($user->stripe_id)) {
            return;
        }

        $customers->delete($user->stripe_id);

        $user->forceFill(['stripe_id' => null])->save();
    }

    /**
     * Esgotadas as tentativas, regista o erro (sem email nem NIF) para um admin apagar o
     * cliente à mão no dashboard do Stripe.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Não foi possível apagar o cliente Stripe; apagar manualmente no dashboard.', [
            'user_id'   => $this->userId,
            'exception' => $e::class,
        ]);
    }
}
