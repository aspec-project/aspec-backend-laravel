<?php

namespace Tests\Feature\Payments;

use App\Contracts\InvoiceService;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceIssuingFailed;
use App\Jobs\IssueInvoiceJob;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoicing\Data\InvoiceCustomerData;
use App\Services\Invoicing\Data\InvoiceData;
use App\Services\Invoicing\Data\IssuedInvoice;
use App\Services\Payments\StripeCustomerService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Emissão em fila da fatura de um pagamento: estados da linha `invoices`, retoma a partir do
 * rascunho e nenhum dado pessoal no payload do job nem nos logs.
 */
class IssueInvoiceJobTest extends TestCase
{
    use RefreshDatabase;

    private const NIF = '123456789';

    private const REASON = 'Isento de IVA — motivo de teste';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-20 10:00');
        config(['subscription.invoice_description' => 'Quota ASPEC']);
    }

    private function runJob(string $invoiceId): void
    {
        app()->call([new IssueInvoiceJob($invoiceId), 'handle']);
    }

    private function pendingInvoice(array $attributes = []): Invoice
    {
        return Invoice::factory()->create($attributes);
    }

    private function expectNoIssuing(): void
    {
        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('issue');
        });
    }

    private function useTax(int $rate, ?string $code, ?string $reason): void
    {
        config([
            'services.invoicing.vat_rate' => $rate,
            'services.invoicing.vat_exemption_code' => $code,
            'services.invoicing.vat_exemption_reason' => $reason,
        ]);
    }

    private function assertNoTaxStored(Invoice $invoice): void
    {
        $fresh = $invoice->fresh();
        $this->assertNull($fresh->vat_rate);
        $this->assertNull($fresh->vat_exemption_code);
        $this->assertNull($fresh->vat_exemption_reason);
    }

    private function hasNoPersonalData(string $message, array $context, User $user): bool
    {
        $logged = $message.json_encode($context, JSON_UNESCAPED_UNICODE);

        return ! str_contains($logged, self::NIF) && ! str_contains($logged, $user->email);
    }

    #[Test]
    public function log_driver_issues_the_pending_invoice(): void
    {
        $invoice = $this->pendingInvoice();

        $this->runJob($invoice->id);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Issued, $fresh->status);
        $this->assertSame('log', $fresh->provider);
        $this->assertMatchesRegularExpression('/^LOG-2026-[A-F0-9]{8}$/', $fresh->number);
        $this->assertNotNull($fresh->issued_at);
        $this->assertNull($fresh->provider_invoice_id);
    }

    #[Test]
    public function issued_but_not_emailed_invoice_is_stored_as_issued_not_sent(): void
    {
        $invoice = $this->pendingInvoice();
        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('issue')->once()->andReturn(new IssuedInvoice(
                provider: 'invoiceexpress',
                providerInvoiceId: 'ie_1',
                number: 'FR ASPEC2026/1',
                pdfUrl: 'https://aspec.app.invoicexpress.com/documents/abc',
                status: IssuedInvoice::ISSUED_NOT_SENT,
            ));
        });

        $this->runJob($invoice->id);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::IssuedNotSent, $fresh->status);
        $this->assertSame('invoiceexpress', $fresh->provider);
        $this->assertSame('ie_1', $fresh->provider_invoice_id);
        $this->assertSame('FR ASPEC2026/1', $fresh->number);
        $this->assertSame('https://aspec.app.invoicexpress.com/documents/abc', $fresh->pdf_url);
        $this->assertNotNull($fresh->issued_at);
    }

    /**
     * Mock do serviço de faturação que só aceita a descrição indicada.
     */
    private function expectDescription(string $description): void
    {
        $this->mock(InvoiceService::class, function (MockInterface $mock) use ($description) {
            $mock->shouldReceive('issue')->once()
                ->withArgs(fn (InvoiceCustomerData $customer, InvoiceData $data) => $data->description === $description)
                ->andReturn(new IssuedInvoice('log', null, 'LOG-2026-ABCDEF12', null, IssuedInvoice::ISSUED));
        });
    }

    #[Test]
    public function invoice_data_uses_the_issue_date_and_the_payment_month_when_there_is_no_period(): void
    {
        // A data é a da emissão (séries fiscais com datas não decrescentes); sem período pago
        // gravado (faturas antigas), a descrição diz o mês do pagamento.
        $invoice = Invoice::factory()->withoutPeriod()->create([
            'stripe_invoice_id' => 'in_test_outubro',
            'amount' => 6000,
            'currency' => 'eur',
        ]);
        $this->travelTo('2026-11-02 09:00');
        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('issue')->once()
                ->withArgs(fn (InvoiceCustomerData $customer, InvoiceData $data) => $data->stripeInvoiceId === 'in_test_outubro'
                    && $data->amount === 6000
                    && $data->currency === 'eur'
                    && $data->date->format('Y-m-d') === '2026-11-02'
                    && $data->description === 'Quota ASPEC — outubro 2026')
                ->andReturn(new IssuedInvoice('log', null, 'LOG-2026-ABCDEF12', null, IssuedInvoice::ISSUED));
        });

        $this->runJob($invoice->id);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    // --- Período pago na descrição ---

    /**
     * O fim do período no Stripe é exclusivo (é o início do seguinte): a fatura mostra o dia antes.
     */
    #[Test]
    public function annual_invoice_describes_the_paid_year_with_an_inclusive_end(): void
    {
        $invoice = $this->pendingInvoice([
            'period_start' => '2026-10-28 10:00:00',
            'period_end' => '2027-10-28 10:00:00',
        ]);
        $this->expectDescription('Quota ASPEC — período de 28/10/2026 a 27/10/2027');

        $this->runJob($invoice->id);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    #[Test]
    public function monthly_invoice_describes_the_paid_month_with_an_inclusive_end(): void
    {
        $invoice = $this->pendingInvoice([
            'period_start' => '2026-10-28 10:00:00',
            'period_end' => '2026-11-28 10:00:00',
        ]);
        $this->expectDescription('Quota ASPEC — período de 28/10/2026 a 27/11/2026');

        $this->runJob($invoice->id);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    /**
     * 23:30 UTC no verão já é o dia seguinte em Lisboa (UTC+1): as datas da fatura são as de Portugal.
     */
    #[Test]
    public function period_dates_are_the_lisbon_dates(): void
    {
        $invoice = $this->pendingInvoice([
            'period_start' => '2026-06-14 23:30:00',
            'period_end' => '2026-07-14 23:30:00',
        ]);
        $this->expectDescription('Quota ASPEC — período de 15/06/2026 a 14/07/2026');

        $this->runJob($invoice->id);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    /**
     * O período vem do Stripe e não da configuração: mudar SUBSCRIPTION_INTERVAL não altera a
     * descrição de uma fatura mensal já paga.
     */
    #[Test]
    public function period_description_does_not_depend_on_the_configured_interval(): void
    {
        config(['subscription.interval' => 'year']);
        $invoice = $this->pendingInvoice([
            'period_start' => '2026-10-28 10:00:00',
            'period_end' => '2026-11-28 10:00:00',
        ]);
        $this->expectDescription('Quota ASPEC — período de 28/10/2026 a 27/11/2026');

        $this->runJob($invoice->id);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    #[Test]
    public function description_starts_with_the_configured_name(): void
    {
        config(['subscription.invoice_description' => 'Quotização ASPEC']);
        $invoice = $this->pendingInvoice([
            'period_start' => '2026-10-28 10:00:00',
            'period_end' => '2027-10-28 10:00:00',
        ]);
        $this->expectDescription('Quotização ASPEC — período de 28/10/2026 a 27/10/2027');

        $this->runJob($invoice->id);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    #[Test]
    public function demo_driver_receives_the_period_description(): void
    {
        config(['services.invoicing.driver' => 'demo']);
        Mail::fake();
        Storage::fake('local');
        $captured = null;
        View::composer('invoices.demo', function ($view) use (&$captured) {
            $captured = $view->getData();
        });
        $invoice = $this->pendingInvoice([
            'period_start' => '2026-10-28 10:00:00',
            'period_end' => '2027-10-28 10:00:00',
        ]);

        $this->runJob($invoice->id);

        $this->assertNotNull($captured, 'A vista invoices.demo não foi renderizada.');
        $this->assertSame('Quota ASPEC — período de 28/10/2026 a 27/10/2027', $captured['description'] ?? null);
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    #[Test]
    public function invoiceexpress_driver_sends_the_period_description_in_the_item(): void
    {
        Http::preventStrayRequests();
        config([
            'services.invoicing.driver' => 'invoiceexpress',
            'services.invoiceexpress.account_name' => 'aspec-test',
            'services.invoiceexpress.api_key' => 'secret-key-123',
            'services.invoiceexpress.document_type' => 'invoice_receipts',
            'services.invoiceexpress.sequence_id' => null,
            'services.invoiceexpress.item_name' => 'Quota ASPEC',
            'services.invoiceexpress.tax_name' => 'IVA0',
            'services.invoicing.vat_rate' => 0,
            'services.invoicing.vat_exemption_code' => 'M07',
            'services.invoicing.vat_exemption_reason' => self::REASON,
            'services.invoiceexpress.retry_times' => 1,
            'services.invoiceexpress.retry_sleep_ms' => 0,
        ]);
        Http::fake([
            '*aspec-test.app.invoicexpress.com/invoice_receipts.json*' => Http::response(['invoice_receipt' => ['id' => 987, 'status' => 'draft', 'total' => 60.0]], 201),
            '*aspec-test.app.invoicexpress.com/invoice_receipts/987/change-state.json*' => Http::response(['invoice_receipt' => [
                'id' => 987,
                'status' => 'settled',
                'sequence_number' => 'FR ASPEC2026/1',
                'permalink' => 'https://aspec-test.app.invoicexpress.com/documents/abc123',
            ]], 200),
            '*aspec-test.app.invoicexpress.com/invoice_receipts/987/email-document.json*' => Http::response([], 200),
        ]);
        $invoice = $this->pendingInvoice([
            'period_start' => '2026-10-28 10:00:00',
            'period_end' => '2027-10-28 10:00:00',
        ]);

        $this->runJob($invoice->id);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/invoice_receipts.json')
            && data_get($request->data(), 'invoice.items.0.name') === 'Quota ASPEC'
            && data_get($request->data(), 'invoice.items.0.description') === 'Quota ASPEC — período de 28/10/2026 a 27/10/2027');
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    // --- Rascunho guardado antes de finalizar e tempo máximo do job ---

    private function useInvoiceExpress(): void
    {
        Http::preventStrayRequests();
        config([
            'services.invoicing.driver' => 'invoiceexpress',
            'services.invoiceexpress.account_name' => 'aspec-test',
            'services.invoiceexpress.api_key' => 'secret-key-123',
            'services.invoiceexpress.document_type' => 'invoice_receipts',
            'services.invoiceexpress.sequence_id' => null,
            'services.invoiceexpress.item_name' => 'Quota ASPEC',
            'services.invoiceexpress.tax_name' => 'IVA0',
            'services.invoicing.vat_rate' => 0,
            'services.invoicing.vat_exemption_code' => 'M07',
            'services.invoicing.vat_exemption_reason' => self::REASON,
            'services.invoiceexpress.retry_times' => 1,
            'services.invoiceexpress.retry_sleep_ms' => 0,
        ]);
    }

    /** Documento finalizado, como a InvoiceExpress o devolve. */
    private function settledDocument(): array
    {
        return ['invoice_receipt' => [
            'id' => 987,
            'status' => 'settled',
            'total' => 60.0,
            'sequence_number' => 'FR ASPEC2026/1',
            'permalink' => 'https://aspec-test.app.invoicexpress.com/documents/abc123',
        ]];
    }

    #[Test]
    public function draft_id_is_stored_before_finalizing(): void
    {
        // Simula o processo a morrer a meio da finalização: a exceção não traz o id do rascunho.
        $this->useInvoiceExpress();
        Http::fake([
            '*aspec-test.app.invoicexpress.com/invoice_receipts.json*' => Http::response(['invoice_receipt' => ['id' => 987, 'status' => 'draft', 'total' => 60.0]], 201),
            '*aspec-test.app.invoicexpress.com/invoice_receipts/987/change-state.json*' => fn () => throw new RuntimeException('Processo terminado.'),
        ]);
        $invoice = $this->pendingInvoice();

        try {
            $this->runJob($invoice->id);
            $this->fail('A exceção devia propagar.');
        } catch (RuntimeException) {
        }

        $fresh = $invoice->fresh();
        $this->assertSame('987', $fresh->provider_invoice_id);
        $this->assertSame(InvoiceStatus::Pending, $fresh->status);
    }

    #[Test]
    public function retry_after_a_stored_draft_resumes_it_instead_of_creating_another(): void
    {
        // A tentativa anterior finalizou o documento mas morreu antes de gravar o resultado.
        $this->useInvoiceExpress();
        Http::fake([
            '*aspec-test.app.invoicexpress.com/invoice_receipts.json*' => Http::response(['invoice_receipt' => ['id' => 555, 'status' => 'draft', 'total' => 60.0]], 201),
            '*aspec-test.app.invoicexpress.com/invoice_receipts/987.json*' => Http::response($this->settledDocument(), 200),
            '*aspec-test.app.invoicexpress.com/invoice_receipts/987/email-document.json*' => Http::response([], 200),
        ]);
        $invoice = $this->pendingInvoice(['provider_invoice_id' => '987']);

        $this->runJob($invoice->id);

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/invoice_receipts.json'));
        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Issued, $fresh->status);
        $this->assertSame('FR ASPEC2026/1', $fresh->number);
        $this->assertSame('987', $fresh->provider_invoice_id);
    }

    #[Test]
    public function job_timeout_covers_the_worst_case_requests_and_stays_below_the_queue_retry_after(): void
    {
        // 4 pedidos (criar ou ler, finalizar, confirmar o estado, email), cada um com as suas tentativas.
        $tries = (int) config('services.invoiceexpress.retry_times');
        $perRequest = $tries * (int) config('services.invoiceexpress.timeout')
            + ($tries - 1) * (int) config('services.invoiceexpress.retry_sleep_ms') / 1000;
        $timeout = (new IssueInvoiceJob('x'))->timeout;

        $this->assertGreaterThan(4 * $perRequest, $timeout);
        $this->assertLessThan((int) config('queue.connections.database.retry_after'), $timeout);
    }

    // --- Erros permanentes vs. erros que se repetem (pela fila, com o middleware do job) ---

    private function workOnce(Invoice $invoice): void
    {
        config(['queue.default' => 'database']);
        IssueInvoiceJob::dispatch($invoice->id);

        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);
    }

    #[Test]
    public function invalid_tax_config_fails_the_job_on_the_first_attempt(): void
    {
        Log::spy();
        $this->useTax(150, '', self::REASON);
        $invoice = $this->pendingInvoice();

        $this->workOnce($invoice);

        $this->assertSame(InvoiceStatus::Failed, $invoice->fresh()->status);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    #[Test]
    public function invoiceexpress_without_api_key_is_retried_instead_of_failing(): void
    {
        // Configuração do driver em falta corrige-se no .env: a fila repete com backoff.
        Log::spy();
        $this->useInvoiceExpress();
        config(['services.invoiceexpress.api_key' => null]);
        $invoice = $this->pendingInvoice();

        $this->workOnce($invoice);

        $this->assertSame(InvoiceStatus::Pending, $invoice->fresh()->status);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    // --- Imposto usado na emissão ---

    #[Test]
    public function log_driver_stores_the_configured_exemption(): void
    {
        $this->useTax(0, 'M07', self::REASON);
        $invoice = $this->pendingInvoice();

        $this->runJob($invoice->id);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Issued, $fresh->status);
        $this->assertSame(0, $fresh->vat_rate);
        $this->assertSame('M07', $fresh->vat_exemption_code);
        $this->assertSame(self::REASON, $fresh->vat_exemption_reason);
    }

    #[Test]
    public function exemption_without_code_stores_the_reason_and_a_null_code(): void
    {
        $this->useTax(0, '', self::REASON);
        $invoice = $this->pendingInvoice();

        $this->runJob($invoice->id);

        $fresh = $invoice->fresh();
        $this->assertSame(0, $fresh->vat_rate);
        $this->assertNull($fresh->vat_exemption_code);
        $this->assertSame(self::REASON, $fresh->vat_exemption_reason);
    }

    #[Test]
    public function demo_driver_stores_the_configured_exemption(): void
    {
        config(['services.invoicing.driver' => 'demo']);
        Mail::fake();
        Storage::fake('local');
        $this->useTax(0, 'M07', self::REASON);
        $invoice = $this->pendingInvoice();

        $this->runJob($invoice->id);

        $fresh = $invoice->fresh();
        $this->assertSame('demo', $fresh->provider);
        $this->assertSame(0, $fresh->vat_rate);
        $this->assertSame('M07', $fresh->vat_exemption_code);
        $this->assertSame(self::REASON, $fresh->vat_exemption_reason);
    }

    #[Test]
    public function taxed_invoice_stores_the_rate_without_exemption(): void
    {
        $this->useTax(23, '', self::REASON);
        $invoice = $this->pendingInvoice();

        $this->runJob($invoice->id);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Issued, $fresh->status);
        $this->assertSame(23, $fresh->vat_rate);
        $this->assertNull($fresh->vat_exemption_code);
        $this->assertNull($fresh->vat_exemption_reason);
    }

    #[Test]
    public function invoice_data_carries_the_configured_tax(): void
    {
        $this->useTax(0, 'M07', self::REASON);
        $invoice = $this->pendingInvoice();
        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('issue')->once()
                ->withArgs(fn (InvoiceCustomerData $customer, InvoiceData $data) => $data->tax->rate === 0
                    && $data->tax->exemptionCode === 'M07'
                    && $data->tax->exemptionReason === self::REASON)
                ->andReturn(new IssuedInvoice('log', null, 'LOG-2026-ABCDEF12', null, IssuedInvoice::ISSUED));
        });

        $this->runJob($invoice->id);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    #[Test]
    public function missing_billing_data_stores_no_tax(): void
    {
        $this->useTax(0, 'M07', self::REASON);
        $user = User::factory()->withBilling()->create();
        $user->forceFill(['nif' => null])->save();
        $invoice = $this->pendingInvoice(['user_id' => $user->id]);
        $this->expectNoIssuing();

        $this->runJob($invoice->id);

        $this->assertSame(InvoiceStatus::Failed, $invoice->fresh()->status);
        $this->assertNoTaxStored($invoice);
    }

    #[Test]
    public function exhausted_attempts_store_no_tax(): void
    {
        Log::spy();
        $this->useTax(0, 'M07', self::REASON);
        $invoice = $this->pendingInvoice();

        (new IssueInvoiceJob($invoice->id))->failed(InvoiceIssuingFailed::rejected('create', 500));

        $this->assertSame(InvoiceStatus::Failed, $invoice->fresh()->status);
        $this->assertNoTaxStored($invoice);
    }

    #[Test]
    public function invalid_tax_config_fails_the_job_without_issuing(): void
    {
        Log::spy();
        $this->useTax(150, '', self::REASON);
        $invoice = $this->pendingInvoice();
        $this->expectNoIssuing();

        try {
            $this->runJob($invoice->id);
            $this->fail('Uma taxa de IVA inválida devia fazer o job falhar.');
        } catch (InvalidArgumentException $e) {
            (new IssueInvoiceJob($invoice->id))->failed($e);
        }

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Failed, $fresh->status);
        $this->assertNull($fresh->number);
        $this->assertNull($fresh->issued_at);
        $this->assertNoTaxStored($invoice);
    }

    #[Test]
    public function retry_stores_the_tax_config_of_the_attempt_that_issued(): void
    {
        $invoice = $this->pendingInvoice();
        $this->useTax(23, '', null);
        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('issue')->once()->andThrow(InvoiceIssuingFailed::unreachable('create'));
        });

        try {
            $this->runJob($invoice->id);
            $this->fail('A InvoiceIssuingFailed devia propagar para a fila repetir o job.');
        } catch (InvoiceIssuingFailed) {
        }

        $this->assertNoTaxStored($invoice);

        $this->useTax(0, 'M07', self::REASON);
        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('issue')->once()
                ->andReturn(new IssuedInvoice('log', null, 'LOG-2026-ABCDEF12', null, IssuedInvoice::ISSUED));
        });

        $this->runJob($invoice->id);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Issued, $fresh->status);
        $this->assertSame(0, $fresh->vat_rate);
        $this->assertSame('M07', $fresh->vat_exemption_code);
        $this->assertSame(self::REASON, $fresh->vat_exemption_reason);
    }

    #[Test]
    public function invoice_issued_before_the_tax_columns_is_left_untouched(): void
    {
        // Um documento fiscal não se altera: faturas antigas ficam com o imposto a null.
        $this->useTax(0, 'M07', self::REASON);
        $invoice = $this->pendingInvoice(['status' => InvoiceStatus::Issued, 'number' => 'LOG-2026-AAAAAAAA']);
        $invoice->forceFill(['vat_rate' => null, 'vat_exemption_code' => null, 'vat_exemption_reason' => null])->save();
        $this->expectNoIssuing();

        $this->runJob($invoice->id);

        $this->assertSame('LOG-2026-AAAAAAAA', $invoice->fresh()->number);
        $this->assertNoTaxStored($invoice);
    }

    #[Test]
    public function customer_data_comes_from_the_billing_fields_of_the_user(): void
    {
        $invoice = $this->pendingInvoice();
        $user = $invoice->user;
        $this->mock(InvoiceService::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('issue')->once()
                ->withArgs(fn (InvoiceCustomerData $customer) => $customer->code === $user->id
                    && $customer->nif === $user->nif
                    && $customer->email === $user->email)
                ->andReturn(new IssuedInvoice('log', null, 'LOG-2026-ABCDEF12', null, IssuedInvoice::ISSUED));
        });

        $this->runJob($invoice->id);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    #[Test]
    public function already_issued_invoice_is_not_issued_again(): void
    {
        $invoice = $this->pendingInvoice(['status' => InvoiceStatus::Issued, 'number' => 'LOG-2026-AAAAAAAA']);
        $this->expectNoIssuing();

        $this->runJob($invoice->id);

        $this->assertSame('LOG-2026-AAAAAAAA', $invoice->fresh()->number);
    }

    #[Test]
    public function missing_invoice_is_ignored_without_exception(): void
    {
        $this->expectNoIssuing();

        $this->runJob((string) Str::uuid());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function user_without_complete_billing_data_marks_the_invoice_failed_without_retrying(): void
    {
        Log::spy();
        $user = User::factory()->withBilling()->create();
        $user->forceFill(['billing_city' => null])->save();
        $invoice = $this->pendingInvoice(['user_id' => $user->id]);
        $this->expectNoIssuing();

        $this->runJob($invoice->id);

        $this->assertSame(InvoiceStatus::Failed, $invoice->fresh()->status);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => ($context['invoice_id'] ?? null) === $invoice->id
                && ($context['stripe_invoice_id'] ?? null) === $invoice->stripe_invoice_id
                && $this->hasNoPersonalData($message, $context, $user))
            ->once();
    }

    #[Test]
    public function anonymized_user_marks_the_invoice_failed_and_keeps_the_row(): void
    {
        $invoice = $this->pendingInvoice();
        $user = $invoice->user;
        $user->forceFill(['stripe_id' => 'cus_test_1'])->save();
        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('delete')->once()->with('cus_test_1');
        });
        $user->anonymizeAndDelete();
        $this->expectNoIssuing();

        $this->runJob($invoice->id);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'user_id' => $user->id,
            'status' => 'failed',
        ]);
    }

    #[Test]
    public function soft_deleted_user_with_billing_data_is_invoiced(): void
    {
        $invoice = $this->pendingInvoice();
        $invoice->user->delete();

        $this->runJob($invoice->id);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    #[Test]
    public function issuing_failure_propagates_and_stores_the_draft_id_for_the_retry(): void
    {
        $invoice = $this->pendingInvoice();
        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('issue')->once()->andThrow(InvoiceIssuingFailed::rejected('finalize', 500, 'ie_1'));
        });

        try {
            $this->runJob($invoice->id);
            $this->fail('A InvoiceIssuingFailed devia propagar para a fila repetir o job.');
        } catch (InvoiceIssuingFailed $e) {
            $this->assertSame('finalize', $e->step);
        }

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Pending, $fresh->status);
        $this->assertSame('ie_1', $fresh->provider_invoice_id);
    }

    #[Test]
    public function retry_resumes_from_the_stored_draft_instead_of_creating_another(): void
    {
        $invoice = $this->pendingInvoice(['provider_invoice_id' => 'ie_1']);
        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('issue')->once()
                ->with(Mockery::type(InvoiceCustomerData::class), Mockery::type(InvoiceData::class), 'ie_1', Mockery::type(Closure::class))
                ->andReturn(new IssuedInvoice('invoiceexpress', 'ie_1', 'FR ASPEC2026/1', 'https://x.test/doc', IssuedInvoice::ISSUED));
        });

        $this->runJob($invoice->id);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    #[Test]
    public function first_attempt_passes_no_draft_id(): void
    {
        $invoice = $this->pendingInvoice();
        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('issue')->once()
                ->with(Mockery::type(InvoiceCustomerData::class), Mockery::type(InvoiceData::class), null, Mockery::type(Closure::class))
                ->andReturn(new IssuedInvoice('log', null, 'LOG-2026-ABCDEF12', null, IssuedInvoice::ISSUED));
        });

        $this->runJob($invoice->id);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    #[Test]
    public function failed_marks_the_pending_invoice_failed_and_logs_without_personal_data(): void
    {
        Log::spy();
        $invoice = $this->pendingInvoice();

        (new IssueInvoiceJob($invoice->id))->failed(new RuntimeException('falha para '.$invoice->user->email));

        $this->assertSame(InvoiceStatus::Failed, $invoice->fresh()->status);
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []) => $message === 'Não foi possível emitir a fatura; emitir manualmente.'
                && $context == [
                    'invoice_id' => $invoice->id,
                    'exception' => RuntimeException::class,
                    'step' => null,
                ])
            ->once();
    }

    #[Test]
    public function failed_logs_the_step_of_an_issuing_failure(): void
    {
        Log::spy();
        $invoice = $this->pendingInvoice();

        (new IssueInvoiceJob($invoice->id))->failed(InvoiceIssuingFailed::rejected('finalize', 500, 'ie_1'));

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []) => ($context['invoice_id'] ?? null) === $invoice->id
                && ($context['exception'] ?? null) === InvoiceIssuingFailed::class
                && ($context['step'] ?? null) === 'finalize')
            ->once();
    }

    #[Test]
    public function failed_does_not_overwrite_an_issued_invoice(): void
    {
        Log::spy();
        $invoice = $this->pendingInvoice(['status' => InvoiceStatus::Issued, 'number' => 'LOG-2026-AAAAAAAA']);

        (new IssueInvoiceJob($invoice->id))->failed(new RuntimeException('falha'));

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    #[Test]
    public function serialized_job_carries_only_the_invoice_id(): void
    {
        $invoice = $this->pendingInvoice(['amount' => 7389]);
        $user = $invoice->user;

        $job = new IssueInvoiceJob($invoice->id);
        $payload = serialize($job);

        $this->assertSame($invoice->id, $job->invoiceId);
        $this->assertStringContainsString($invoice->id, $payload);
        $this->assertStringNotContainsString(self::NIF, $payload);
        $this->assertStringNotContainsString($user->email, $payload);
        $this->assertStringNotContainsString($invoice->stripe_invoice_id, $payload);
        $this->assertStringNotContainsString('i:7389;', $payload);
    }

    #[Test]
    public function job_is_retried_five_times_with_increasing_backoff(): void
    {
        $job = new IssueInvoiceJob((string) Str::uuid());

        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 900, 3600], $job->backoff());
    }
}
