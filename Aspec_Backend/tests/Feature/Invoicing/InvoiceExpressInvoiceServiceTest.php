<?php

namespace Tests\Feature\Invoicing;

use App\Contracts\InvoiceService;
use App\Exceptions\InvoiceIssuingFailed;
use App\Services\Invoicing\Data\IssuedInvoice;
use App\Services\Invoicing\InvoiceExpressInvoiceService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsInvoiceData;
use Tests\TestCase;

/**
 * Driver InvoiceExpress: rascunho → finalizar → enviar por email.
 * Nunca há pedidos reais: todas as respostas são simuladas com Http::fake().
 */
class InvoiceExpressInvoiceServiceTest extends TestCase
{
    use BuildsInvoiceData;

    private const BASE_URL = 'https://aspec-test.app.invoicexpress.com';

    private const DOCUMENT_ID = 987;

    private const NUMBER = 'FR ASPEC2026/1';

    private const PERMALINK = 'https://aspec-test.app.invoicexpress.com/documents/abc123';

    private const CREATE_URL = '*aspec-test.app.invoicexpress.com/invoice_receipts.json*';

    private const CHANGE_STATE_URL = '*aspec-test.app.invoicexpress.com/invoice_receipts/987/change-state.json*';

    private const EMAIL_URL = '*aspec-test.app.invoicexpress.com/invoice_receipts/987/email-document.json*';

    private const SHOW_URL = '*aspec-test.app.invoicexpress.com/invoice_receipts/987.json*';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.invoicing.driver' => 'invoiceexpress',
            'services.invoiceexpress.account_name' => self::ACCOUNT_NAME,
            'services.invoiceexpress.api_key' => self::API_KEY,
            'services.invoiceexpress.document_type' => 'invoice_receipts',
            'services.invoiceexpress.sequence_id' => null,
            'services.invoiceexpress.item_name' => 'Quota mensal ASPEC',
            'services.invoiceexpress.tax_name' => 'IVA23',
            'services.invoiceexpress.vat_rate' => 23,
            'services.invoiceexpress.tax_exemption' => null,
            'services.invoiceexpress.timeout' => 10,
            'services.invoiceexpress.retry_times' => 3,
            'services.invoiceexpress.retry_sleep_ms' => 0,
        ]);
    }

    private function service(): InvoiceService
    {
        $service = app(InvoiceService::class);
        $this->assertInstanceOf(InvoiceExpressInvoiceService::class, $service);

        return $service;
    }

    /** Documento como a InvoiceExpress o devolve: a raiz tem o nome do tipo (aqui, invoice_receipt). */
    private function document(string $status): array
    {
        return ['invoice_receipt' => [
            'id' => self::DOCUMENT_ID,
            'status' => $status,
            'sequence_number' => self::NUMBER,
            'permalink' => self::PERMALINK,
        ]];
    }

    private function createdResponse()
    {
        return Http::response(['invoice_receipt' => ['id' => self::DOCUMENT_ID, 'status' => 'draft']], 201);
    }

    private function fakeHappyPath(): void
    {
        Http::fake([
            self::CREATE_URL => $this->createdResponse(),
            self::CHANGE_STATE_URL => Http::response($this->document('settled'), 200),
            self::EMAIL_URL => Http::response([], 200),
        ]);
    }

    private function sentCreatePayload(): array
    {
        $create = Http::recorded(fn (Request $request) => $request->method() === 'POST'
            && str_starts_with($request->url(), self::BASE_URL.'/invoice_receipts.json'))->first();

        $this->assertNotNull($create, 'O pedido de criação do rascunho não foi enviado.');

        return $create[0]->data();
    }

    private function countRequests(string $method, string $path): int
    {
        return Http::recorded(fn (Request $request) => $request->method() === $method
            && str_starts_with($request->url(), self::BASE_URL.$path))->count();
    }

    private function issueExpectingFailure(): InvoiceIssuingFailed
    {
        try {
            $this->service()->issue($this->customer(), $this->invoice());
        } catch (InvoiceIssuingFailed $e) {
            $this->assertNoSecretsOrPersonalData($e->getMessage());

            return $e;
        }

        $this->fail('Era esperada uma InvoiceIssuingFailed.');
    }

    private function assertNoSecretsOrPersonalData(string $text): void
    {
        foreach ([self::NIF, self::CUSTOMER_NAME, self::CUSTOMER_EMAIL, self::API_KEY, 'invoicexpress.com'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text);
        }
    }

    // --- Caminho feliz ---

    #[Test]
    public function issues_finalizes_and_emails_the_invoice_in_three_requests(): void
    {
        $this->fakeHappyPath();

        $this->service()->issue($this->customer(), $this->invoice());

        Http::assertSentCount(3);
        $this->assertSame(1, $this->countRequests('POST', '/invoice_receipts.json'));
        $this->assertSame(1, $this->countRequests('PUT', '/invoice_receipts/987/change-state.json'));
        $this->assertSame(1, $this->countRequests('PUT', '/invoice_receipts/987/email-document.json'));
    }

    #[Test]
    public function create_request_goes_to_the_account_url_with_the_api_key_in_the_query(): void
    {
        $this->fakeHappyPath();

        $this->service()->issue($this->customer(), $this->invoice());

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST') {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), self::BASE_URL.'/invoice_receipts.json')
                && ($query['api_key'] ?? null) === self::API_KEY;
        });
    }

    #[Test]
    public function create_payload_carries_the_client_and_the_item(): void
    {
        $this->fakeHappyPath();

        $this->service()->issue($this->customer(), $this->invoice());

        $payload = $this->sentCreatePayload();

        $this->assertSame('07/10/2026', data_get($payload, 'invoice.date'));
        $this->assertSame('07/10/2026', data_get($payload, 'invoice.due_date'));
        $this->assertSame('in_test_123', data_get($payload, 'invoice.reference'));

        $this->assertSame(self::NIF, data_get($payload, 'invoice.client.fiscal_id'));
        $this->assertSame(self::CUSTOMER_CODE, data_get($payload, 'invoice.client.code'));
        $this->assertSame(self::CUSTOMER_NAME, data_get($payload, 'invoice.client.name'));
        $this->assertSame(self::CUSTOMER_ADDRESS, data_get($payload, 'invoice.client.address'));
        $this->assertSame('1000-001', data_get($payload, 'invoice.client.postal_code'));
        $this->assertSame('Lisboa', data_get($payload, 'invoice.client.city'));
        $this->assertSame('Portugal', data_get($payload, 'invoice.client.country'));
        $this->assertSame(self::CUSTOMER_EMAIL, data_get($payload, 'invoice.client.email'));

        $this->assertCount(1, data_get($payload, 'invoice.items'));
        $this->assertSame('Quota mensal ASPEC', data_get($payload, 'invoice.items.0.name'));
        $this->assertSame('Quota mensal ASPEC — outubro 2026', data_get($payload, 'invoice.items.0.description'));
        $this->assertSame('1', data_get($payload, 'invoice.items.0.quantity'));
        $this->assertSame('IVA23', data_get($payload, 'invoice.items.0.tax.name'));
    }

    #[Test]
    public function finalizes_the_draft_it_created(): void
    {
        $this->fakeHappyPath();

        $this->service()->issue($this->customer(), $this->invoice());

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && str_starts_with($request->url(), self::BASE_URL.'/invoice_receipts/987/change-state.json')
            && data_get($request->data(), 'invoice.state') === 'finalized');
    }

    #[Test]
    public function emails_the_document_to_the_billing_email_with_the_invoice_number(): void
    {
        $this->fakeHappyPath();

        $this->service()->issue($this->customer(), $this->invoice());

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && str_starts_with($request->url(), self::BASE_URL.'/invoice_receipts/987/email-document.json')
            && data_get($request->data(), 'message.client.email') === self::CUSTOMER_EMAIL
            && data_get($request->data(), 'message.client.save') === '0'
            && data_get($request->data(), 'message.subject') === 'Fatura '.self::NUMBER.' — ASPEC');
    }

    #[Test]
    public function returns_the_number_and_permalink_given_by_invoiceexpress(): void
    {
        $this->fakeHappyPath();

        $issued = $this->service()->issue($this->customer(), $this->invoice());

        $this->assertSame('invoiceexpress', $issued->provider);
        $this->assertSame('987', $issued->providerInvoiceId);
        $this->assertSame(self::NUMBER, $issued->number);
        $this->assertSame(self::PERMALINK, $issued->pdfUrl);
        $this->assertSame(IssuedInvoice::ISSUED, $issued->status);
    }

    // --- IVA e campos opcionais ---

    #[Test]
    public function unit_price_excludes_vat_so_the_total_matches_the_amount_charged(): void
    {
        $this->fakeHappyPath();

        $this->service()->issue($this->customer(), $this->invoice(amount: 6000));

        // 60,00 € cobrados com IVA 23 % → preço unitário sem IVA = round(6000 / 1,23) = 4878 cêntimos.
        $this->assertSame('48.78', data_get($this->sentCreatePayload(), 'invoice.items.0.unit_price'));
    }

    #[Test]
    public function vat_exempt_invoice_uses_the_full_amount_and_sends_the_exemption_code(): void
    {
        config([
            'services.invoiceexpress.vat_rate' => 0,
            'services.invoiceexpress.tax_name' => 'IVA0',
            'services.invoiceexpress.tax_exemption' => 'M07',
        ]);
        $this->fakeHappyPath();

        $this->service()->issue($this->customer(), $this->invoice(amount: 6000));

        $payload = $this->sentCreatePayload();
        $this->assertSame('60.00', data_get($payload, 'invoice.items.0.unit_price'));
        $this->assertSame('IVA0', data_get($payload, 'invoice.items.0.tax.name'));
        $this->assertSame('M07', data_get($payload, 'invoice.tax_exemption'));
    }

    #[Test]
    public function tax_exemption_is_omitted_when_not_configured(): void
    {
        $this->fakeHappyPath();

        $this->service()->issue($this->customer(), $this->invoice());

        $this->assertArrayNotHasKey('tax_exemption', $this->sentCreatePayload()['invoice']);
    }

    #[Test]
    public function sequence_id_is_omitted_when_not_configured(): void
    {
        $this->fakeHappyPath();

        $this->service()->issue($this->customer(), $this->invoice());

        $this->assertArrayNotHasKey('sequence_id', $this->sentCreatePayload()['invoice']);
    }

    #[Test]
    public function sequence_id_is_sent_when_configured(): void
    {
        config(['services.invoiceexpress.sequence_id' => '12345']);
        $this->fakeHappyPath();

        $this->service()->issue($this->customer(), $this->invoice());

        $this->assertEquals('12345', data_get($this->sentCreatePayload(), 'invoice.sequence_id'));
    }

    // --- Erros na criação ---

    #[Test]
    public function rejected_create_fails_without_retrying(): void
    {
        Http::fake([
            self::CREATE_URL => Http::response(['errors' => [['error' => 'Pedido inválido.']]], 422),
        ]);

        $e = $this->issueExpectingFailure();

        $this->assertSame('create', $e->step);
        $this->assertSame(422, $e->httpStatus);
        $this->assertSame('Falha ao emitir a fatura (passo: create, HTTP 422).', $e->getMessage());
        Http::assertSentCount(1);
    }

    #[Test]
    public function transient_server_errors_are_retried_until_success(): void
    {
        Http::fake([
            self::CREATE_URL => Http::sequence()
                ->push([], 503)
                ->push([], 503)
                ->push(['invoice_receipt' => ['id' => self::DOCUMENT_ID, 'status' => 'draft']], 201),
            self::CHANGE_STATE_URL => Http::response($this->document('settled'), 200),
            self::EMAIL_URL => Http::response([], 200),
        ]);

        $issued = $this->service()->issue($this->customer(), $this->invoice());

        $this->assertSame(3, $this->countRequests('POST', '/invoice_receipts.json'));
        $this->assertSame(self::NUMBER, $issued->number);
        $this->assertSame(IssuedInvoice::ISSUED, $issued->status);
    }

    #[Test]
    public function persistent_server_errors_fail_after_the_configured_retries(): void
    {
        Http::fake([
            self::CREATE_URL => Http::sequence()->push([], 503)->push([], 503)->push([], 503),
        ]);

        $e = $this->issueExpectingFailure();

        $this->assertSame('create', $e->step);
        $this->assertSame(503, $e->httpStatus);
        $this->assertSame(3, $this->countRequests('POST', '/invoice_receipts.json'));
    }

    #[Test]
    public function connection_failure_hides_the_url_and_the_api_key(): void
    {
        // A mensagem do Guzzle inclui o URL com a chave: não pode chegar ao log nem à failed_jobs.
        Http::fake([
            '*' => Http::failedConnection(
                'cURL error 28: Operation timed out for '.self::BASE_URL.'/invoice_receipts.json?api_key='.self::API_KEY
            ),
        ]);

        $e = $this->issueExpectingFailure();

        $this->assertSame('create', $e->step);
        $this->assertNull($e->httpStatus);
        $this->assertSame('Sem ligação ao serviço de faturação (passo: create).', $e->getMessage());
        $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        $this->assertStringNotContainsString('invoicexpress.com', $e->getMessage());
        $this->assertNull($e->getPrevious());
    }

    #[Test]
    public function rejected_create_does_not_echo_personal_data_from_the_response(): void
    {
        Http::fake([
            self::CREATE_URL => Http::response([
                'errors' => [['error' => 'Cliente '.self::CUSTOMER_NAME.' com NIF '.self::NIF.' inválido.']],
            ], 422),
        ]);

        $e = $this->issueExpectingFailure();

        $this->assertStringNotContainsString(self::NIF, $e->getMessage());
        $this->assertNull($e->getPrevious());
    }

    // --- Finalização verificável ---

    #[Test]
    public function finalize_conflict_continues_when_the_document_is_already_final(): void
    {
        // O 1.º PUT pode ter sido aplicado apesar do timeout: confirma-se o estado em vez de falhar.
        Http::fake([
            self::CREATE_URL => $this->createdResponse(),
            self::CHANGE_STATE_URL => Http::response(['errors' => [['error' => 'Estado inválido.']]], 422),
            self::SHOW_URL => Http::response($this->document('settled'), 200),
            self::EMAIL_URL => Http::response([], 200),
        ]);

        $issued = $this->service()->issue($this->customer(), $this->invoice());

        $this->assertSame(1, $this->countRequests('GET', '/invoice_receipts/987.json'));
        $this->assertSame(self::NUMBER, $issued->number);
        $this->assertSame(self::PERMALINK, $issued->pdfUrl);
        $this->assertSame(IssuedInvoice::ISSUED, $issued->status);
    }

    #[Test]
    public function finalize_conflict_fails_when_the_document_is_still_a_draft(): void
    {
        Http::fake([
            self::CREATE_URL => $this->createdResponse(),
            self::CHANGE_STATE_URL => Http::response(['errors' => [['error' => 'Estado inválido.']]], 422),
            self::SHOW_URL => Http::response($this->document('draft'), 200),
            self::EMAIL_URL => Http::response([], 200),
        ]);

        $e = $this->issueExpectingFailure();

        $this->assertSame('finalize', $e->step);
        $this->assertSame(0, $this->countRequests('PUT', '/invoice_receipts/987/email-document.json'));
    }

    #[Test]
    public function finalize_without_a_sequence_number_fails_before_emailing(): void
    {
        // Sem número fiscal não há fatura para enviar nem para gravar.
        $document = $this->document('settled');
        unset($document['invoice_receipt']['sequence_number']);
        Http::fake([
            self::CREATE_URL => $this->createdResponse(),
            self::CHANGE_STATE_URL => Http::response($document, 200),
            self::EMAIL_URL => Http::response([], 200),
        ]);

        $e = $this->issueExpectingFailure();

        $this->assertSame('finalize', $e->step);
        $this->assertSame('987', $e->providerInvoiceId);
        $this->assertSame(0, $this->countRequests('PUT', '/invoice_receipts/987/email-document.json'));
    }

    #[Test]
    public function finalize_conflict_with_a_final_document_without_sequence_number_fails_before_emailing(): void
    {
        $document = $this->document('settled');
        unset($document['invoice_receipt']['sequence_number']);
        Http::fake([
            self::CREATE_URL => $this->createdResponse(),
            self::CHANGE_STATE_URL => Http::response(['errors' => [['error' => 'Estado inválido.']]], 422),
            self::SHOW_URL => Http::response($document, 200),
            self::EMAIL_URL => Http::response([], 200),
        ]);

        $e = $this->issueExpectingFailure();

        $this->assertSame('finalize', $e->step);
        $this->assertSame('987', $e->providerInvoiceId);
        $this->assertSame(0, $this->countRequests('PUT', '/invoice_receipts/987/email-document.json'));
    }

    #[Test]
    public function finalize_connection_failure_keeps_the_draft_id_so_the_job_can_resume(): void
    {
        // Com o id do rascunho, a nova tentativa finaliza o mesmo documento em vez de criar outro.
        Http::fake([
            self::CREATE_URL => $this->createdResponse(),
            self::CHANGE_STATE_URL => Http::failedConnection(
                'cURL error 28: Operation timed out for '.self::BASE_URL.'/invoice_receipts/987/change-state.json?api_key='.self::API_KEY
            ),
            self::EMAIL_URL => Http::response([], 200),
        ]);

        $e = $this->issueExpectingFailure();

        $this->assertSame('finalize', $e->step);
        $this->assertNull($e->httpStatus);
        $this->assertSame('987', $e->providerInvoiceId);
        $this->assertSame('Sem ligação ao serviço de faturação (passo: finalize).', $e->getMessage());
        $this->assertNull($e->getPrevious());
        $this->assertSame(0, $this->countRequests('PUT', '/invoice_receipts/987/email-document.json'));
    }

    #[Test]
    public function create_failure_has_no_provider_invoice_id(): void
    {
        Http::fake([
            self::CREATE_URL => Http::response(['errors' => [['error' => 'Pedido inválido.']]], 422),
        ]);

        $e = $this->issueExpectingFailure();

        $this->assertSame('create', $e->step);
        $this->assertNull($e->providerInvoiceId);
    }

    // --- Envio por email ---

    #[Test]
    public function email_failure_after_finalizing_does_not_throw(): void
    {
        // A fatura já tem número fiscal: lançar faria o job repetir e emitir uma segunda fatura.
        Log::spy();
        Http::fake([
            self::CREATE_URL => $this->createdResponse(),
            self::CHANGE_STATE_URL => Http::response($this->document('settled'), 200),
            self::EMAIL_URL => Http::response([], 500),
        ]);

        $issued = $this->service()->issue($this->customer(), $this->invoice());

        $this->assertSame(IssuedInvoice::ISSUED_NOT_SENT, $issued->status);
        $this->assertSame('issued_not_sent', $issued->status);
        $this->assertSame(self::NUMBER, $issued->number);
        $this->assertSame('987', $issued->providerInvoiceId);
    }

    #[Test]
    public function email_failure_is_logged_without_personal_data(): void
    {
        Log::spy();
        Http::fake([
            self::CREATE_URL => $this->createdResponse(),
            self::CHANGE_STATE_URL => Http::response($this->document('settled'), 200),
            self::EMAIL_URL => Http::response([], 500),
        ]);

        $this->service()->issue($this->customer(), $this->invoice());

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) {
                $logged = $message.json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                foreach ([self::NIF, self::CUSTOMER_EMAIL, self::CUSTOMER_NAME, self::API_KEY, 'invoicexpress.com'] as $forbidden) {
                    if (str_contains($logged, $forbidden)) {
                        return false;
                    }
                }

                return true;
            })
            ->atLeast()->once();
    }

    #[Test]
    public function unexpected_error_while_emailing_does_not_throw(): void
    {
        // Qualquer falha depois de finalizar (não só a falta de ligação) não pode fazer o job repetir.
        Log::spy();
        Http::fake([
            self::CREATE_URL => $this->createdResponse(),
            self::CHANGE_STATE_URL => Http::response($this->document('settled'), 200),
            self::EMAIL_URL => fn () => throw new \RuntimeException('Erro inesperado em '.self::BASE_URL.'?api_key='.self::API_KEY),
        ]);

        $issued = $this->service()->issue($this->customer(), $this->invoice());

        $this->assertSame(IssuedInvoice::ISSUED_NOT_SENT, $issued->status);
        $this->assertSame(self::NUMBER, $issued->number);
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) {
                $logged = $message.json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return ! str_contains($logged, self::API_KEY) && ! str_contains($logged, 'invoicexpress.com');
            })
            ->atLeast()->once();
    }

    // --- Retoma a partir de um rascunho existente ---

    #[Test]
    public function resuming_from_a_draft_finalizes_and_emails_without_creating_another(): void
    {
        Http::fake([
            self::CHANGE_STATE_URL => Http::response($this->document('settled'), 200),
            self::EMAIL_URL => Http::response([], 200),
        ]);

        $issued = $this->service()->issue($this->customer(), $this->invoice(), '987');

        Http::assertSentCount(2);
        $this->assertSame(0, $this->countRequests('POST', '/invoice_receipts.json'));
        $this->assertSame(1, $this->countRequests('PUT', '/invoice_receipts/987/change-state.json'));
        $this->assertSame(1, $this->countRequests('PUT', '/invoice_receipts/987/email-document.json'));
        $this->assertSame('987', $issued->providerInvoiceId);
        $this->assertSame(self::NUMBER, $issued->number);
        $this->assertSame(IssuedInvoice::ISSUED, $issued->status);
    }

    #[Test]
    public function resuming_a_draft_that_was_already_finalized_succeeds_without_creating_another(): void
    {
        Http::fake([
            self::CHANGE_STATE_URL => Http::response(['errors' => [['error' => 'Estado inválido.']]], 422),
            self::SHOW_URL => Http::response($this->document('settled'), 200),
            self::EMAIL_URL => Http::response([], 200),
        ]);

        $issued = $this->service()->issue($this->customer(), $this->invoice(), '987');

        $this->assertSame(0, $this->countRequests('POST', '/invoice_receipts.json'));
        $this->assertSame(1, $this->countRequests('GET', '/invoice_receipts/987.json'));
        $this->assertSame(1, $this->countRequests('PUT', '/invoice_receipts/987/email-document.json'));
        $this->assertSame(self::NUMBER, $issued->number);
        $this->assertSame(IssuedInvoice::ISSUED, $issued->status);
    }

    #[Test]
    public function resuming_a_missing_draft_fails_at_finalize_and_keeps_the_draft_id(): void
    {
        Http::fake([
            self::CHANGE_STATE_URL => Http::response([], 404),
            self::EMAIL_URL => Http::response([], 200),
        ]);

        try {
            $this->service()->issue($this->customer(), $this->invoice(), '987');
            $this->fail('Era esperada uma InvoiceIssuingFailed.');
        } catch (InvoiceIssuingFailed $e) {
            $this->assertSame('finalize', $e->step);
            $this->assertSame(404, $e->httpStatus);
            $this->assertSame('987', $e->providerInvoiceId);
        }

        $this->assertSame(0, $this->countRequests('POST', '/invoice_receipts.json'));
        $this->assertSame(0, $this->countRequests('PUT', '/invoice_receipts/987/email-document.json'));
    }

    // --- Moeda ---

    #[Test]
    public function non_euro_currency_is_rejected_before_any_request(): void
    {
        Http::fake();

        try {
            $this->service()->issue($this->customer(), $this->invoice(currency: 'usd'));
            $this->fail('Era esperada uma InvoiceIssuingFailed.');
        } catch (InvoiceIssuingFailed $e) {
            $this->assertSame('currency', $e->step);
            $this->assertNull($e->httpStatus);
            $this->assertSame('Moeda não suportada na faturação: usd.', $e->getMessage());
        }

        Http::assertNothingSent();
    }
}
