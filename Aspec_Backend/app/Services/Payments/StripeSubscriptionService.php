<?php

namespace App\Services\Payments;

use App\Models\User;
use App\Services\Payments\Data\CheckoutSessionData;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;

/**
 * Único sítio que fala com o Stripe sobre subscrições e Checkout. As regras de negócio
 * (estados, duplicados, links) ficam no SubscriptionService; nos testes esta classe é mockada.
 */
class StripeSubscriptionService
{
    /**
     * Cria uma sessão do Stripe Checkout em modo subscrição para o preço configurado.
     * O Cashier cria o cliente Stripe se ainda não existir; se já existir, os dados de faturação
     * são sincronizados antes (podem ter sido corrigidos num novo pedido de ativação).
     * O trial usa trial_period_days para os dias contarem a partir do pagamento, não da criação da sessão.
     *
     * @throws ApiErrorException Se o Stripe falhar.
     */
    public function createCheckout(User $user, bool $withTrial, string $cancelUrl): CheckoutSessionData
    {
        $this->syncCustomer($user);

        $checkout = $user->newSubscription('default', config('subscription.price_id'))->checkout(array_filter([
            'success_url' => rtrim(config('app.frontend_url'), '/').'/ativacao/sucesso',
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $user->id,
            'metadata' => ['user_id' => $user->id],
            'locale' => 'pt',
            'payment_method_collection' => 'always',
            'subscription_data' => $withTrial ? ['trial_period_days' => config('subscription.trial_days')] : null,
        ]));

        return CheckoutSessionData::fromStripe($checkout->asStripeCheckoutSession());
    }

    /**
     * Envia ao cliente Stripe o nome e a morada de faturação atuais (se o cliente já existir).
     *
     * @throws ApiErrorException Se o Stripe falhar.
     */
    public function syncCustomer(User $user): void
    {
        if ($user->hasStripeId()) {
            $user->syncStripeCustomerDetails();
        }
    }

    /**
     * Cria uma sessão do portal de faturação do Stripe (só atualizar o cartão) e devolve o URL.
     * O URL é uma credencial de curta duração: não se guarda nem vai para o log.
     * Fora de local/testing exige STRIPE_BILLING_PORTAL_CONFIGURATION (fail closed).
     *
     * @throws RuntimeException Se o portal não estiver configurado fora de local/testing.
     * @throws ApiErrorException Se o Stripe falhar.
     */
    public function billingPortalUrl(User $user, string $returnUrl): string
    {
        $configuration = config('subscription.billing_portal_configuration');

        if (blank($configuration) && ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('STRIPE_BILLING_PORTAL_CONFIGURATION não configurado; portal de faturação recusado.');
        }

        return $user->billingPortalUrl($returnUrl, array_filter([
            'configuration' => $configuration,
            'locale' => 'pt',
        ]));
    }

    /**
     * Vai buscar uma sessão de Checkout guardada. Uma sessão que o Stripe já não conhece
     * conta como expirada (pode criar-se outra).
     *
     * @throws ApiErrorException Se o Stripe falhar por outro motivo.
     */
    public function retrieveCheckout(string $sessionId): CheckoutSessionData
    {
        try {
            return CheckoutSessionData::fromStripe(Cashier::stripe()->checkout->sessions->retrieve($sessionId));
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() !== 'resource_missing') {
                throw $e;
            }

            return new CheckoutSessionData($sessionId, null, 'expired');
        }
    }

    /**
     * Cancela de imediato a subscrição no Stripe e atualiza a linha local.
     * Chama o Stripe diretamente e não o cancelNow() do Cashier, que passa pelo dono da subscrição:
     * para um utilizador anonimizado (soft deleted) o dono é null e o cancelamento falhava.
     * Sem proração: o tempo não usado não fica como crédito no cliente, que entraria na fatura
     * de uma futura reativação. Uma subscrição que o Stripe já não conhece conta como cancelada
     * (só se atualiza a linha local), para repetir a operação sem erro.
     *
     * @throws ApiErrorException Se o Stripe falhar por outro motivo (a linha local não é alterada).
     */
    public function cancelNow(Subscription $subscription): void
    {
        try {
            Cashier::stripe()->subscriptions->cancel($subscription->stripe_id, ['prorate' => false]);
            $subscription->markAsCanceled();
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() !== 'resource_missing') {
                throw $e;
            }

            $subscription->markAsCanceled();
        }
    }

    /**
     * Expira uma sessão de Checkout para já não poder ser paga. O Stripe recusa expirar uma sessão
     * que já não está aberta (paga, expirada ou inexistente): nesse caso não há nada a fazer.
     *
     * @throws ApiErrorException Se o Stripe falhar e a sessão continuar aberta.
     */
    public function expireCheckout(string $sessionId): void
    {
        try {
            Cashier::stripe()->checkout->sessions->expire($sessionId);
        } catch (InvalidRequestException $e) {
            if ($this->retrieveCheckout($sessionId)->status === 'open') {
                throw $e;
            }
        }
    }
}
