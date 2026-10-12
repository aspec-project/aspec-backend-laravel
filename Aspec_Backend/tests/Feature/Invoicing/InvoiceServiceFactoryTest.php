<?php

namespace Tests\Feature\Invoicing;

use App\Contracts\InvoiceService;
use App\Services\Invoicing\DemoInvoiceService;
use App\Services\Invoicing\InvoiceExpressInvoiceService;
use App\Services\Invoicing\InvoiceServiceFactory;
use App\Services\Invoicing\LogInvoiceService;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A fábrica cria o driver pelo nome. O PDF de uma fatura é pedido ao driver que a emitiu
 * (invoices.provider), que pode já não ser o configurado agora.
 */
class InvoiceServiceFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function factory(): InvoiceServiceFactory
    {
        return app(InvoiceServiceFactory::class);
    }

    private function configureInvoiceExpress(?string $apiKey = 'secret-key-123'): void
    {
        config([
            'services.invoiceexpress.account_name' => 'aspec-test',
            'services.invoiceexpress.api_key' => $apiKey,
        ]);
    }

    // --- F1 ---

    public static function drivers(): array
    {
        return [
            'log' => ['log', LogInvoiceService::class],
            'demo' => ['demo', DemoInvoiceService::class],
            'invoiceexpress' => ['invoiceexpress', InvoiceExpressInvoiceService::class],
        ];
    }

    #[Test]
    #[DataProvider('drivers')]
    public function make_returns_the_driver_for_the_provider_name(string $provider, string $class): void
    {
        $this->configureInvoiceExpress();

        $this->assertInstanceOf($class, $this->factory()->make($provider));
    }

    #[Test]
    public function make_ignores_the_configured_driver(): void
    {
        config(['services.invoicing.driver' => 'log']);

        $this->assertInstanceOf(DemoInvoiceService::class, $this->factory()->make('demo'));
    }

    // --- F2 ---

    #[Test]
    public function unknown_provider_is_rejected_with_the_current_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver de faturação desconhecido.');

        $this->factory()->make('foo');
    }

    #[Test]
    public function invoiceexpress_without_api_key_is_rejected_with_the_current_message(): void
    {
        $this->configureInvoiceExpress(apiKey: null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Faltam o nome da conta ou a chave da API da InvoiceExpress.');

        $this->factory()->make('invoiceexpress');
    }

    // --- F3 ---

    #[Test]
    #[DataProvider('drivers')]
    public function contract_follows_the_configured_driver(string $provider, string $class): void
    {
        $this->configureInvoiceExpress();
        config(['services.invoicing.driver' => $provider]);

        $this->assertInstanceOf($class, app(InvoiceService::class));
    }

    #[Test]
    public function contract_with_an_unknown_configured_driver_is_still_rejected(): void
    {
        config(['services.invoicing.driver' => 'foo']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver de faturação desconhecido.');

        app(InvoiceService::class);
    }
}
