<?php

namespace Tests\Feature\Payments;

use App\Services\Payments\StripeCustomerService;
use PHPUnit\Framework\Attributes\Test;
use Stripe\ApiRequestor;
use Stripe\Exception\InvalidRequestException;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * O SDK do Stripe usa cURL próprio (o Http::fake() não o apanha), por isso troca-se o
 * cliente HTTP do SDK por um falso: o pedido real é montado e interpretado pelo SDK, sem rede.
 */
class StripeCustomerServiceTest extends TestCase
{
    private object $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new class implements ClientInterface
        {
            public array $requests = [];

            public int $status = 200;

            public array $body = ['id' => 'cus_x', 'object' => 'customer', 'deleted' => true];

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->requests[] = ['method' => $method, 'url' => $absUrl];

                return [json_encode($this->body), $this->status, []];
            }
        };

        ApiRequestor::setHttpClient($this->http);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function respondWithError(int $status, string $code): void
    {
        $this->http->status = $status;
        $this->http->body = ['error' => [
            'type' => 'invalid_request_error',
            'code' => $code,
            'message' => 'Erro de teste',
        ]];
    }

    #[Test]
    public function deletes_the_customer_with_a_single_delete_request(): void
    {
        app(StripeCustomerService::class)->delete('cus_x');

        $this->assertCount(1, $this->http->requests);
        $this->assertSame('delete', $this->http->requests[0]['method']);
        $this->assertStringEndsWith('/v1/customers/cus_x', $this->http->requests[0]['url']);
    }

    #[Test]
    public function customer_already_missing_counts_as_success(): void
    {
        $this->respondWithError(404, 'resource_missing');

        app(StripeCustomerService::class)->delete('cus_x');

        $this->assertCount(1, $this->http->requests);
    }

    #[Test]
    public function other_invalid_request_errors_are_rethrown(): void
    {
        $this->respondWithError(400, 'parameter_invalid');

        try {
            app(StripeCustomerService::class)->delete('cus_x');
            $this->fail('Um erro do Stripe que não seja resource_missing tem de propagar.');
        } catch (InvalidRequestException $e) {
            $this->assertSame('parameter_invalid', $e->getStripeCode());
        }
    }

    #[Test]
    public function requests_never_target_the_real_stripe_api(): void
    {
        app(StripeCustomerService::class)->delete('cus_x');

        $this->assertStringNotContainsString('stripe.com', $this->http->requests[0]['url']);
    }
}
