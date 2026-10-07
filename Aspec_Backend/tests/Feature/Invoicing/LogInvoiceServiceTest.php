<?php

namespace Tests\Feature\Invoicing;

use App\Services\Invoicing\Data\IssuedInvoice;
use App\Services\Invoicing\LogInvoiceService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsInvoiceData;
use Tests\TestCase;

/**
 * O driver log é o entregável da Fase 1 enquanto não há conta InvoiceExpress:
 * regista a emissão sem dados pessoais e devolve um número fictício estável.
 */
class LogInvoiceServiceTest extends TestCase
{
    use BuildsInvoiceData;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.invoiceexpress.retry_sleep_ms' => 0]);
        Log::spy();
    }

    private function service(): LogInvoiceService
    {
        return app(LogInvoiceService::class);
    }

    #[Test]
    public function logs_the_invoice_with_its_stripe_id_and_amount(): void
    {
        $this->service()->issue($this->customer(), $this->invoice());

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => ($context['stripe_invoice_id'] ?? null) === 'in_test_123'
                && ($context['amount'] ?? null) === 6000)
            ->once();
    }

    #[Test]
    public function log_context_has_no_personal_data(): void
    {
        $this->service()->issue($this->customer(), $this->invoice());

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context = []) {
                $logged = $message.json_encode($context, JSON_UNESCAPED_UNICODE);

                foreach ([self::NIF, self::CUSTOMER_NAME, self::CUSTOMER_EMAIL, self::CUSTOMER_ADDRESS, self::CUSTOMER_CODE] as $personal) {
                    if (str_contains($logged, $personal)) {
                        return false;
                    }
                }

                return true;
            })
            ->once();
    }

    #[Test]
    public function returns_a_log_number_for_the_payment_year(): void
    {
        $issued = $this->service()->issue($this->customer(), $this->invoice());

        $this->assertMatchesRegularExpression('/^LOG-2026-[A-F0-9]{8}$/', $issued->number);
    }

    #[Test]
    public function number_is_derived_from_the_stripe_invoice_id(): void
    {
        $issued = $this->service()->issue($this->customer(), $this->invoice('in_test_123'));

        $this->assertSame('LOG-2026-'.strtoupper(substr(sha1('in_test_123'), 0, 8)), $issued->number);
    }

    #[Test]
    public function same_stripe_invoice_gets_the_same_number(): void
    {
        $first = $this->service()->issue($this->customer(), $this->invoice('in_test_123'));
        $second = $this->service()->issue($this->customer(), $this->invoice('in_test_123'));

        $this->assertSame($first->number, $second->number);
    }

    #[Test]
    public function different_stripe_invoices_get_different_numbers(): void
    {
        $first = $this->service()->issue($this->customer(), $this->invoice('in_test_123'));
        $second = $this->service()->issue($this->customer(), $this->invoice('in_test_456'));

        $this->assertNotSame($first->number, $second->number);
    }

    #[Test]
    public function returns_an_issued_invoice_from_the_log_provider(): void
    {
        $issued = $this->service()->issue($this->customer(), $this->invoice());

        $this->assertSame('log', $issued->provider);
        $this->assertSame(IssuedInvoice::ISSUED, $issued->status);
        $this->assertSame('issued', $issued->status);
        $this->assertNull($issued->providerInvoiceId);
        $this->assertNull($issued->pdfUrl);
    }

    #[Test]
    public function accepts_any_currency(): void
    {
        $issued = $this->service()->issue($this->customer(), $this->invoice(currency: 'usd'));

        $this->assertSame(IssuedInvoice::ISSUED, $issued->status);
    }

    #[Test]
    public function makes_no_http_requests(): void
    {
        Http::fake();

        $this->service()->issue($this->customer(), $this->invoice());

        Http::assertNothingSent();
    }
}
