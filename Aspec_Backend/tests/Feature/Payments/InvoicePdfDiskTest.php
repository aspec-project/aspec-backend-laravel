<?php

namespace Tests\Feature\Payments;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET /api/invoices/{invoice}/pdf com o PDF demo no disco privado do R2 (INVOICES_DISK=r2-invoices).
 * O download continua a passar só pela API, com o dono verificado; o bucket nunca tem URL.
 */
class InvoicePdfDiskTest extends TestCase
{
    use RefreshDatabase;

    private const PDF_BYTES = '%PDF-1.4 fatura demo de teste';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-20 10:00');
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('r2-invoices');
        config(['filesystems.invoices_disk' => 'r2-invoices', 'filesystems.media_disk' => 'public']);
    }

    private function demoInvoiceOn(string $disk, User $member): Invoice
    {
        $invoice = Invoice::factory()->for($member)->demo()->issued()->create();
        Storage::disk($disk)->put($invoice->provider_invoice_id, self::PDF_BYTES);

        return $invoice;
    }

    #[Test]
    public function owner_downloads_the_pdf_stored_on_the_configured_disk(): void
    {
        $member = User::factory()->withBilling()->create();
        $invoice = $this->demoInvoiceOn('r2-invoices', $member);
        Sanctum::actingAs($member);

        $response = $this->get("/api/invoices/{$invoice->id}/pdf");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(self::PDF_BYTES, $response->getContent());
    }

    #[Test]
    public function another_member_gets_404(): void
    {
        $owner = User::factory()->withBilling()->create();
        $invoice = $this->demoInvoiceOn('r2-invoices', $owner);
        Sanctum::actingAs(User::factory()->withBilling()->create());

        $this->getJson("/api/invoices/{$invoice->id}/pdf")
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Fatura não encontrada.');
    }

    #[Test]
    public function public_invoices_disk_never_serves_the_pdf(): void
    {
        config(['filesystems.invoices_disk' => 'public', 'filesystems.media_disk' => 'r2']);
        $member = User::factory()->withBilling()->create();
        $invoice = $this->demoInvoiceOn('public', $member);
        Sanctum::actingAs($member);

        $response = $this->getJson("/api/invoices/{$invoice->id}/pdf");

        $this->assertContains($response->status(), [404, 502]);
        $response->assertJsonPath('success', false);
        $this->assertStringNotContainsString(self::PDF_BYTES, (string) $response->getContent());
    }
}
