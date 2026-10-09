<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Models\User;
use App\Services\Payments\Data\CheckoutSessionData;
use App\Services\Payments\StripeSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * O SDK do Stripe usa cURL próprio (o Http::fake() não o apanha), por isso troca-se o cliente
 * HTTP do SDK por um falso: os pedidos são montados pelo SDK/Cashier e inspecionados aqui, sem rede.
 */
class StripeSubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private const CANCEL_URL = 'http://localhost:5173/ativacao/x?expires=1&signature=abc';

    private object $http;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => 'http://localhost:5173',
            'subscription.price_id' => 'price_test',
            'subscription.trial_days' => 30,
        ]);

        $this->http = new class implements ClientInterface
        {
            public array $requests = [];

            public ?array $sessionError = null;

            public array $session = [
                'id' => 'cs_test_1',
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
                'status' => 'open',
            ];

            /** Resposta própria para um pedido: devolve [corpo, código] ou null para seguir o normal. */
            public ?\Closure $respond = null;

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

                if ($this->respond && ($response = ($this->respond)($method, $absUrl))) {
                    return [json_encode($response[0]), $response[1], []];
                }

                if (str_contains($absUrl, '/v1/customers')) {
                    return [json_encode(['id' => 'cus_test_1', 'object' => 'customer']), 200, []];
                }

                if ($this->sessionError) {
                    return [json_encode(['error' => $this->sessionError]), 404, []];
                }

                return [json_encode($this->session), 200, []];
            }

            public function checkoutCreation(): ?array
            {
                foreach ($this->requests as $request) {
                    if ($request['method'] === 'post' && str_ends_with($request['url'], '/v1/checkout/sessions')) {
                        return $request['params'];
                    }
                }

                return null;
            }
        };

        ApiRequestor::setHttpClient($this->http);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function service(): StripeSubscriptionService
    {
        return app(StripeSubscriptionService::class);
    }

    #[Test]
    public function checkout_with_trial_sends_trial_period_days_and_returns_the_session(): void
    {
        $user = User::factory()->approved()->withBilling()->create();

        $session = $this->service()->createCheckout($user, true, self::CANCEL_URL);

        $this->assertInstanceOf(CheckoutSessionData::class, $session);
        $this->assertSame('cs_test_1', $session->id);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $session->url);
        $this->assertSame('open', $session->status);

        $params = $this->http->checkoutCreation();
        $this->assertNotNull($params);
        $this->assertSame('subscription', $params['mode']);
        $this->assertSame(30, (int) $params['subscription_data']['trial_period_days']);
        $this->assertArrayNotHasKey('trial_end', $params['subscription_data']);
        $this->assertSame('pt', $params['locale']);
        $this->assertSame('always', $params['payment_method_collection']);
        $this->assertSame($user->id, $params['client_reference_id']);
        $this->assertSame($user->id, $params['metadata']['user_id']);
        $this->assertSame('http://localhost:5173/ativacao/sucesso', $params['success_url']);
        $this->assertSame(self::CANCEL_URL, $params['cancel_url']);
        $this->assertSame('price_test', $params['line_items'][0]['price']);
    }

    #[Test]
    public function checkout_without_trial_sends_no_trial(): void
    {
        $user = User::factory()->inactive(InactiveReason::Unpaid)->withBilling()->create();

        $this->service()->createCheckout($user, false, self::CANCEL_URL);

        $params = $this->http->checkoutCreation();
        $this->assertArrayNotHasKey('trial_period_days', $params['subscription_data'] ?? []);
        $this->assertArrayNotHasKey('trial_end', $params['subscription_data'] ?? []);
    }

    #[Test]
    public function checkout_creates_the_stripe_customer_and_stores_its_id(): void
    {
        $user = User::factory()->approved()->withBilling()->create();

        $this->service()->createCheckout($user, true, self::CANCEL_URL);

        $this->assertSame('cus_test_1', $user->fresh()->stripe_id);
        $this->assertSame('cus_test_1', $this->http->checkoutCreation()['customer']);
    }

    #[Test]
    public function retrieve_checkout_maps_the_session(): void
    {
        $this->http->session['status'] = 'complete';
        $this->http->session['url'] = null;

        $session = $this->service()->retrieveCheckout('cs_test_1');

        $this->assertSame('cs_test_1', $session->id);
        $this->assertNull($session->url);
        $this->assertSame('complete', $session->status);
    }

    #[Test]
    public function missing_checkout_session_counts_as_expired(): void
    {
        $this->http->sessionError = [
            'type' => 'invalid_request_error',
            'code' => 'resource_missing',
            'message' => 'No such checkout.session',
        ];

        $session = $this->service()->retrieveCheckout('cs_test_gone');

        $this->assertSame('expired', $session->status);
    }

    private function subscriptionFor(User $user, string $status = 'active'): Subscription
    {
        return $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_1',
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);
    }

    private function stripeError(string $code, string $message): array
    {
        return [['error' => ['type' => 'invalid_request_error', 'code' => $code, 'message' => $message]], 400];
    }

    #[Test]
    public function cancel_now_cancels_in_stripe_and_marks_the_local_row(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscriptionFor($user);
        $this->http->respond = fn ($method, $url) => $method === 'delete'
            ? [['id' => 'sub_test_1', 'object' => 'subscription', 'status' => 'canceled'], 200]
            : null;

        $this->service()->cancelNow($subscription);

        $this->assertSame('delete', $this->http->requests[0]['method']);
        $this->assertStringEndsWith('/v1/subscriptions/sub_test_1', $this->http->requests[0]['url']);
        $this->assertSame('canceled', $subscription->fresh()->stripe_status);
        $this->assertNotNull($subscription->fresh()->ends_at);
    }

    /**
     * Com proração o Stripe dava crédito do tempo não usado, que entrava na 1.ª fatura depois de
     * um desbloqueio e deixava a fatura fiscal diferente do valor pago.
     */
    #[Test]
    public function cancel_now_does_not_prorate_the_unused_time(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscriptionFor($user);
        $this->http->respond = fn ($method, $url) => $method === 'delete'
            ? [['id' => 'sub_test_1', 'object' => 'subscription', 'status' => 'canceled'], 200]
            : null;

        $this->service()->cancelNow($subscription);

        $request = $this->http->requests[0];
        $this->assertSame('delete', $request['method']);
        $this->assertSame('false', $request['params']['prorate'] ?? null);
        $this->assertStringNotContainsString('prorate=true', $request['url']);
    }

    /**
     * Um utilizador anonimizado está soft deleted, por isso a relação owner do Cashier é null:
     * o cancelamento não pode depender dela (ex.: Checkout pago depois de a conta ser apagada).
     */
    #[Test]
    public function cancel_now_works_when_the_owner_is_soft_deleted(): void
    {
        $user = User::factory()->withBilling()->create();
        $this->subscriptionFor($user);
        $user->delete();
        $subscription = Subscription::where('stripe_id', 'sub_test_1')->firstOrFail();
        $this->http->respond = fn ($method, $url) => $method === 'delete'
            ? [['id' => 'sub_test_1', 'object' => 'subscription', 'status' => 'canceled'], 200]
            : null;

        $this->service()->cancelNow($subscription);

        $this->assertCount(1, $this->http->requests);
        $this->assertSame('delete', $this->http->requests[0]['method']);
        $this->assertStringEndsWith('/v1/subscriptions/sub_test_1', $this->http->requests[0]['url']);
        $this->assertSame('canceled', $subscription->fresh()->stripe_status);
        $this->assertNotNull($subscription->fresh()->ends_at);
    }

    #[Test]
    public function cancel_now_of_a_subscription_stripe_no_longer_knows_only_marks_the_local_row(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscriptionFor($user);
        $this->http->respond = fn () => $this->stripeError('resource_missing', 'No such subscription');

        $this->service()->cancelNow($subscription);

        $this->assertSame('canceled', $subscription->fresh()->stripe_status);
    }

    #[Test]
    public function cancel_now_rethrows_other_stripe_errors_and_keeps_the_local_row(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscriptionFor($user);
        $this->http->respond = fn () => $this->stripeError('parameter_invalid', 'Erro');

        try {
            $this->service()->cancelNow($subscription);
            $this->fail('Devia ter propagado o erro do Stripe.');
        } catch (InvalidRequestException) {
        }

        $this->assertSame('active', $subscription->fresh()->stripe_status);
    }

    #[Test]
    public function expire_checkout_expires_the_session_in_stripe(): void
    {
        $this->http->session['status'] = 'expired';

        $this->service()->expireCheckout('cs_test_1');

        $this->assertSame('post', $this->http->requests[0]['method']);
        $this->assertStringEndsWith('/v1/checkout/sessions/cs_test_1/expire', $this->http->requests[0]['url']);
    }

    #[Test]
    public function expire_checkout_of_a_session_that_is_no_longer_open_does_nothing(): void
    {
        $this->http->respond = fn ($method, $url) => str_ends_with($url, '/expire')
            ? $this->stripeError('checkout_session_not_open', 'Only open sessions can be expired.')
            : null;
        $this->http->session['status'] = 'complete';

        $this->service()->expireCheckout('cs_test_1');

        $this->assertCount(2, $this->http->requests);
    }

    #[Test]
    public function expire_checkout_rethrows_when_the_session_is_still_open(): void
    {
        $this->http->respond = fn ($method, $url) => str_ends_with($url, '/expire')
            ? $this->stripeError('parameter_invalid', 'Erro')
            : null;

        $this->expectException(InvalidRequestException::class);

        $this->service()->expireCheckout('cs_test_1');
    }

    private function respondToBillingPortal(): void
    {
        $this->http->respond = fn ($method, $url) => str_ends_with($url, '/v1/billing_portal/sessions')
            ? [['id' => 'bps_test_1', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/test_1'], 200]
            : null;
    }

    private function billingPortalCreation(): ?array
    {
        foreach ($this->http->requests as $request) {
            if ($request['method'] === 'post' && str_ends_with($request['url'], '/v1/billing_portal/sessions')) {
                return $request['params'];
            }
        }

        return null;
    }

    #[Test]
    public function billing_portal_url_creates_a_portal_session_for_the_customer(): void
    {
        config(['subscription.billing_portal_configuration' => null]);
        $user = User::factory()->withBilling()->create(['stripe_id' => 'cus_test_1']);
        $this->respondToBillingPortal();

        $url = $this->service()->billingPortalUrl($user, 'http://localhost:5173/conta/subscricao');

        $this->assertSame('https://billing.stripe.com/p/session/test_1', $url);
        $params = $this->billingPortalCreation();
        $this->assertNotNull($params);
        $this->assertSame('cus_test_1', $params['customer']);
        $this->assertSame('http://localhost:5173/conta/subscricao', $params['return_url']);
        $this->assertSame('pt', $params['locale']);
    }

    /**
     * Com o id da configuração no .env, as opções do portal (só atualizar o cartão) ficam ligadas
     * a cada ambiente e não dependem da configuração por omissão do dashboard.
     */
    #[Test]
    public function billing_portal_url_sends_the_configured_portal_configuration(): void
    {
        config(['subscription.billing_portal_configuration' => 'bpc_test_1']);
        $user = User::factory()->withBilling()->create(['stripe_id' => 'cus_test_1']);
        $this->respondToBillingPortal();

        $this->service()->billingPortalUrl($user, 'http://localhost:5173/conta/subscricao');

        $this->assertSame('bpc_test_1', $this->billingPortalCreation()['configuration'] ?? null);
    }

    public static function developmentEnvironments(): array
    {
        return [
            'local' => ['local'],
            'testing' => ['testing'],
        ];
    }

    /**
     * Em desenvolvimento a configuração é opcional para cada colega não ter de criar uma no
     * Stripe: o portal usa a configuração por omissão do dashboard.
     */
    #[Test]
    #[DataProvider('developmentEnvironments')]
    public function billing_portal_url_without_configuration_uses_the_dashboard_default_in_development(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        config(['subscription.billing_portal_configuration' => null]);
        $user = User::factory()->withBilling()->create(['stripe_id' => 'cus_test_1']);
        $this->respondToBillingPortal();

        $this->service()->billingPortalUrl($user, 'http://localhost:5173/conta/subscricao');

        $params = $this->billingPortalCreation();
        $this->assertNotNull($params);
        $this->assertArrayNotHasKey('configuration', $params);
    }

    public static function deployedEnvironments(): array
    {
        return [
            'production' => ['production'],
            'staging' => ['staging'],
        ];
    }

    /**
     * Sem configuração própria, as opções do portal vinham do dashboard: se alguém lá ligasse
     * "cancelar" ou "editar dados do cliente", o membro contornava as nossas regras. Fora de
     * desenvolvimento o portal é recusado antes de qualquer pedido ao Stripe (fail closed).
     */
    #[Test]
    #[DataProvider('deployedEnvironments')]
    public function billing_portal_url_without_configuration_is_refused_outside_development(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        config(['subscription.billing_portal_configuration' => null]);
        $user = User::factory()->withBilling()->create(['stripe_id' => 'cus_test_1']);
        $this->respondToBillingPortal();

        try {
            $this->service()->billingPortalUrl($user, 'http://localhost:5173/conta/subscricao');
            $this->fail('Devia ter recusado o portal sem configuração.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('STRIPE_BILLING_PORTAL_CONFIGURATION', $e->getMessage());
        }

        $this->assertSame([], $this->http->requests);
    }

    #[Test]
    public function billing_portal_url_with_configuration_works_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['subscription.billing_portal_configuration' => 'bpc_test_1']);
        $user = User::factory()->withBilling()->create(['stripe_id' => 'cus_test_1']);
        $this->respondToBillingPortal();

        $url = $this->service()->billingPortalUrl($user, 'http://localhost:5173/conta/subscricao');

        $this->assertSame('https://billing.stripe.com/p/session/test_1', $url);
        $params = $this->billingPortalCreation();
        $this->assertNotNull($params);
        $this->assertSame('bpc_test_1', $params['configuration'] ?? null);
    }

    #[Test]
    public function billing_portal_url_rethrows_stripe_errors(): void
    {
        $user = User::factory()->withBilling()->create(['stripe_id' => 'cus_test_1']);
        $this->http->respond = fn () => $this->stripeError('resource_missing', 'No such customer');

        $this->expectException(ApiErrorException::class);

        $this->service()->billingPortalUrl($user, 'http://localhost:5173/conta/subscricao');
    }

    #[Test]
    public function other_stripe_errors_are_rethrown(): void
    {
        ApiRequestor::setHttpClient(new class implements ClientInterface
        {
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                throw ApiConnectionException::factory('sem rede');
            }
        });

        $this->expectException(ApiConnectionException::class);

        $this->service()->retrieveCheckout('cs_test_1');
    }
}
