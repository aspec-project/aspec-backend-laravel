<?php

namespace Tests\Feature\Invoicing;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceIssuingFailed;
use App\Jobs\IssueInvoiceJob;
use App\Mail\DemoInvoiceMail;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoicing\Data\InvoiceCustomerData;
use App\Services\Invoicing\Data\IssuedInvoice;
use App\Services\Invoicing\DemoInvoiceService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsInvoiceData;
use Tests\TestCase;

/**
 * Driver demo: gera um PDF "sem validade fiscal" no disco privado, envia-o por email e permite
 * descarregá-lo depois. Serve para mostrar o requisito 8 sem conta InvoiceExpress.
 */
class DemoInvoiceServiceTest extends TestCase
{
    use BuildsInvoiceData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-20 10:00');
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function service(): DemoInvoiceService
    {
        return app(DemoInvoiceService::class);
    }

    private function expectedNumber(string $stripeInvoiceId = 'in_test_123'): string
    {
        return 'DEMO-2026-'.strtoupper(substr(sha1($stripeInvoiceId), 0, 8));
    }

    private function expectedPath(string $stripeInvoiceId = 'in_test_123', string $userId = self::CUSTOMER_CODE): string
    {
        return "invoices/{$userId}/{$this->expectedNumber($stripeInvoiceId)}.pdf";
    }

    /**
     * Guarda os dados com que a vista do PDF foi renderizada. O PDF comprime o texto, por isso o
     * conteúdo é verificado voltando a renderizar a vista com esses mesmos dados.
     *
     * @param  array<string, mixed>|null  $captured
     */
    private function captureInvoiceView(?array &$captured): void
    {
        View::composer('invoices.demo', function ($view) use (&$captured) {
            $captured = Arr::except($view->getData(), ['__env', 'app', 'errors']);
        });
    }

    private function renderedInvoiceView(?array $captured): string
    {
        $this->assertNotNull($captured, 'A vista invoices.demo não foi renderizada.');

        return view('invoices.demo', $captured)->render();
    }

    private function customerNamed(string $name): InvoiceCustomerData
    {
        return new InvoiceCustomerData(
            code: self::CUSTOMER_CODE,
            name: $name,
            nif: self::NIF,
            address: self::CUSTOMER_ADDRESS,
            postalCode: '1000-001',
            city: 'Lisboa',
            email: self::CUSTOMER_EMAIL,
        );
    }

    private function runJob(string $invoiceId): void
    {
        app()->call([new IssueInvoiceJob($invoiceId), 'handle']);
    }

    // --- D1: emissão ---

    #[Test]
    public function issue_stores_a_pdf_in_the_private_disk_under_the_member_folder(): void
    {
        Mail::fake();

        $this->service()->issue($this->customer(), $this->invoice());

        Storage::disk('local')->assertExists($this->expectedPath());
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($this->expectedPath()));
    }

    #[Test]
    public function issue_returns_a_demo_invoice_whose_provider_id_is_the_pdf_path(): void
    {
        Mail::fake();

        $issued = $this->service()->issue($this->customer(), $this->invoice());

        $this->assertSame('demo', $issued->provider);
        $this->assertSame($this->expectedNumber(), $issued->number);
        $this->assertMatchesRegularExpression('/^DEMO-2026-[A-F0-9]{8}$/', $issued->number);
        $this->assertSame($this->expectedPath(), $issued->providerInvoiceId);
        $this->assertNull($issued->pdfUrl);
        $this->assertSame(IssuedInvoice::ISSUED, $issued->status);
    }

    // --- D2: idempotência ---

    #[Test]
    public function issuing_the_same_payment_twice_gives_the_same_number_and_a_single_file(): void
    {
        Mail::fake();

        $first = $this->service()->issue($this->customer(), $this->invoice('in_test_123'));
        $second = $this->service()->issue($this->customer(), $this->invoice('in_test_123'));

        $this->assertSame($first->number, $second->number);
        $this->assertSame($first->providerInvoiceId, $second->providerInvoiceId);
        $this->assertCount(1, Storage::disk('local')->allFiles('invoices/'.self::CUSTOMER_CODE));
    }

    #[Test]
    public function draft_id_is_ignored(): void
    {
        Mail::fake();

        $issued = $this->service()->issue($this->customer(), $this->invoice('in_test_123'), 'rascunho-antigo');

        $this->assertSame($this->expectedNumber('in_test_123'), $issued->number);
        $this->assertSame($this->expectedPath('in_test_123'), $issued->providerInvoiceId);
    }

    // --- D3: nunca no disco público ---

    #[Test]
    public function public_disk_stays_empty(): void
    {
        // O disco public é servido em /storage sem autenticação: o PDF tem NIF e morada.
        Mail::fake();

        $this->service()->issue($this->customer(), $this->invoice());

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // --- D4: conteúdo do PDF ---

    #[Test]
    public function pdf_view_has_the_demo_warning_customer_billing_data_and_payment(): void
    {
        Mail::fake();
        $this->captureInvoiceView($captured);

        $this->service()->issue($this->customer(), $this->invoice('in_test_123'));
        $html = $this->renderedInvoiceView($captured);

        foreach ([
            'SEM VALIDADE FISCAL',
            self::CUSTOMER_NAME,
            self::NIF,
            self::CUSTOMER_ADDRESS,
            '1000-001',
            'Lisboa',
            '60,00 €',
            'Quota ASPEC — período de 07/10/2026 a 06/10/2027',
            $this->expectedNumber('in_test_123'),
            'in_test_123',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    #[Test]
    public function pdf_view_has_no_customer_email(): void
    {
        Mail::fake();
        $this->captureInvoiceView($captured);

        $this->service()->issue($this->customer(), $this->invoice());

        $this->assertStringNotContainsString(self::CUSTOMER_EMAIL, $this->renderedInvoiceView($captured));
    }

    #[Test]
    public function pdf_view_escapes_html_in_the_billing_name(): void
    {
        Mail::fake();
        $this->captureInvoiceView($captured);

        $this->service()->issue($this->customerNamed('<b>X</b>'), $this->invoice());
        $html = $this->renderedInvoiceView($captured);

        $this->assertStringNotContainsString('<b>X</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;X&lt;/b&gt;', $html);
    }

    // --- D5: email com o PDF ---

    #[Test]
    public function pdf_is_emailed_to_the_customer_with_the_number_in_the_subject(): void
    {
        Mail::fake();

        $this->service()->issue($this->customer(), $this->invoice());

        $number = $this->expectedNumber();
        Mail::assertSent(DemoInvoiceMail::class, fn (DemoInvoiceMail $mail) => $mail->hasTo(self::CUSTOMER_EMAIL)
            && $mail->hasSubject("Fatura {$number} — ASPEC"));
        Mail::assertSentCount(1);
    }

    #[Test]
    public function email_has_the_pdf_as_its_only_attachment(): void
    {
        Mail::fake();

        $this->service()->issue($this->customer(), $this->invoice());

        $number = $this->expectedNumber();
        $expected = Attachment::fromStorageDisk('local', $this->expectedPath())
            ->as("fatura-{$number}.pdf")
            ->withMime('application/pdf');

        Mail::assertSent(DemoInvoiceMail::class, fn (DemoInvoiceMail $mail) => count($mail->attachments()) === 1
            && $mail->hasAttachment($expected));
    }

    #[Test]
    public function email_is_sent_synchronously_and_never_queued(): void
    {
        // Em fila, o email e o NIF do cliente ficavam gravados na tabela jobs.
        $this->assertFalse(is_subclass_of(DemoInvoiceMail::class, ShouldQueue::class));

        Mail::fake();

        $this->service()->issue($this->customer(), $this->invoice());

        Mail::assertNothingQueued();
    }

    // --- D6: falha no email ---

    #[Test]
    public function email_failure_returns_issued_not_sent_and_keeps_the_file(): void
    {
        $this->useUnreachableSmtp();
        Log::spy();

        $issued = $this->service()->issue($this->customer(), $this->invoice());

        $this->assertSame(IssuedInvoice::ISSUED_NOT_SENT, $issued->status);
        $this->assertSame($this->expectedNumber(), $issued->number);
        Storage::disk('local')->assertExists($this->expectedPath());
    }

    #[Test]
    public function email_failure_is_logged_without_nif_or_email(): void
    {
        $this->useUnreachableSmtp();
        Log::spy();

        $this->service()->issue($this->customer(), $this->invoice());

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) {
                $logged = $message.json_encode($context, JSON_UNESCAPED_UNICODE);

                return ! str_contains($logged, self::NIF)
                    && ! str_contains($logged, self::CUSTOMER_EMAIL)
                    && ! str_contains($logged, self::CUSTOMER_NAME);
            })
            ->once();
    }

    /**
     * Mailer SMTP para uma porta local fechada: o envio falha sem depender de como o driver chama o Mail.
     */
    private function useUnreachableSmtp(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 9,
            'mail.mailers.smtp.timeout' => 2,
        ]);
    }

    // --- D7: moeda ---

    #[Test]
    public function non_euro_currency_is_rejected_without_creating_a_file(): void
    {
        Mail::fake();

        try {
            $this->service()->issue($this->customer(), $this->invoice(currency: 'usd'));
            $this->fail('Era esperada uma InvoiceIssuingFailed.');
        } catch (InvoiceIssuingFailed $e) {
            $this->assertSame('currency', $e->step);
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingSent();
    }

    // --- D8: falha ao gravar ---

    #[Test]
    public function failed_write_throws_a_store_failure_so_the_job_retries(): void
    {
        Mail::fake();
        $disk = Mockery::mock(Filesystem::class)->shouldIgnoreMissing();
        $disk->shouldReceive('put')->andReturn(false);
        Storage::set('local', $disk);

        try {
            $this->service()->issue($this->customer(), $this->invoice());
            $this->fail('Era esperada uma InvoiceIssuingFailed.');
        } catch (InvoiceIssuingFailed $e) {
            $this->assertSame('store', $e->step);
            $this->assertStringNotContainsString(self::NIF, $e->getMessage());
        }

        Mail::assertNothingSent();
    }

    // --- D9: pdf() ---

    #[Test]
    public function pdf_returns_the_stored_file_contents(): void
    {
        $path = 'invoices/'.self::CUSTOMER_CODE.'/DEMO-2026-ABCDEF12.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 conteudo de teste');

        $this->assertSame('%PDF-1.4 conteudo de teste', $this->service()->pdf($path));
    }

    #[Test]
    public function pdf_of_an_issued_invoice_returns_the_generated_file(): void
    {
        Mail::fake();
        $issued = $this->service()->issue($this->customer(), $this->invoice());

        $pdf = $this->service()->pdf($issued->providerInvoiceId);

        $this->assertSame(Storage::disk('local')->get($this->expectedPath()), $pdf);
    }

    public static function pathsOutsideTheInvoicesFolder(): array
    {
        return [
            'sobe um nível' => ['../x'],
            'outra pasta do disco' => ['logos/x.pdf'],
            'sai de invoices com ..' => ['invoices/../.env'],
            'vazio' => [''],
        ];
    }

    #[Test]
    #[DataProvider('pathsOutsideTheInvoicesFolder')]
    public function pdf_rejects_paths_outside_the_invoices_folder_without_reading_the_disk(string $path): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldNotReceive('get');
        $disk->shouldNotReceive('readStream');
        $disk->shouldNotReceive('exists');
        Storage::set('local', $disk);

        $this->assertNull($this->service()->pdf($path));
    }

    #[Test]
    public function pdf_does_not_return_existing_files_from_other_folders(): void
    {
        Storage::disk('local')->put('logos/x.pdf', '%PDF-1.4 outro ficheiro');

        $this->assertNull($this->service()->pdf('logos/x.pdf'));
    }

    #[Test]
    public function pdf_of_a_missing_file_returns_null_and_logs_a_warning(): void
    {
        Log::spy();

        $this->assertNull($this->service()->pdf('invoices/'.self::CUSTOMER_CODE.'/DEMO-2026-00000000.pdf'));

        Log::shouldHaveReceived('warning')->once();
    }

    // --- D10: job com o driver demo ---

    #[Test]
    public function job_with_the_demo_driver_stores_the_issued_invoice_and_its_path(): void
    {
        config(['services.invoicing.driver' => 'demo']);
        Mail::fake();
        $invoice = Invoice::factory()->create(['stripe_invoice_id' => 'in_test_job']);

        $this->runJob($invoice->id);

        $fresh = $invoice->fresh();
        $expectedPath = $this->expectedPath('in_test_job', $invoice->user_id);
        $this->assertSame(InvoiceStatus::Issued, $fresh->status);
        $this->assertSame('demo', $fresh->provider);
        $this->assertSame($this->expectedNumber('in_test_job'), $fresh->number);
        $this->assertSame($expectedPath, $fresh->provider_invoice_id);
        $this->assertNull($fresh->pdf_url);
        $this->assertNotNull($fresh->issued_at);
        Storage::disk('local')->assertExists($expectedPath);
    }

    #[Test]
    public function job_with_the_demo_driver_emails_the_member_billing_contact(): void
    {
        config(['services.invoicing.driver' => 'demo']);
        Mail::fake();
        $invoice = Invoice::factory()->create();
        $member = User::find($invoice->user_id);

        $this->runJob($invoice->id);

        Mail::assertSent(DemoInvoiceMail::class, fn (DemoInvoiceMail $mail) => $mail->hasTo($member->email));
    }

    #[Test]
    public function pdf_from_the_job_has_no_member_email_or_phone(): void
    {
        config(['services.invoicing.driver' => 'demo']);
        Mail::fake();
        $this->captureInvoiceView($captured);
        $invoice = Invoice::factory()->create();
        $member = User::find($invoice->user_id);

        $this->runJob($invoice->id);
        $html = $this->renderedInvoiceView($captured);

        $this->assertStringContainsString(e($member->billing_name), $html);
        $this->assertStringNotContainsString($member->email, $html);
        $this->assertStringNotContainsString($member->phone, $html);
    }
}
