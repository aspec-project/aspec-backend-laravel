<?php

namespace App\Console\Commands;

use App\Enums\InactiveReason;
use App\Models\AccountStatus;
use App\Models\User;
use App\Services\Payments\StripeSubscriptionService;
use App\Services\Payments\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Subscription as StripeSubscription;
use Throwable;

/**
 * Fim do período de carência (agendado de hora a hora em routes/console.php). É a fonte de
 * verdade do fim da carência; o cancelamento automático do Stripe é só a rede de segurança.
 */
class EndGracePeriods extends Command
{
    private const RUNNING_STATUSES = [StripeSubscription::STATUS_TRIALING, StripeSubscription::STATUS_ACTIVE];

    private const ENDED_STATUSES = [StripeSubscription::STATUS_CANCELED, StripeSubscription::STATUS_INCOMPLETE_EXPIRED];

    protected $signature = 'subscriptions:end-grace';

    protected $description = 'Desativa as contas Active cuja carência terminou sem pagamento (confirmado no Stripe).';

    /**
     * Para cada conta Active com a carência expirada, isolada das outras:
     * 1. confirma no Stripe o estado da subscrição em curso (desativar quem pagou é o pior erro):
     *    trialing/active → só limpa a carência; terminada → marca-a cancelada localmente;
     * 2. relê a conta com lock e, se ainda estiver Active com a carência expirada, desativa-a
     *    (motivo unpaid) e envia o link de reativação depois do commit;
     * 3. depois do commit, cancela a subscrição pelo mesmo caminho do bloqueio
     *    (SubscriptionService::cancel(), que nunca lança por causa do Stripe).
     * Uma falha do Stripe no passo 1 deixa a conta como está (tenta na hora seguinte) e o
     * comando termina com FAILURE, para aparecer no log do scheduler.
     */
    public function handle(StripeSubscriptionService $stripe, SubscriptionService $subscriptions): int
    {
        $deactivated = $resolved = $errors = 0;

        $users = User::where('account_status_id', AccountStatus::where('name', 'Active')->value('id'))
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<=', now())
            ->get();

        foreach ($users as $user) {
            try {
                if ($this->paidInStripe($user, $stripe)) {
                    $resolved++;

                    continue;
                }

                if ($this->deactivateIfStillInGrace($user, $subscriptions)) {
                    $subscriptions->cancel($user);
                    $deactivated++;
                }
            } catch (ApiErrorException $e) {
                $errors++;
                Log::warning('Fim da carência adiado: o Stripe não respondeu.', [
                    'user_id' => $user->id,
                    'exception' => $e::class,
                    'stripe_request_id' => $e->getRequestId(),
                ]);
            } catch (Throwable $e) {
                $errors++;
                report($e);
            }
        }

        $this->info("Contas desativadas: {$deactivated}; carências resolvidas: {$resolved}; erros: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Pergunta ao Stripe o estado da subscrição em curso. Se já estiver paga (o webhook perdeu-se
     * ou atrasou-se) atualiza a linha local e limpa a carência. Uma subscrição já terminada no
     * Stripe fica marcada cancelada, para o cancel() seguinte não voltar a chamá-lo para ela.
     * Sem subscrição em curso não há nada a perguntar.
     * A pergunta ao Stripe fica fora da transação (sem locks durante a rede). Na transação, o
     * utilizador é bloqueado antes de escrever a subscrição, a mesma ordem dos webhooks e do
     * cancelar/retomar: com a ordem inversa, duas transações podiam esperar uma pela outra
     * (deadlock no MySQL).
     *
     * @return bool true se a subscrição está paga e a conta deve continuar ativa.
     *
     * @throws ApiErrorException Se o Stripe falhar.
     */
    private function paidInStripe(User $user, StripeSubscriptionService $stripe): bool
    {
        $subscription = $user->currentSubscription();

        if (! $subscription || in_array($subscription->stripe_status, self::ENDED_STATUSES, true)) {
            return false;
        }

        $status = $stripe->retrieveStatus($subscription);

        if (in_array($status, self::RUNNING_STATUSES, true)) {
            DB::transaction(function () use ($subscription, $status, $user) {
                User::whereKey($user->id)->lockForUpdate()->first();
                $subscription->forceFill(['stripe_status' => $status])->save();
                User::whereKey($user->id)->update(['grace_ends_at' => null]);
            });

            return true;
        }

        if (in_array($status, self::ENDED_STATUSES, true)) {
            $subscription->markAsCanceled();
        }

        return false;
    }

    /**
     * Desativa a conta (unpaid) e envia o link de reativação, decidindo pelo estado relido com
     * lockForUpdate: um pagamento recebido pelo webhook ou um bloqueio do admin entre a seleção
     * e este ponto ganham.
     *
     * @return bool true se a conta foi desativada.
     */
    private function deactivateIfStillInGrace(User $user, SubscriptionService $subscriptions): bool
    {
        return DB::transaction(function () use ($user, $subscriptions) {
            $locked = User::with('accountStatus')->whereKey($user->id)->lockForUpdate()->first();

            if (! $locked
                || $locked->accountStatus?->name !== 'Active'
                || $locked->grace_ends_at === null
                || $locked->grace_ends_at->isFuture()) {
                return false;
            }

            $locked->deactivate(InactiveReason::Unpaid->value);
            $subscriptions->sendReactivationLink($locked);

            return true;
        });
    }
}
