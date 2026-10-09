<?php

namespace App\Services\Payments;

use App\Enums\ActivationType;
use App\Exceptions\ActivationLinkNoLongerValidException;
use App\Exceptions\SubscriptionInProgressException;
use App\Models\User;
use App\Notifications\ActivationLinkNotification;
use App\Notifications\ReactivationLinkNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Stripe\Exception\ApiErrorException;

/**
 * Regras de domínio da subscrição: links de ativação/reativação e início do pagamento sem
 * duplicados. As chamadas ao Stripe passam pelo StripeSubscriptionService.
 */
class SubscriptionService
{
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
