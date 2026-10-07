<?php

namespace Tests\Feature\Invoicing;

use App\Contracts\InvoiceService;
use App\Services\Invoicing\InvoiceExpressInvoiceService;
use App\Services\Invoicing\LogInvoiceService;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O driver de faturação é escolhido pela config ao resolver o contrato,
 * para trocar a InvoiceExpress pelo driver log (ou vice-versa) sem mudar código.
 */
class InvoiceServiceBindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.invoiceexpress.retry_sleep_ms' => 0]);
    }

    private function useInvoiceExpress(?string $accountName = 'aspec-test', ?string $apiKey = 'secret-key-123'): void
    {
        config([
            'services.invoicing.driver' => 'invoiceexpress',
            'services.invoiceexpress.account_name' => $accountName,
            'services.invoiceexpress.api_key' => $apiKey,
        ]);
    }

    #[Test]
    public function log_driver_resolves_the_log_service(): void
    {
        config(['services.invoicing.driver' => 'log']);

        $this->assertInstanceOf(LogInvoiceService::class, app(InvoiceService::class));
    }

    #[Test]
    public function log_is_the_default_driver_in_tests(): void
    {
        $this->assertSame('log', config('services.invoicing.driver'));
        $this->assertInstanceOf(LogInvoiceService::class, app(InvoiceService::class));
    }

    #[Test]
    public function invoiceexpress_driver_with_credentials_resolves_the_invoiceexpress_service(): void
    {
        $this->useInvoiceExpress();

        $this->assertInstanceOf(InvoiceExpressInvoiceService::class, app(InvoiceService::class));
    }

    #[Test]
    public function changing_the_config_changes_the_resolved_driver(): void
    {
        // Prova que o binding não é singleton: a config é lida de cada vez que o contrato é resolvido.
        config(['services.invoicing.driver' => 'log']);
        $this->assertInstanceOf(LogInvoiceService::class, app(InvoiceService::class));

        $this->useInvoiceExpress();
        $this->assertInstanceOf(InvoiceExpressInvoiceService::class, app(InvoiceService::class));
    }

    #[Test]
    public function unknown_driver_is_rejected(): void
    {
        config(['services.invoicing.driver' => 'foo']);

        $this->expectException(InvalidArgumentException::class);

        app(InvoiceService::class);
    }

    #[Test]
    public function invoiceexpress_without_api_key_fails_without_revealing_the_account(): void
    {
        $this->useInvoiceExpress(apiKey: null);

        try {
            app(InvoiceService::class);
            $this->fail('Era esperada uma InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('aspec-test', $e->getMessage());
        }
    }

    #[Test]
    public function invoiceexpress_without_account_name_fails_without_revealing_the_key(): void
    {
        $this->useInvoiceExpress(accountName: null);

        try {
            app(InvoiceService::class);
            $this->fail('Era esperada uma InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('secret-key-123', $e->getMessage());
        }
    }

    public static function invalidAccountNames(): array
    {
        return [
            'domínio e query' => ['evil.example/x?'],
            'com espaço' => ['a b'],
            'com @' => ['user@evil.example'],
            'com #' => ['aspec#frag'],
        ];
    }

    public static function invalidDocumentTypes(): array
    {
        return [
            'caminho' => ['invoice_receipts/../clients'],
            'maiúsculas' => ['Invoice_receipts'],
            'com query' => ['invoices.json?x=1'],
            'vazio' => [''],
        ];
    }

    #[Test]
    #[DataProvider('invalidAccountNames')]
    public function account_name_that_could_change_the_host_is_rejected(string $accountName): void
    {
        // O nome da conta entra no host do URL: um valor como "evil.example/x?" mandaria a api_key para outro servidor.
        $this->useInvoiceExpress(accountName: $accountName);

        try {
            app(InvoiceService::class);
            $this->fail('Era esperada uma InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Nome da conta InvoiceExpress inválido.', $e->getMessage());
            $this->assertStringNotContainsString($accountName, $e->getMessage());
        }
    }

    #[Test]
    #[DataProvider('invalidDocumentTypes')]
    public function document_type_outside_lowercase_and_underscores_is_rejected(string $documentType): void
    {
        $this->useInvoiceExpress();
        config(['services.invoiceexpress.document_type' => $documentType]);

        try {
            app(InvoiceService::class);
            $this->fail('Era esperada uma InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Tipo de documento InvoiceExpress inválido.', $e->getMessage());

            if ($documentType !== '') {
                $this->assertStringNotContainsString($documentType, $e->getMessage());
            }
        }
    }
}
