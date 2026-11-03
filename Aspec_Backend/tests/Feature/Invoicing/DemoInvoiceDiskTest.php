<?php

namespace Tests\Feature\Invoicing;

use App\Exceptions\InvoiceIssuingFailed;
use App\Mail\DemoInvoiceMail;
use App\Services\Invoicing\DemoInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsInvoiceData;
use Tests\TestCase;

/**
 * Driver demo com o disco das faturas escolhido pelo .env (INVOICES_DISK). O PDF tem NIF e
 * morada: o driver recusa qualquer disco que possa ser servido sem autenticação (o public, o
 * mesmo disco das imagens, ou um disco com URL), em vez de emitir para um sítio público.
 * Os discos r2 são sempre fakes locais.
 */
class DemoInvoiceDiskTest extends TestCase
{
    use BuildsInvoiceData, RefreshDatabase;

    private const PDF_BYTES = '%PDF-1.4 fatura de teste';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-20 10:00');
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('r2-invoices');
    }

    private function service(): DemoInvoiceService
    {
        return app(DemoInvoiceService::class);
    }

    private function expectedPath(): string
    {
        $number = 'DEMO-2026-'.strtoupper(substr(sha1('in_test_123'), 0, 8));

        return 'invoices/'.self::CUSTOMER_CODE."/{$number}.pdf";
    }

    private function useR2Invoices(): void
    {
        config(['filesystems.invoices_disk' => 'r2-invoices', 'filesystems.media_disk' => 'public']);
    }

    /**
     * Disco privado de teste com um URL público na definição (o erro que a guarda apanha).
     */
    private function useInvoicesDiskWithUrl(): void
    {
        config([
            'filesystems.disks.faturas-com-url' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/faturas-com-url'), 'url' => 'https://faturas.test'],
            'filesystems.invoices_disk' => 'faturas-com-url',
            'filesystems.media_disk' => 'public',
        ]);
        Storage::fake('faturas-com-url', ['url' => 'https://faturas.test']);
    }

    private function assertIssueRefused(string $disk): void
    {
        Mail::fake();
        Log::spy();

        try {
            $this->service()->issue($this->customer(), $this->invoice());
            $this->fail('Era esperada uma InvoiceIssuingFailed.');
        } catch (InvoiceIssuingFailed $e) {
            $this->assertSame('store', $e->step);
            $this->assertStringNotContainsString(self::NIF, $e->getMessage());
        }

        $this->assertSame([], Storage::disk($disk)->allFiles());
        Mail::assertNothingSent();
        Log::shouldHaveReceived('critical')->once();
    }

    // --- Disco configurado ---

    #[Test]
    public function issue_stores_the_pdf_on_the_configured_invoices_disk(): void
    {
        $this->useR2Invoices();
        Mail::fake();

        $issued = $this->service()->issue($this->customer(), $this->invoice());

        $this->assertSame($this->expectedPath(), $issued->providerInvoiceId);
        Storage::disk('r2-invoices')->assertExists($this->expectedPath());
        $this->assertStringStartsWith('%PDF-', Storage::disk('r2-invoices')->get($this->expectedPath()));
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function pdf_reads_from_the_configured_invoices_disk(): void
    {
        $this->useR2Invoices();
        Storage::disk('r2-invoices')->put($this->expectedPath(), self::PDF_BYTES);

        $this->assertSame(self::PDF_BYTES, $this->service()->pdf($this->expectedPath()));
    }

    #[Test]
    public function pdf_ignores_a_file_left_on_the_local_disk(): void
    {
        $this->useR2Invoices();
        Storage::disk('local')->put($this->expectedPath(), self::PDF_BYTES);

        $this->assertNull($this->service()->pdf($this->expectedPath()));
    }

    #[Test]
    public function email_attachment_is_read_from_the_configured_invoices_disk(): void
    {
        $this->useR2Invoices();
        Mail::fake();

        $this->service()->issue($this->customer(), $this->invoice());

        $number = 'DEMO-2026-'.strtoupper(substr(sha1('in_test_123'), 0, 8));
        $expected = Attachment::fromStorageDisk('r2-invoices', $this->expectedPath())
            ->as("fatura-{$number}.pdf")
            ->withMime('application/pdf');

        Mail::assertSent(DemoInvoiceMail::class, fn (DemoInvoiceMail $mail) => count($mail->attachments()) === 1
            && $mail->hasAttachment($expected));
    }

    // --- Guarda: o disco das faturas nunca é público ---

    #[Test]
    public function issue_refuses_the_public_disk(): void
    {
        config(['filesystems.invoices_disk' => 'public', 'filesystems.media_disk' => 'r2']);

        $this->assertIssueRefused('public');
    }

    #[Test]
    public function issue_refuses_the_same_disk_as_the_media(): void
    {
        // O disco local não tem URL nem é o public: só a regra "igual ao disco de media" o recusa aqui.
        config(['filesystems.invoices_disk' => 'local', 'filesystems.media_disk' => 'local']);

        $this->assertIssueRefused('local');
    }

    #[Test]
    public function issue_refuses_a_disk_with_a_public_url(): void
    {
        $this->useInvoicesDiskWithUrl();

        $this->assertIssueRefused('faturas-com-url');
    }

    #[Test]
    public function issue_refuses_an_s3_disk_in_the_public_media_bucket(): void
    {
        // Disco S3 sem url, mas no bucket das imagens (público pelo r2.dev): só a regra do bucket o recusa.
        config([
            'filesystems.disks.r2.bucket' => 'aspec-media-teste',
            'filesystems.disks.s3-mesmo-bucket' => [
                'driver' => 's3', 'key' => 'test', 'secret' => 'test', 'region' => 'auto',
                'bucket' => 'aspec-media-teste', 'endpoint' => 'http://127.0.0.1:9',
                'use_path_style_endpoint' => true, 'throw' => false,
            ],
            'filesystems.invoices_disk' => 's3-mesmo-bucket',
            'filesystems.media_disk' => 'r2',
        ]);
        Mail::fake();
        Log::spy();

        try {
            $this->service()->issue($this->customer(), $this->invoice());
            $this->fail('Era esperada uma InvoiceIssuingFailed.');
        } catch (InvoiceIssuingFailed $e) {
            $this->assertSame('store', $e->step);
        }

        Mail::assertNothingSent();
        Log::shouldHaveReceived('critical')->once();
        $this->assertNull($this->service()->pdf('invoices/'.self::CUSTOMER_CODE.'/DEMO-2026-AAAAAAAA.pdf'));
    }

    #[Test]
    public function refusal_log_has_no_customer_data(): void
    {
        config(['filesystems.invoices_disk' => 'public', 'filesystems.media_disk' => 'r2']);
        Mail::fake();
        Log::spy();

        try {
            $this->service()->issue($this->customer(), $this->invoice());
        } catch (InvoiceIssuingFailed) {
        }

        Log::shouldHaveReceived('critical')->withArgs(function (string $message, array $context = []) {
            $logged = $message.json_encode($context);

            return ! str_contains($logged, self::NIF)
                && ! str_contains($logged, self::CUSTOMER_EMAIL)
                && ! str_contains($logged, self::CUSTOMER_ADDRESS);
        })->once();
    }

    #[Test]
    public function pdf_refuses_the_public_disk(): void
    {
        config(['filesystems.invoices_disk' => 'public', 'filesystems.media_disk' => 'r2']);
        Storage::disk('public')->put($this->expectedPath(), self::PDF_BYTES);

        $this->assertNull($this->service()->pdf($this->expectedPath()));
    }

    #[Test]
    public function pdf_refuses_the_same_disk_as_the_media(): void
    {
        config(['filesystems.invoices_disk' => 'local', 'filesystems.media_disk' => 'local']);
        Storage::disk('local')->put($this->expectedPath(), self::PDF_BYTES);

        $this->assertNull($this->service()->pdf($this->expectedPath()));
    }

    #[Test]
    public function pdf_refuses_a_disk_with_a_public_url(): void
    {
        $this->useInvoicesDiskWithUrl();
        Storage::disk('faturas-com-url')->put($this->expectedPath(), self::PDF_BYTES);

        $this->assertNull($this->service()->pdf($this->expectedPath()));
    }

    #[Test]
    public function default_private_disks_are_accepted(): void
    {
        config(['filesystems.invoices_disk' => 'local', 'filesystems.media_disk' => 'public']);
        Mail::fake();

        $this->service()->issue($this->customer(), $this->invoice());

        Storage::disk('local')->assertExists($this->expectedPath());
    }
}
