<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Models\User;
use App\Services\Payments\Data\CheckoutSessionData;
use App\Services\Payments\StripeSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
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

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

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
