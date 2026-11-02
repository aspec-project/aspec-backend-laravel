<?php

namespace Tests\Feature\Invoicing;

use App\Contracts\InvoiceService;
use App\Exceptions\InvoiceIssuingFailed;
use App\Exceptions\InvoicePdfUnavailable;
use App\Services\Invoicing\Data\IssuedInvoice;
use App\Services\Invoicing\InvoiceExpressInvoiceService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsInvoiceData;
use Tests\TestCase;
use Throwable;

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
            'services.invoiceexpress.item_name' => 'Quota ASPEC',
            'services.invoiceexpress.tax_name' => 'IVA23',
            'services.invoicing.vat_rate' => 23,
            'services.invoicing.vat_exemption_code' => null,
            'services.invoicing.vat_exemption_reason' => null,
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

    /**
     * Documento como a InvoiceExpress o devolve: a raiz tem o nome do tipo (aqui, invoice_receipt).
     * O total (base + IVA) bate certo, por omissão, com o valor cobrado em invoice() (60,00 €).
     */
    private function document(string $status, float $total = 60.0): array
    {
        return ['invoice_receipt' => [
            'id' => self::DOCUMENT_ID,
            'status' => $status,
            'total' => $total,
            'sequence_number' => self::NUMBER,
            'permalink' => self::PERMALINK,
        ]];
    }

    /**
     * Rascunho criado, com o total (base + IVA) calculado pela InvoiceExpress.
     * Por omissão bate certo com o valor cobrado em invoice() (60,00 €).
     */
    private function createdResponse(float $total = 60.0)
    {
        return Http::response(['invoice_receipt' => ['id' => self::DOCUMENT_ID, 'status' => 'draft', 'total' => $total]], 201);
    }

    private function useExemptConfig(): void
    {
        config([
            'services.invoicing.vat_rate' => 0,
            'services.invoicing.vat_exemption_code' => 'M07',
            'services.invoicing.vat_exemption_reason' => self::EXEMPTION_REASON,
            'services.invoiceexpress.tax_name' => 'IVA0',
        ]);
    }

    private function fakeIssuingWithTotal(float $createdTotal = 30.0): void
    {
        Http::fake([
            self::CREATE_URL => $this->createdResponse(total: $createdTotal),
            self::CHANGE_STATE_URL => Http::response($this->document('settled'), 200),
            self::EMAIL_URL => Http::response([], 200),
        ]);
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
        $this->assertSame('Quota ASPEC', data_get($payload, 'invoice.items.0.name'));
        $this->assertSame('Quota ASPEC — período de 07/10/2026 a 06/10/2027', data_get($payload, 'invoice.items.0.description'));
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
        $this->useExemptConfig();
        $this->fakeIssuingWithTotal();

        $issued = $this->service()->issue($this->customer(), $this->invoice(amount: 3000, tax: $this->exemptTax('M07')));

        $payload = $this->sentCreatePayload();
        $this->assertSame('30.00', data_get($payload, 'invoice.items.0.unit_price'));
        $this->assertSame('IVA0', data_get($payload, 'invoice.items.0.tax.name'));
        $this->assertSame('M07', data_get($payload, 'invoice.tax_exemption'));
        $this->assertSame(IssuedInvoice::ISSUED, $issued->status);
    }

    #[Test]
    public function exemption_code_comes_from_the_invoice_tax(): void
    {
        $this->useExemptConfig();
        $this->fakeIssuingWithTotal();

        $this->service()->issue($this->customer(), $this->invoice(amount: 3000, tax: $this->exemptTax('M10')));

        $this->assertSame('M10', data_get($this->sentCreatePayload(), 'invoice.tax_exemption'));
    }

    #[Test]
    public function exemption_reason_text_is_never_sent(): void
    {
        // Na fatura fiscal a menção legal sai do código da AT; um texto livre ao lado podia contradizê-la.
        $this->useExemptConfig();
        $this->fakeIssuingWithTotal();

        $this->service()->issue($this->customer(), $this->invoice(amount: 3000, tax: $this->exemptTax('M07')));

        $payload = $this->sentCreatePayload();
        $this->assertArrayNotHasKey('observations', $payload['invoice']);
        $this->assertStringNotContainsString(self::EXEMPTION_REASON, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function exempt_invoice_without_exemption_code_fails_before_any_request(): void
    {
        $this->useExemptConfig();
        Http::fake();

        try {
            $this->service()->issue($this->customer(), $this->invoice(amount: 3000, tax: $this->exemptTax(code: null)));
            $this->fail('Era esperada uma InvoiceIssuingFailed.');
        } catch (InvoiceIssuingFailed $e) {
            $this->assertSame('tax', $e->step);
            $this->assertSame('Falta o código do motivo de isenção de IVA (INVOICE_VAT_EXEMPTION_CODE).', $e->getMessage());
            $this->assertNull($e->httpStatus);
            $this->assertNull($e->providerInvoiceId);
            $this->assertNoSecretsOrPersonalData($e->getMessage());
        }

        Http::assertNothingSent();
    }

    // --- Total devolvido pela InvoiceExpress ---

    #[Test]
    public function draft_with_a_total_different_from_the_charge_is_not_finalized(): void
    {
        // Ex.: taxa IVA23 na conta com a quota isenta: fatura de 36,90 € para um débito de 30 €.
        $this->useExemptConfig();
        $this->fakeIssuingWithTotal(createdTotal: 36.90);

        try {
            $this->service()->issue($this->customer(), $this->invoice(amount: 3000, tax: $this->exemptTax('M07')));
            $this->fail('Era esperada uma InvoiceIssuingFailed.');
        } catch (InvoiceIssuingFailed $e) {
            $this->assertSame('create', $e->step);
            $this->assertNoSecretsOrPersonalData($e->getMessage());
            // Sem o id do rascunho, a nova tentativa do job não finaliza o documento com o total errado.
            $this->assertNull($e->providerInvoiceId);
        }

        $this->assertSame(0, $this->countRequests('PUT', '/invoice_receipts/987/change-state.json'));
        $this->assertSame(0, $this->countRequests('PUT', '/invoice_receipts/987/email-document.json'));
    }

    #[Test]
    public function draft_with_the_charged_total_is_finalized(): void
    {
        $this->useExemptConfig();
        $this->fakeIssuingWithTotal(createdTotal: 30.0);

        $issued = $this->service()->issue($this->customer(), $this->invoice(amount: 3000, tax: $this->exemptTax('M07')));

        $this->assertSame(1, $this->countRequests('PUT', '/invoice_receipts/987/change-state.json'));
        $this->assertSame(self::NUMBER, $issued->number);
    }

    #[Test]
    public function draft_without_a_total_is_not_finalized(): void
    {
        // Sem total não há como confirmar o valor: falha fechado, como a falta do código de isenção.
        Http::fake([
            self::CREATE_URL => Http::response(['invoice_receipt' => ['id' => self::DOCUMENT_ID, 'status' => 'draft']], 201),
            self::CHANGE_STATE_URL => Http::response($this->document('settled'), 200),
            self::EMAIL_URL => Http::response([], 200),
        ]);

        $e = $this->issueExpectingFailure();

        $this->assertSame('create', $e->step);
        $this->assertSame(0, $this->countRequests('PUT', '/invoice_receipts/987/change-state.json'));
        $this->assertSame(0, $this->countRequests('PUT', '/invoice_receipts/987/email-document.json'));
    }

    public static function taxedTotalsWithinOneCent(): array
    {
        return [
            'um cêntimo acima' => [30.01],
            'um cêntimo abaixo' => [29.99],
        ];
    }

    #[Test]
    #[DataProvider('taxedTotalsWithinOneCent')]
    public function taxed_draft_one_cent_off_is_finalized(float $total): void
    {
        // Com IVA, a InvoiceExpress arredonda base × taxa e pode ficar a 1 cêntimo do valor cobrado.
        $this->fakeIssuingWithTotal(createdTotal: $total);

        $issued = $this->service()->issue($this->customer(), $this->invoice(amount: 3000, tax: $this->taxedAt(23)));

        $this->assertSame(1, $this->countRequests('PUT', '/invoice_receipts/987/change-state.json'));
        $this->assertSame(IssuedInvoice::ISSUED, $issued->status);
    }

    public static function taxedTotalsTwoCentsOff(): array
    {
        return [
            'dois cêntimos acima' => [30.02],
            'dois cêntimos abaixo' => [29.98],
        ];
    }

    #[Test]
    #[DataProvider('taxedTotalsTwoCentsOff')]
    public function taxed_draft_two_cents_off_is_not_finalized(float $total): void
    {
        $this->fakeIssuingWithTotal(createdTotal: $total);

        try {
            $this->service()->issue($this->customer(), $this->invoice(amount: 3000, tax: $this->taxedAt(23)));
            $this->fail('Era esperada uma InvoiceIssuingFailed.');
        } catch (InvoiceIssuingFailed $e) {
            $this->assertSame('create', $e->step);
            $this->assertNull($e->providerInvoiceId);
        }

        $this->assertSame(0, $this->countRequests('PUT', '/invoice_receipts/987/change-state.json'));
    }

    #[Test]
    public function exempt_draft_one_cent_off_is_not_finalized(): void
    {
        // Sem IVA não há arredondamento: o total tem de ser exatamente o valor cobrado.
        $this->useExemptConfig();
        $this->fakeIssuingWithTotal(createdTotal: 30.01);

        try {
            $this->service()->issue($this->customer(), $this->invoice(amount: 3000, tax: $this->exemptTax('M07')));
            $this->fail('Era esperada uma InvoiceIssuingFailed.');
        } catch (InvoiceIssuingFailed $e) {
            $this->assertSame('create', $e->step);
            $this->assertNull($e->providerInvoiceId);
        }

        $this->assertSame(0, $this->countRequests('PUT', '/invoice_receipts/987/change-state.json'));
    }

    #[Test]
    public function resumed_draft_with_the_charged_total_is_finalized(): void
    {
        $this->useExemptConfig();
        Http::fake([
            self::SHOW_URL => Http::response($this->document('draft', total: 30.0), 200),
            self::CHANGE_STATE_URL => Http::response($this->document('settled', total: 30.0), 200),
            self::EMAIL_URL => Http::response([], 200),
        ]);

        $issued = $this->service()->issue($this->customer(), $this->invoice(amount: 3000, tax: $this->exemptTax('M07')), '987');

        $this->assertSame(0, $this->countRequests('POST', '/invoice_receipts.json'));
        $this->assertSame(1, $this->countRequests('PUT', '/invoice_receipts/987/change-state.json'));
        $this->assertSame(self::NUMBER, $issued->number);
    }

    #[Test]
    public function resumed_draft_with_a_different_total_is_not_finalized(): void
    {
        // Rascunho de uma tentativa anterior com outro imposto: nunca vira documento fiscal.
        $this->useExemptConfig();
        Http::fake([
            self::SHOW_URL => Http::response($this->document('draft', total: 36.90), 200),
            self::CHANGE_STATE_URL => Http::response($this->document('settled', total: 36.90), 200),
            self::EMAIL_URL => Http::response([], 200),
        ]);

        try {
            $this->service()->issue($this->customer(), $this->invoice(amount: 3000, tax: $this->exemptTax('M07')), '987');
            $this->fail('Era esperada uma InvoiceIssuingFailed.');
        } catch (InvoiceIssuingFailed $e) {
            // Sem o id, a nova tentativa cria outro rascunho em vez de voltar a este.
            $this->assertNull($e->providerInvoiceId);
            $this->assertNoSecretsOrPersonalData($e->getMessage());
        }

        $this->assertSame(0, $this->countRequests('POST', '/invoice_receipts.json'));
        $this->assertSame(0, $this->countRequests('PUT', '/invoice_receipts/987/change-state.json'));
        $this->assertSame(0, $this->countRequests('PUT', '/invoice_receipts/987/email-document.json'));
    }

    #[Test]
    public function taxed_draft_whose_total_matches_the_charge_is_finalized(): void
    {
        // 30,00 € com IVA 23 %: base 24,39 €, e a InvoiceExpress devolve o total de 30,00 €.
        $this->fakeIssuingWithTotal(createdTotal: 30.0);

        $issued = $this->service()->issue($this->customer(), $this->invoice(amount: 3000, tax: $this->taxedAt(23)));

        $this->assertSame('24.39', data_get($this->sentCreatePayload(), 'invoice.items.0.unit_price'));
        $this->assertSame('IVA23', data_get($this->sentCreatePayload(), 'invoice.items.0.tax.name'));
        $this->assertSame(IssuedInvoice::ISSUED, $issued->status);
    }

    #[Test]
    public function tax_exemption_is_omitted_on_a_taxed_invoice(): void
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
                ->push(['invoice_receipt' => ['id' => self::DOCUMENT_ID, 'status' => 'draft', 'total' => 60.0]], 201),
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

    // --- Rascunho guardado antes de finalizar ---

    #[Test]
    public function draft_id_is_handed_over_before_finalizing(): void
    {
        $this->fakeHappyPath();
        $handedOver = null;
        $changeStateRequestsAtThatTime = null;

        $this->service()->issue($this->customer(), $this->invoice(), null, function (string $draftId) use (&$handedOver, &$changeStateRequestsAtThatTime) {
            $handedOver = $draftId;
            $changeStateRequestsAtThatTime = $this->countRequests('PUT', '/invoice_receipts/987/change-state.json');
        });

        $this->assertSame('987', $handedOver);
        $this->assertSame(0, $changeStateRequestsAtThatTime);
    }

    #[Test]
    public function draft_id_is_handed_over_even_when_the_total_does_not_match(): void
    {
        // As novas tentativas voltam a este rascunho (e falham da mesma forma) em vez de criar outros.
        $this->useExemptConfig();
        $this->fakeIssuingWithTotal(createdTotal: 36.90);
        $handedOver = null;

        try {
            $this->service()->issue($this->customer(), $this->invoice(amount: 3000, tax: $this->exemptTax('M07')), null, function (string $draftId) use (&$handedOver) {
                $handedOver = $draftId;
            });
            $this->fail('Era esperada uma InvoiceIssuingFailed.');
        } catch (InvoiceIssuingFailed $e) {
            $this->assertSame('create', $e->step);
        }

        $this->assertSame('987', $handedOver);
        $this->assertSame(0, $this->countRequests('PUT', '/invoice_receipts/987/change-state.json'));
    }

    #[Test]
    public function resumed_draft_is_not_handed_over_again(): void
    {
        Http::fake([
            self::SHOW_URL => Http::response($this->document('draft'), 200),
            self::CHANGE_STATE_URL => Http::response($this->document('settled'), 200),
            self::EMAIL_URL => Http::response([], 200),
        ]);
        $calls = 0;

        $this->service()->issue($this->customer(), $this->invoice(), '987', function () use (&$calls) {
            $calls++;
        });

        $this->assertSame(0, $calls);
    }

    // --- Retoma a partir de um rascunho existente ---

    #[Test]
    public function resuming_from_a_draft_finalizes_and_emails_without_creating_another(): void
    {
        Http::fake([
            self::SHOW_URL => Http::response($this->document('draft'), 200),
            self::CHANGE_STATE_URL => Http::response($this->document('settled'), 200),
            self::EMAIL_URL => Http::response([], 200),
        ]);

        $issued = $this->service()->issue($this->customer(), $this->invoice(), '987');

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
        $this->assertGreaterThanOrEqual(1, $this->countRequests('GET', '/invoice_receipts/987.json'));
        $this->assertSame(1, $this->countRequests('PUT', '/invoice_receipts/987/email-document.json'));
        $this->assertSame(self::NUMBER, $issued->number);
        $this->assertSame(IssuedInvoice::ISSUED, $issued->status);
    }

    #[Test]
    public function resuming_a_missing_draft_fails_without_creating_or_emailing(): void
    {
        Http::fake([
            self::SHOW_URL => Http::response([], 404),
            self::CHANGE_STATE_URL => Http::response([], 404),
            self::EMAIL_URL => Http::response([], 200),
        ]);

        try {
            $this->service()->issue($this->customer(), $this->invoice(), '987');
            $this->fail('Era esperada uma InvoiceIssuingFailed.');
        } catch (InvoiceIssuingFailed $e) {
            $this->assertSame(404, $e->httpStatus);
            $this->assertNoSecretsOrPersonalData($e->getMessage());
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

    // --- PDF (proxy do documento) ---

    private const PDF_URL = '*aspec-test.app.invoicexpress.com/api/pdf/42.json*';

    private const DOWNLOAD_URL = 'https://cdn.invoicexpress.test/documents/42.pdf';

    private const PDF_BYTES = '%PDF-1.4 fatura de teste';

    private function pdfReady(string $pdfUrl = self::DOWNLOAD_URL)
    {
        return Http::response(['output' => ['pdfUrl' => $pdfUrl]], 200);
    }

    private function pdfExpectingFailure(string $documentId = '42'): InvoicePdfUnavailable
    {
        try {
            $this->service()->pdf($documentId);
        } catch (InvoicePdfUnavailable $e) {
            return $e;
        }

        $this->fail('Era esperada uma InvoicePdfUnavailable.');
    }

    private function countPdfRequests(): int
    {
        return $this->countRequests('GET', '/api/pdf/42.json');
    }

    #[Test]
    public function pdf_asks_for_the_document_and_downloads_it_from_the_returned_url(): void
    {
        Http::fake([
            self::PDF_URL => $this->pdfReady(),
            self::DOWNLOAD_URL => Http::response(self::PDF_BYTES, 200, ['Content-Type' => 'application/pdf']),
        ]);

        $pdf = $this->service()->pdf('42');

        $this->assertSame(self::PDF_BYTES, $pdf);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), self::BASE_URL.'/api/pdf/42.json')
            && str_contains($request->url(), 'api_key='.self::API_KEY));
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === self::DOWNLOAD_URL);
        Http::assertSentCount(2);
    }

    #[Test]
    public function pdf_still_being_generated_after_three_attempts_fails(): void
    {
        Sleep::fake();
        Http::fake([
            self::PDF_URL => Http::sequence()
                ->push([], 202)
                ->push([], 202)
                ->push([], 202),
        ]);

        $this->pdfExpectingFailure();

        $this->assertSame(3, $this->countPdfRequests());
        Http::assertNotSent(fn (Request $request) => $request->url() === self::DOWNLOAD_URL);
    }

    #[Test]
    public function pdf_generated_on_a_later_attempt_is_downloaded(): void
    {
        Sleep::fake();
        Http::fake([
            self::PDF_URL => Http::sequence()
                ->push([], 202)
                ->pushResponse($this->pdfReady()),
            self::DOWNLOAD_URL => Http::response(self::PDF_BYTES, 200),
        ]);

        $this->assertSame(self::PDF_BYTES, $this->service()->pdf('42'));
        $this->assertSame(2, $this->countPdfRequests());
    }

    public static function pdfErrorStatuses(): array
    {
        return [
            '404' => [404],
            '422' => [422],
            '500' => [500],
        ];
    }

    #[Test]
    #[DataProvider('pdfErrorStatuses')]
    public function pdf_error_response_fails_without_the_api_key(int $status): void
    {
        Http::fake([
            self::PDF_URL => Http::response(['errors' => [['error' => 'erro com '.self::API_KEY]]], $status),
        ]);

        $e = $this->pdfExpectingFailure();

        $this->assertSame($status, $e->httpStatus);
        $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        $this->assertNull($e->getPrevious());
    }

    #[Test]
    public function pdf_connection_failure_hides_the_url_and_the_api_key(): void
    {
        Http::fake([
            '*' => Http::failedConnection(
                'cURL error 28: Operation timed out for '.self::BASE_URL.'/api/pdf/42.json?api_key='.self::API_KEY
            ),
        ]);

        $e = $this->pdfExpectingFailure();

        $this->assertNull($e->httpStatus);
        $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        $this->assertStringNotContainsString('invoicexpress.com', $e->getMessage());
        $this->assertNull($e->getPrevious());
    }

    #[Test]
    public function pdf_download_error_fails(): void
    {
        Http::fake([
            self::PDF_URL => $this->pdfReady(),
            self::DOWNLOAD_URL => Http::response('', 404),
        ]);

        $this->pdfExpectingFailure();
    }

    public static function nonNumericDocumentIds(): array
    {
        return [
            'sobe um nível' => ['../1'],
            'com query' => ['1?x=2'],
            'com barra' => ['1/2'],
            'vazio' => [''],
        ];
    }

    #[Test]
    #[DataProvider('nonNumericDocumentIds')]
    public function non_numeric_document_id_is_rejected_before_any_request(string $documentId): void
    {
        // O id entra no caminho do URL: só dígitos, para não mudar o pedido feito com a api_key.
        Http::fake();

        try {
            $this->service()->pdf($documentId);
            $this->fail('Era esperada uma exceção.');
        } catch (Throwable $e) {
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function pdf_url_without_https_is_never_requested(): void
    {
        Http::fake([
            self::PDF_URL => $this->pdfReady('http://cdn.invoicexpress.test/documents/42.pdf'),
            '*' => Http::response(self::PDF_BYTES, 200),
        ]);

        $this->pdfExpectingFailure();

        Http::assertSentCount(1);
    }

    #[Test]
    public function downloaded_body_that_is_not_a_pdf_is_rejected(): void
    {
        Http::fake([
            self::PDF_URL => $this->pdfReady(),
            self::DOWNLOAD_URL => Http::response('<html>Página de login</html>', 200),
        ]);

        $this->pdfExpectingFailure();
    }

    #[Test]
    public function pdf_request_is_not_retried_on_a_server_error(): void
    {
        // Corre dentro de um pedido do membro: as novas tentativas automáticas alongavam a espera.
        Http::fake([
            self::PDF_URL => Http::response([], 500),
        ]);

        $this->pdfExpectingFailure();

        $this->assertSame(1, $this->countPdfRequests());
    }

    #[Test]
    public function pdf_download_redirect_is_not_followed(): void
    {
        Http::fake([
            self::PDF_URL => $this->pdfReady(),
            self::DOWNLOAD_URL => Http::response('', 302, ['Location' => 'https://evil.example/x.pdf']),
            'https://evil.example/*' => Http::response(self::PDF_BYTES, 200),
        ]);

        $e = $this->pdfExpectingFailure();

        $this->assertSame(302, $e->httpStatus);
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://evil.example'));
        Http::assertSentCount(2);
    }

    #[Test]
    public function pdf_download_declared_larger_than_10_mb_is_rejected(): void
    {
        Http::fake([
            self::PDF_URL => $this->pdfReady(),
            self::DOWNLOAD_URL => Http::response(self::PDF_BYTES, 200, ['Content-Length' => (string) (10 * 1024 * 1024 + 1)]),
        ]);

        $this->pdfExpectingFailure();
    }

    #[Test]
    public function pdf_download_body_larger_than_10_mb_is_rejected(): void
    {
        Http::fake([
            self::PDF_URL => $this->pdfReady(),
            self::DOWNLOAD_URL => Http::response('%PDF-'.str_repeat('x', 10 * 1024 * 1024), 200),
        ]);

        $this->pdfExpectingFailure();
    }

    #[Test]
    public function pdf_download_unexpected_error_hides_the_url(): void
    {
        Http::fake([
            self::PDF_URL => $this->pdfReady(),
            self::DOWNLOAD_URL => fn () => throw new \RuntimeException('Erro inesperado em '.self::DOWNLOAD_URL),
        ]);

        $e = $this->pdfExpectingFailure();

        $this->assertNull($e->getPrevious());
        $this->assertStringNotContainsString(self::DOWNLOAD_URL, $e->getMessage());
    }

    #[Test]
    public function pdf_download_never_sends_the_api_key(): void
    {
        Http::fake([
            self::PDF_URL => $this->pdfReady(),
            self::DOWNLOAD_URL.'*' => Http::response(self::PDF_BYTES, 200),
        ]);

        $this->service()->pdf('42');

        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), self::DOWNLOAD_URL)
            && (str_contains($request->url(), 'api_key') || str_contains(json_encode($request->headers()), self::API_KEY)));
    }
}
