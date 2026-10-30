<?php

namespace Tests\Feature\Invoicing;

use App\Contracts\InvoiceService;
use App\Exceptions\InvoiceIssuingFailed;
use App\Services\Invoicing\Data\InvoiceTax;
use App\Services\Invoicing\DemoInvoiceService;
use App\Services\Invoicing\InvoiceExpressInvoiceService;
use App\Services\Invoicing\InvoiceServiceFactory;
use App\Services\Invoicing\LogInvoiceService;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsInvoiceData;
use Tests\TestCase;

/**
 * A fábrica cria o driver pelo nome. O PDF de uma fatura é pedido ao driver que a emitiu
 * (invoices.provider), que pode já não ser o configurado agora.
 */
class InvoiceServiceFactoryTest extends TestCase
{
    use BuildsInvoiceData;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function factory(): InvoiceServiceFactory
    {
        return app(InvoiceServiceFactory::class);
    }

    private function configureInvoiceExpress(?string $apiKey = 'secret-key-123', ?string $exemptionCode = 'M07'): void
    {
        config([
            'services.invoiceexpress.account_name' => 'aspec-test',
            'services.invoiceexpress.api_key' => $apiKey,
            'services.invoicing.vat_rate' => 0,
            'services.invoicing.vat_exemption_code' => $exemptionCode,
            'services.invoicing.vat_exemption_reason' => 'Isento de IVA — motivo de teste',
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

    // --- Isenção de IVA sem código ---

    public static function missingExemptionCodes(): array
    {
        return [
            'vazio' => [''],
            'null' => [null],
        ];
    }

    #[Test]
    #[DataProvider('missingExemptionCodes')]
    public function invoiceexpress_exempt_without_exemption_code_is_still_created(?string $code): void
    {
        // A recusa é só ao emitir: o PDF de faturas já emitidas tem de continuar a descarregar-se.
        $this->configureInvoiceExpress(exemptionCode: $code);

        $this->assertInstanceOf(InvoiceExpressInvoiceService::class, $this->factory()->make('invoiceexpress'));
    }

    #[Test]
    #[DataProvider('missingExemptionCodes')]
    public function invoiceexpress_exempt_without_exemption_code_refuses_to_issue_without_requests(?string $code): void
    {
        // Um documento fiscal nunca sai com um código de isenção adivinhado.
        $this->configureInvoiceExpress(exemptionCode: $code);
        Http::fake();

        try {
            $this->factory()->make('invoiceexpress')->issue($this->customer(), $this->invoice(amount: 3000, tax: InvoiceTax::fromConfig()));
            $this->fail('Era esperada uma InvoiceIssuingFailed.');
        } catch (InvoiceIssuingFailed $e) {
            $this->assertSame('tax', $e->step);
            $this->assertSame('Falta o código do motivo de isenção de IVA (INVOICE_VAT_EXEMPTION_CODE).', $e->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
            $this->assertStringNotContainsString(self::ACCOUNT_NAME, $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function invoiceexpress_with_vat_needs_no_exemption_code(): void
    {
        $this->configureInvoiceExpress(exemptionCode: '');
        config(['services.invoicing.vat_rate' => 23]);

        $this->assertInstanceOf(InvoiceExpressInvoiceService::class, $this->factory()->make('invoiceexpress'));
    }

    public static function driversWithoutFiscalDocuments(): array
    {
        return [
            'log' => ['log', LogInvoiceService::class],
            'demo' => ['demo', DemoInvoiceService::class],
        ];
    }

    #[Test]
    #[DataProvider('driversWithoutFiscalDocuments')]
    public function demo_and_log_work_exempt_without_exemption_code(string $provider, string $class): void
    {
        $this->configureInvoiceExpress(exemptionCode: '');

        $this->assertInstanceOf($class, $this->factory()->make($provider));
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
