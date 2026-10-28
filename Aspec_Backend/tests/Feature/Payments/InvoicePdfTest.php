<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FillsRateLimits;
use Tests\TestCase;

/**
 * GET /api/invoices/{invoice}/pdf: o dono descarrega o PDF da fatura pela API (o dono é verificado
 * em cada download). O PDF é pedido ao driver que emitiu a fatura.
 */
class InvoicePdfTest extends TestCase
{
    use FillsRateLimits;
    use RefreshDatabase;

    private const PDF_BYTES = '%PDF-1.4 fatura demo de teste';

    private const NOT_FOUND = 'Fatura não encontrada.';

    private const PDF_UNAVAILABLE = 'O PDF desta fatura não está disponível.';

    private const STILL_ISSUING = 'A fatura ainda está a ser emitida. Tente novamente mais tarde.';

    private const PROVIDER_FAILED = 'Não foi possível obter o PDF da fatura. Tente novamente dentro de instantes.';

    private const IE_API_KEY = 'secret-key-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-20 10:00');
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function url(string $invoiceId): string
    {
        return "/api/invoices/{$invoiceId}/pdf";
    }

    private function member(): User
    {
        return User::factory()->withBilling()->create();
    }

    /**
     * Fatura demo do membro com o PDF gravado no disco local.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function demoInvoiceWithFile(User $member, string $state = 'issued', array $attributes = []): Invoice
    {
        $invoice = Invoice::factory()->for($member)->demo()->{$state}()->create($attributes);
        Storage::disk('local')->put($invoice->provider_invoice_id, self::PDF_BYTES);

        return $invoice;
    }

    private function useInvoiceExpress(): void
    {
        Sleep::fake();
        config([
            'services.invoiceexpress.account_name' => 'aspec-test',
            'services.invoiceexpress.api_key' => self::IE_API_KEY,
            'services.invoiceexpress.retry_sleep_ms' => 0,
        ]);
    }

    private function assertJsonError(TestResponse $response, int $status, string $message): void
    {
        $response->assertStatus($status)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', $message);
    }

    // --- P1, P2 ---

    #[Test]
    public function owner_downloads_the_demo_pdf(): void
    {
        $member = $this->member();
        $invoice = $this->demoInvoiceWithFile($member);
        Sanctum::actingAs($member);

        $response = $this->get($this->url($invoice->id));

        $response->assertOk();
        $this->assertSame(self::PDF_BYTES, $response->getContent());
    }

    #[Test]
    public function download_has_pdf_attachment_and_no_cache_headers(): void
    {
        $member = $this->member();
        $invoice = $this->demoInvoiceWithFile($member);
        Sanctum::actingAs($member);

        $response = $this->get($this->url($invoice->id))->assertOk();

        $this->assertMatchesRegularExpression('/^DEMO-2026-[A-F0-9]{8}$/', $invoice->number);
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', "attachment; filename=\"fatura-{$invoice->number}.pdf\"");
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    #[Test]
    public function issued_but_not_emailed_invoice_can_be_downloaded(): void
    {
        $member = $this->member();
        $invoice = $this->demoInvoiceWithFile($member, 'issuedNotSent');
        Sanctum::actingAs($member);

        $response = $this->get($this->url($invoice->id));

        $response->assertOk();
        $this->assertSame(self::PDF_BYTES, $response->getContent());
    }

    // --- P3, P4 ---

    #[Test]
    public function other_members_invoice_looks_exactly_like_a_missing_one(): void
    {
        // A resposta igual à de um id inexistente não confirma que a fatura existe.
        $owner = $this->member();
        $invoice = $this->demoInvoiceWithFile($owner);
        Sanctum::actingAs($this->member());

        $missing = $this->getJson($this->url((string) Str::uuid()));
        $other = $this->getJson($this->url($invoice->id));

        $this->assertJsonError($missing, 404, self::NOT_FOUND);
        $this->assertJsonError($other, 404, self::NOT_FOUND);
        $other->assertExactJson($missing->json());
    }

    #[Test]
    public function admin_cannot_download_a_members_invoice(): void
    {
        $invoice = $this->demoInvoiceWithFile($this->member());
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->assertJsonError($this->getJson($this->url($invoice->id)), 404, self::NOT_FOUND);
    }

    #[Test]
    public function non_uuid_id_gets_the_generic_404(): void
    {
        Sanctum::actingAs($this->member());

        $this->assertJsonError($this->getJson($this->url('abc')), 404, 'Recurso não encontrado.');
    }

    // --- P5 ---

    public static function invoicesStillIssuing(): array
    {
        return [
            'pending' => [null],
            'failed' => ['failed'],
        ];
    }

    #[Test]
    #[DataProvider('invoicesStillIssuing')]
    public function invoice_not_issued_yet_gets_409(?string $state): void
    {
        $member = $this->member();
        $factory = Invoice::factory()->for($member);
        $invoice = ($state ? $factory->{$state}() : $factory)->create();
        Sanctum::actingAs($member);

        $this->assertJsonError($this->getJson($this->url($invoice->id)), 409, self::STILL_ISSUING);
    }

    // --- P6, P7 ---

    #[Test]
    public function invoice_from_the_log_driver_has_no_pdf(): void
    {
        $member = $this->member();
        $invoice = Invoice::factory()->for($member)->issued()->create();
        Sanctum::actingAs($member);

        $this->assertJsonError($this->getJson($this->url($invoice->id)), 404, self::PDF_UNAVAILABLE);
    }

    #[Test]
    public function demo_invoice_whose_file_is_missing_has_no_pdf(): void
    {
        $member = $this->member();
        $invoice = Invoice::factory()->for($member)->demo()->issued()->create();
        Sanctum::actingAs($member);

        $this->assertJsonError($this->getJson($this->url($invoice->id)), 404, self::PDF_UNAVAILABLE);
    }

    // --- P8 ---

    #[Test]
    public function invoiceexpress_pdf_is_proxied_to_the_owner(): void
    {
        $this->useInvoiceExpress();
        $member = $this->member();
        $invoice = Invoice::factory()->for($member)->invoiceExpress()->issued()->create(['provider_invoice_id' => '42']);
        Http::fake([
            '*aspec-test.app.invoicexpress.com/api/pdf/42.json*' => Http::response(['output' => ['pdfUrl' => 'https://cdn.invoicexpress.test/42.pdf']], 200),
            'https://cdn.invoicexpress.test/42.pdf' => Http::response(self::PDF_BYTES, 200, ['Content-Type' => 'application/pdf']),
        ]);
        Sanctum::actingAs($member);

        $response = $this->get($this->url($invoice->id));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(self::PDF_BYTES, $response->getContent());
    }

    #[Test]
    public function invoiceexpress_failure_gets_502_and_logs_without_the_api_key(): void
    {
        $this->useInvoiceExpress();
        Log::spy();
        $member = $this->member();
        $invoice = Invoice::factory()->for($member)->invoiceExpress()->issued()->create(['provider_invoice_id' => '42']);
        Http::fake([
            '*aspec-test.app.invoicexpress.com/api/pdf/42.json*' => Http::response(['errors' => ['erro']], 500),
        ]);
        Sanctum::actingAs($member);

        $this->assertJsonError($this->getJson($this->url($invoice->id)), 502, self::PROVIDER_FAILED);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($invoice) {
                $logged = $message.json_encode($context, JSON_UNESCAPED_UNICODE);

                return str_contains($logged, $invoice->id) && ! str_contains($logged, self::IE_API_KEY);
            })
            ->once();
    }

    #[Test]
    public function invoiceexpress_connection_failure_gets_502(): void
    {
        $this->useInvoiceExpress();
        $member = $this->member();
        $invoice = Invoice::factory()->for($member)->invoiceExpress()->issued()->create(['provider_invoice_id' => '42']);
        Http::fake([
            '*' => Http::failedConnection('cURL error 28: timeout for https://aspec-test.app.invoicexpress.com/api/pdf/42.json?api_key='.self::IE_API_KEY),
        ]);
        Sanctum::actingAs($member);

        $response = $this->getJson($this->url($invoice->id));

        $this->assertJsonError($response, 502, self::PROVIDER_FAILED);
        $this->assertStringNotContainsString(self::IE_API_KEY, $response->getContent());
    }

    // --- P9 ---

    public static function otherConfiguredDrivers(): array
    {
        return [
            'log' => ['log', 'aspec-test', self::IE_API_KEY],
            'invoiceexpress sem conta configurada' => ['invoiceexpress', null, null],
        ];
    }

    #[Test]
    #[DataProvider('otherConfiguredDrivers')]
    public function demo_invoice_is_served_by_the_demo_driver_whatever_driver_is_configured(string $driver, ?string $account, ?string $key): void
    {
        config([
            'services.invoicing.driver' => $driver,
            'services.invoiceexpress.account_name' => $account,
            'services.invoiceexpress.api_key' => $key,
        ]);
        $member = $this->member();
        $invoice = $this->demoInvoiceWithFile($member);
        Sanctum::actingAs($member);

        $response = $this->get($this->url($invoice->id));

        $response->assertOk();
        $this->assertSame(self::PDF_BYTES, $response->getContent());
        Http::assertNothingSent();
    }

    // --- P10 ---

    #[Test]
    public function filename_replaces_spaces_and_slashes_from_the_number(): void
    {
        // Os números da InvoiceExpress têm espaço e "/", que partiam o cabeçalho e o nome do ficheiro.
        $member = $this->member();
        $invoice = $this->demoInvoiceWithFile($member, 'issued', [
            'number' => 'FR 2026/12',
            'provider_invoice_id' => "invoices/{$member->id}/FR-2026-12.pdf",
        ]);
        Sanctum::actingAs($member);

        $this->get($this->url($invoice->id))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="fatura-FR-2026-12.pdf"');
    }

    #[Test]
    public function filename_cannot_inject_header_characters(): void
    {
        $member = $this->member();
        $invoice = $this->demoInvoiceWithFile($member, 'issued', [
            'number' => "X\"; filename=evil.exe\r\nY",
            'provider_invoice_id' => "invoices/{$member->id}/X.pdf",
        ]);
        Sanctum::actingAs($member);

        $disposition = (string) $this->get($this->url($invoice->id))->assertOk()->headers->get('Content-Disposition');

        $this->assertMatchesRegularExpression('/^attachment; filename="fatura-[A-Za-z0-9-]+\.pdf"$/', $disposition);
    }

    // --- P11 ---

    #[Test]
    public function download_over_the_minute_limit_gets_429_only_for_that_member(): void
    {
        $member = $this->member();
        $invoice = $this->demoInvoiceWithFile($member);
        $other = $this->member();
        $otherInvoice = $this->demoInvoiceWithFile($other);
        Sanctum::actingAs($member);
        $this->fillRateLimit('invoice-download', 'minute:'.$member->id, 119);

        $this->get($this->url($invoice->id))->assertOk();
        $this->getJson($this->url($invoice->id))->assertTooManyRequests();

        Sanctum::actingAs($other);
        $this->get($this->url($otherInvoice->id))->assertOk();
    }

    #[Test]
    public function download_limit_does_not_block_the_invoice_list(): void
    {
        $member = $this->member();
        $invoice = $this->demoInvoiceWithFile($member);
        Sanctum::actingAs($member);
        $this->fillRateLimit('invoice-download', 'minute:'.$member->id, 120);

        $this->getJson($this->url($invoice->id))->assertTooManyRequests();
        $this->getJson('/api/invoices')->assertOk();
    }

    // --- P12, P13 ---

    #[Test]
    public function guest_gets_401(): void
    {
        $invoice = $this->demoInvoiceWithFile($this->member());

        $this->assertJsonError($this->getJson($this->url($invoice->id)), 401, 'Não autenticado.');
    }

    public static function accountsWithoutAccess(): array
    {
        return [
            'Approved' => ['approved'],
            'Pending' => ['pending'],
            'Inactive unpaid' => ['inactiveUnpaid'],
            'Inactive cancelled' => ['inactiveCancelled'],
        ];
    }

    #[Test]
    #[DataProvider('accountsWithoutAccess')]
    public function account_that_is_not_active_cannot_download_even_its_own_invoice(string $state): void
    {
        $factory = User::factory()->withBilling();
        $user = match ($state) {
            'inactiveUnpaid' => $factory->inactive(InactiveReason::Unpaid)->create(),
            'inactiveCancelled' => $factory->inactive(InactiveReason::Cancelled)->create(),
            default => $factory->{$state}()->create(),
        };
        $invoice = $this->demoInvoiceWithFile($user);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->url($invoice->id));

        $response->assertForbidden()->assertJsonPath('success', false);
        $this->assertStringNotContainsString(self::PDF_BYTES, $response->getContent());
    }

    #[Test]
    public function post_is_not_allowed(): void
    {
        $member = $this->member();
        $invoice = $this->demoInvoiceWithFile($member);
        Sanctum::actingAs($member);

        $this->assertJsonError($this->postJson($this->url($invoice->id)), 405, 'Método não permitido.');
    }
}
