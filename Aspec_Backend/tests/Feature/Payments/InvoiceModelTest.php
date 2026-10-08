<?php

namespace Tests\Feature\Payments;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\ProcessedWebhookEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tabelas `invoices` e `processed_webhook_events`: unicidade que garante a idempotência,
 * faturas guardadas mesmo de contas apagadas (obrigação fiscal) e campos sensíveis escondidos.
 */
class InvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function invoices_table_has_the_erd_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('invoices', [
            'id', 'user_id', 'stripe_invoice_id', 'provider', 'provider_invoice_id', 'number',
            'amount', 'currency', 'status', 'pdf_url', 'issued_at', 'created_at', 'updated_at',
        ]));
    }

    #[Test]
    public function processed_webhook_events_table_has_the_erd_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('processed_webhook_events', [
            'id', 'stripe_event_id', 'type', 'created_at', 'updated_at',
        ]));
    }

    #[Test]
    public function invoice_status_enum_has_the_four_states(): void
    {
        $this->assertSame(
            ['pending', 'issued', 'issued_not_sent', 'failed'],
            array_map(fn (InvoiceStatus $status) => $status->value, InvoiceStatus::cases()),
        );
        $this->assertSame(InvoiceStatus::Pending, InvoiceStatus::from('pending'));
        $this->assertSame(InvoiceStatus::Issued, InvoiceStatus::from('issued'));
        $this->assertSame(InvoiceStatus::IssuedNotSent, InvoiceStatus::from('issued_not_sent'));
        $this->assertSame(InvoiceStatus::Failed, InvoiceStatus::from('failed'));
    }

    #[Test]
    public function invoice_ids_are_uuids(): void
    {
        $invoice = Invoice::factory()->create();
        $event = ProcessedWebhookEvent::create(['stripe_event_id' => 'evt_test_1', 'type' => 'invoice.paid']);

        $this->assertTrue(str($invoice->id)->isUuid());
        $this->assertTrue(str($event->id)->isUuid());
    }

    #[Test]
    public function duplicate_stripe_invoice_id_is_rejected(): void
    {
        Invoice::factory()->create(['stripe_invoice_id' => 'in_test_dup']);

        $this->expectException(UniqueConstraintViolationException::class);

        Invoice::factory()->create(['stripe_invoice_id' => 'in_test_dup']);
    }

    #[Test]
    public function duplicate_stripe_event_id_is_rejected(): void
    {
        ProcessedWebhookEvent::create(['stripe_event_id' => 'evt_test_dup', 'type' => 'invoice.payment_succeeded']);

        $this->expectException(UniqueConstraintViolationException::class);

        ProcessedWebhookEvent::create(['stripe_event_id' => 'evt_test_dup', 'type' => 'invoice.payment_succeeded']);
    }

    #[Test]
    public function user_with_invoices_cannot_be_hard_deleted(): void
    {
        $invoice = Invoice::factory()->create();

        $this->expectException(QueryException::class);

        $invoice->user->forceDelete();
    }

    #[Test]
    public function invoice_finds_its_soft_deleted_user(): void
    {
        $invoice = Invoice::factory()->create();
        $invoice->user->delete();

        $user = Invoice::find($invoice->id)->user;

        $this->assertNotNull($user);
        $this->assertTrue($user->trashed());
    }

    #[Test]
    public function to_array_hides_the_pdf_url_and_provider_invoice_id(): void
    {
        $invoice = Invoice::factory()->create([
            'provider_invoice_id' => 'ie_1',
            'pdf_url' => 'https://aspec.app.invoicexpress.com/documents/abc',
        ]);

        $array = $invoice->fresh()->toArray();

        $this->assertArrayNotHasKey('pdf_url', $array);
        $this->assertArrayNotHasKey('provider_invoice_id', $array);
        $this->assertSame('ie_1', $invoice->fresh()->provider_invoice_id);
    }

    #[Test]
    public function attributes_are_cast(): void
    {
        $invoice = Invoice::factory()->create(['issued_at' => '2026-10-20 10:00:00'])->fresh();

        $this->assertInstanceOf(InvoiceStatus::class, $invoice->status);
        $this->assertSame(InvoiceStatus::Pending, $invoice->status);
        $this->assertIsInt($invoice->amount);
        $this->assertInstanceOf(Carbon::class, $invoice->issued_at);
    }

    #[Test]
    public function factory_builds_a_pending_euro_invoice_from_the_log_driver(): void
    {
        $invoice = Invoice::factory()->create()->fresh();

        $this->assertSame('log', $invoice->provider);
        $this->assertSame(6000, $invoice->amount);
        $this->assertSame('eur', $invoice->currency);
        $this->assertStringStartsWith('in_test_', $invoice->stripe_invoice_id);
        $this->assertNotNull($invoice->user->nif);
    }

    #[Test]
    public function issued_invoices_returns_only_the_users_own_invoices(): void
    {
        $user = User::factory()->withBilling()->create();
        $other = User::factory()->withBilling()->create();
        $mine = Invoice::factory()->count(2)->create(['user_id' => $user->id]);
        Invoice::factory()->create(['user_id' => $other->id]);

        $this->assertEqualsCanonicalizing($mine->pluck('id')->all(), $user->issuedInvoices->pluck('id')->all());
    }
}
