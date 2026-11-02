<?php

namespace App\Jobs;

use App\Contracts\InvoiceService;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvalidInvoiceTax;
use App\Exceptions\InvoiceIssuingFailed;
use App\Models\Invoice;
use App\Services\Invoicing\Data\InvoiceCustomerData;
use App\Services\Invoicing\Data\InvoiceData;
use App\Services\Invoicing\Data\InvoiceTax;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Support\Facades\Log;
use Throwable;

class IssueInvoiceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Tempo máximo de uma tentativa, em segundos. Acima do pior caso dos pedidos à InvoiceExpress
     * (4 pedidos: criar ou ler o rascunho, finalizar, confirmar o estado e email; cada um até 3 tentativas
     * de 10 s com 0,5 s de espera = 31 s, ou seja 124 s) e abaixo do retry_after da fila (180 s): se o
     * retry_after fosse menor, outro worker apanhava o job ainda a correr e podia emitir em paralelo.
     */
    public int $timeout = 150;

    /**
     * Guarda só o id da linha `invoices`: o payload fica nas tabelas jobs/failed_jobs, sem
     * dados pessoais nem valores.
     */
    public function __construct(public string $invoiceId) {}

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
     * Erros permanentes do imposto falham o job logo, sem as 5 tentativas: configuração do IVA inválida
     * e fatura isenta sem código de isenção na InvoiceExpress. Repetir dava o mesmo erro e atrasava o
     * aviso; o failed() marca a fatura como failed e regista o erro. Os outros erros (incluindo a conta
     * InvoiceExpress por configurar) repetem com as esperas do backoff().
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new FailOnException(fn (Throwable $e) => $e instanceof InvalidInvoiceTax
                || ($e instanceof InvoiceIssuingFailed && $e->step === 'tax')),
        ];
    }

    /**
     * Emite a fatura pendente e grava o resultado (número, estado, data de emissão).
     * Idempotente: uma fatura inexistente ou que já não está pendente não é emitida outra vez.
     * Sem dados de faturação completos (conta sem ativação ou anonimizada) marca a fatura como failed
     * sem repetir: repetir não traz os dados de volta, um admin emite à mão.
     * Grava o imposto usado nesta emissão (a configuração pode mudar depois; a fatura não).
     *
     * O id do rascunho é gravado logo que a InvoiceExpress o cria, antes de finalizar: se o worker
     * morrer depois de finalizar e antes de gravar o resultado, a nova tentativa retoma esse documento
     * (já finalizado) em vez de emitir uma segunda fatura fiscal.
     *
     * @throws InvalidInvoiceTax Se a configuração do IVA for inválida (o job falha sem repetir).
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

        $data = $this->invoiceData($invoice);

        try {
            $issued = $invoices->issue(
                $customer,
                $data,
                $invoice->provider_invoice_id,
                fn (string $draftId) => $invoice->forceFill(['provider_invoice_id' => $draftId])->save(),
            );
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
            'vat_rate' => $data->tax->rate,
            'vat_exemption_code' => $data->tax->exemptionCode,
            'vat_exemption_reason' => $data->tax->exemptionReason,
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
     * A descrição diz o período pago, do Stripe, em datas de Lisboa e com o último dia pago como fim
     * (ex. "Quota ASPEC — período de 28/10/2026 a 27/10/2027"), igual para a quota mensal e anual.
     * Faturas sem período (anteriores a esta coluna ou payload sem período) descrevem o mês do
     * pagamento (a linha é criada pelo webhook segundos depois de pago).
     * O imposto é lido da configuração em cada tentativa.
     */
    private function invoiceData(Invoice $invoice): InvoiceData
    {
        $period = $invoice->paidPeriodDates();

        $paid = $period
            ? "período de {$period[0]->format('d/m/Y')} a {$period[1]->format('d/m/Y')}"
            : $invoice->created_at->locale('pt_PT')->translatedFormat('F Y');

        return new InvoiceData(
            stripeInvoiceId: $invoice->stripe_invoice_id,
            amount: $invoice->amount,
            currency: $invoice->currency,
            description: config('subscription.invoice_description').' — '.$paid,
            date: CarbonImmutable::now(),
            tax: InvoiceTax::fromConfig(),
        );
    }
}
