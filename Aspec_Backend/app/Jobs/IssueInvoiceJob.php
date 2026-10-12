<?php

namespace App\Jobs;

use App\Contracts\InvoiceService;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceIssuingFailed;
use App\Models\Invoice;
use App\Services\Invoicing\Data\InvoiceCustomerData;
use App\Services\Invoicing\Data\InvoiceData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class IssueInvoiceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Guarda só o id da linha `invoices`: o payload fica nas tabelas jobs/failed_jobs, sem
     * dados pessoais nem valores.
     */
    public function __construct(public string $invoiceId)
    {
    }

    /**
     * Esperas crescentes entre tentativas (segundos): 1 min, 5 min, 15 min, 1 h.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    /**
     * Emite a fatura pendente e grava o resultado (número, estado, data de emissão).
     * Idempotente: uma fatura inexistente ou que já não está pendente não é emitida outra vez.
     * Sem dados de faturação completos (conta sem ativação ou anonimizada) marca a fatura como failed
     * sem repetir: repetir não traz os dados de volta, um admin emite à mão.
     *
     * @throws InvoiceIssuingFailed Propaga para a fila repetir; o id do rascunho já criado fica
     *                              gravado para a próxima tentativa o retomar.
     */
    public function handle(InvoiceService $invoices): void
    {
        $invoice = Invoice::with('user')->find($this->invoiceId);

        if (! $invoice || $invoice->status !== InvoiceStatus::Pending) {
            return;
        }

        $customer = InvoiceCustomerData::fromUser($invoice->user);

        if (! $customer) {
            $invoice->forceFill(['status' => InvoiceStatus::Failed])->save();

            Log::warning('Dados de faturação em falta; fatura não emitida, emitir manualmente.', [
                'invoice_id' => $invoice->id,
                'stripe_invoice_id' => $invoice->stripe_invoice_id,
            ]);

            return;
        }

        try {
            $issued = $invoices->issue($customer, $this->invoiceData($invoice), $invoice->provider_invoice_id);
        } catch (InvoiceIssuingFailed $e) {
            if ($e->providerInvoiceId) {
                $invoice->forceFill(['provider_invoice_id' => $e->providerInvoiceId])->save();
            }

            throw $e;
        }

        $invoice->forceFill([
            'provider' => $issued->provider,
            'provider_invoice_id' => $issued->providerInvoiceId,
            'number' => $issued->number,
            'pdf_url' => $issued->pdfUrl,
            'status' => InvoiceStatus::from($issued->status),
            'issued_at' => now(),
        ])->save();
    }

    /**
     * Esgotadas as tentativas, marca a fatura como failed e regista o erro (sem NIF nem email)
     * para um admin a emitir à mão. Uma fatura já emitida não é alterada.
     */
    public function failed(Throwable $e): void
    {
        Invoice::whereKey($this->invoiceId)
            ->where('status', InvoiceStatus::Pending)
            ->update(['status' => InvoiceStatus::Failed]);

        Log::error('Não foi possível emitir a fatura; emitir manualmente.', [
            'invoice_id' => $this->invoiceId,
            'exception' => $e::class,
            'step' => $e instanceof InvoiceIssuingFailed ? $e->step : null,
        ]);
    }

    /**
     * Pagamento a faturar. A data é a de emissão e não a do pagamento: as séries fiscais exigem
     * datas não decrescentes, e uma nova tentativa dias depois com a data antiga podia ser recusada.
     * O mês descrito é o do pagamento (a linha é criada pelo webhook segundos depois de pago).
     */
    private function invoiceData(Invoice $invoice): InvoiceData
    {
        $month = $invoice->created_at->locale('pt_PT')->translatedFormat('F Y');

        return new InvoiceData(
            stripeInvoiceId: $invoice->stripe_invoice_id,
            amount: $invoice->amount,
            currency: $invoice->currency,
            description: config('subscription.invoice_description').' — '.$month,
            date: CarbonImmutable::now(),
        );
    }
}
