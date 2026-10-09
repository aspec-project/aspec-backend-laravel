<?php

namespace App\Services\Payments;

use App\Enums\ActivationType;
use App\Exceptions\ActivationLinkNoLongerValidException;
use App\Exceptions\SubscriptionInProgressException;
use App\Jobs\CancelStripeSubscriptionJob;
use App\Models\User;
use App\Notifications\ActivationLinkNotification;
use App\Notifications\ReactivationLinkNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;
use Stripe\Subscription as StripeSubscription;

/**
 * Regras de domínio da subscrição: links de ativação/reativação e início do pagamento sem
 * duplicados. As chamadas ao Stripe passam pelo StripeSubscriptionService.
 */
class SubscriptionService
{
    private const ENDED_STATUSES = [StripeSubscription::STATUS_CANCELED, StripeSubscription::STATUS_INCOMPLETE_EXPIRED];

    public function __construct(private StripeSubscriptionService $stripe) {}

    /**
     * Link do email: página de ativação do frontend com a assinatura e a validade de um URL
     * assinado relativo da API. O frontend chama a API com o mesmo caminho e query, por isso a
     * assinatura não depende do host. Validade configurável (ativação e reativação à parte).
     */
    public function activationUrl(User $user): string
    {
        $days = $user->activationType() === ActivationType::Reactivation
            ? config('subscription.reactivation_link_days')
            : config('subscription.activation_link_days');

        $signed = URL::temporarySignedRoute(
            'account-activations.show',
            now()->addDays($days),
            ['user' => $user->id],
            absolute: false,
        );

        return rtrim(config('app.frontend_url'), '/')."/ativacao/{$user->id}?".parse_url($signed, PHP_URL_QUERY);
    }

    /**
     * Envia (em fila, depois do commit) o email com o link de ativação de uma conta aprovada.
     */
    public function sendActivationLink(User $user): void
    {
        $user->notify(new ActivationLinkNotification);
    }

    /**
     * Envia (em fila, depois do commit) o email com o link de reativação de uma conta sem pagamento.
     */
    public function sendReactivationLink(User $user): void
    {
        $user->notify(new ReactivationLinkNotification);
    }

    /**
     * Envia um novo link a contas Approved (ativação) ou Inactive por falta de pagamento
     * (reativação); outras contas e emails inexistentes não recebem nada. A comparação do email
     * é exata, como no pedido de reposição de password.
     */
    public function resendLink(string $email): void
    {
        $user = User::with('accountStatus')->where('email', $email)->first();

        match ($user?->activationType()) {
            ActivationType::Activation => $this->sendActivationLink($user),
            ActivationType::Reactivation => $this->sendReactivationLink($user),
            null => null,
        };
    }

    /**
     * Deixa de cobrar a conta (usado no bloqueio): expira a sessão de Checkout guardada e cancela
     * de imediato as subscrições não terminadas. Pré-condição: chamar depois do commit do
     * deactivate(), porque é o estado já gravado que impede sessões novas depois do retrato.
     *
     * O que cancelar é lido da base de dados com o utilizador bloqueado (lockForUpdate), numa
     * transação curta que termina antes de falar com o Stripe. Nunca lança por causa do Stripe:
     * regista um aviso e agenda o CancelStripeSubscriptionJob (depois do commit) com o mesmo retrato.
     */
    public function cancel(User $user): void
    {
        [$subscriptionIds, $sessionId] = DB::transaction(function () use ($user) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $subscriptionIds = Subscription::where('user_id', $locked->id)
                ->whereNotIn('stripe_status', self::ENDED_STATUSES)
                ->pluck('stripe_id')
                ->all();

            return [$subscriptionIds, $locked->stripe_checkout_session_id];
        });

        if ($subscriptionIds === [] && $sessionId === null) {
            return;
        }

        try {
            $this->cancelOrFail($user->id, $subscriptionIds, $sessionId);
        } catch (ApiErrorException $e) {
            Log::warning('Falha ao cancelar a subscrição Stripe; job de recurso agendado.', [
                'user_id' => $user->id,
                'exception' => $e::class,
                'stripe_request_id' => $e->getRequestId(),
            ]);

            // Closure sem return: o PendingDispatch tem de ser destruído (e despachado) dentro do
            // rescue, senão com a fila sync o job falhado lançava fora dele.
            rescue(function () use ($user, $subscriptionIds, $sessionId) {
                CancelStripeSubscriptionJob::dispatch($user->id, $subscriptionIds, $sessionId)->afterCommit();
            }, report: true);
        }
    }

    /**
     * Cancela exatamente o retrato recebido (usado pelo cancel() e pelo job, para a fila repetir).
     * Sessão primeiro (ainda pode ser paga e criar uma subscrição nova), depois cada subscrição;
     * os passos são independentes e a primeira falha só é relançada no fim. Idempotente:
     * subscrições já terminadas localmente não voltam ao Stripe e a sessão só é esquecida se
     * continuar a ser a guardada (nunca apaga uma sessão nova, ex. depois de um desbloqueio).
     *
     * @param  array<int, string>  $subscriptionStripeIds
     *
     * @throws ApiErrorException A primeira falha do Stripe.
     */
    public function cancelOrFail(string $userId, array $subscriptionStripeIds, ?string $checkoutSessionId): void
    {
        $firstError = null;

        if ($checkoutSessionId !== null) {
            try {
                $this->stripe->expireCheckout($checkoutSessionId);

                User::whereKey($userId)
                    ->where('stripe_checkout_session_id', $checkoutSessionId)
                    ->update(['stripe_checkout_session_id' => null]);
            } catch (ApiErrorException $e) {
                $firstError ??= $e;
            }
        }

        $subscriptions = Subscription::where('user_id', $userId)
            ->whereIn('stripe_id', $subscriptionStripeIds)
            ->whereNotIn('stripe_status', self::ENDED_STATUSES)
            ->get();

        foreach ($subscriptions as $subscription) {
            try {
                $this->stripe->cancelNow($subscription);
            } catch (ApiErrorException $e) {
                $firstError ??= $e;
            }
        }

        if ($firstError) {
            throw $firstError;
        }
    }

    /**
     * Devolve o URL do Stripe Checkout para a conta pagar, sem nunca abrir duas subscrições:
     * subscrição em curso ou sessão já paga → exceção (409); sessão ainda aberta → o mesmo URL;
     * senão cria uma sessão nova e guarda o id. Na ativação grava também os dados de faturação;
     * na reativação usa os guardados. Só há trial se for ativação e a conta nunca tiver subscrito.
     *
     * Tudo numa transação com o utilizador bloqueado (lockForUpdate): um segundo pedido simultâneo
     * espera pelo primeiro e encontra a sessão aberta. O estado é revisto depois do lock (a conta
     * pode ter sido bloqueada entretanto). Ao reutilizar a sessão, faturação corrigida também é
     * enviada ao cliente Stripe. Se o Stripe falhar, nada fica gravado.
     *
     * @param  array<string, string>  $billing  Dados de faturação validados (vazio na reativação).
     *
     * @throws ActivationLinkNoLongerValidException Se a conta deixou de poder pagar.
     * @throws SubscriptionInProgressException
     * @throws ApiErrorException
     */
    public function startCheckout(User $user, array $billing, string $cancelUrl): string
    {
        return DB::transaction(function () use ($user, $billing, $cancelUrl) {
            $user = User::with('accountStatus')->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $type = $user->activationType();

            if ($type === null) {
                throw new ActivationLinkNoLongerValidException;
            }

            if ($type === ActivationType::Activation) {
                $user->fill($billing);
            }

            if ($user->hasOngoingSubscription()) {
                throw new SubscriptionInProgressException;
            }

            if ($user->stripe_checkout_session_id) {
                $session = $this->stripe->retrieveCheckout($user->stripe_checkout_session_id);

                if ($session->status === 'complete') {
                    throw new SubscriptionInProgressException;
                }

                if ($session->status === 'open') {
                    if ($user->isDirty(['billing_name', 'nif', 'billing_address', 'billing_postal_code', 'billing_city'])) {
                        $this->stripe->syncCustomer($user);
                    }

                    $user->save();

                    return $session->url;
                }
            }

            $withTrial = $type === ActivationType::Activation && $user->subscriptions()->doesntExist();
            $session = $this->stripe->createCheckout($user, $withTrial, $cancelUrl);

            $user->forceFill(['stripe_checkout_session_id' => $session->id])->save();

            return $session->url;
        });
    }
}
