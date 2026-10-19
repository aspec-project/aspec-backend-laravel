<?php

namespace App\Http\Controllers\Api\Payments;

use App\Exceptions\InvoicePdfUnavailable;
use App\Http\Controllers\Controller;
use App\Services\Invoicing\InvoiceServiceFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class InvoicePdfController extends Controller
{
    /**
     * Descarrega o PDF de uma fatura do membro autenticado, pedido ao driver que a emitiu.
     *
     * Fatura inexistente ou de outro membro → 404 igual (não confirma que o id existe). Ainda não
     * emitida → 409; driver sem PDF ou ficheiro em falta → 404; serviço de faturação em falha → 502.
     * O PDF passa pela API em vez de redirecionar para o link do fornecedor: assim o dono é
     * verificado em cada download e o NIF não fica em caches (no-store).
     */
    public function show(Request $request, InvoiceServiceFactory $drivers, string $invoice): Response
    {
        $invoice = $request->user()->issuedInvoices()->whereKey($invoice)->first();

        if (! $invoice) {
            return $this->errorResponse('Fatura não encontrada.', Response::HTTP_NOT_FOUND);
        }

        if (! $invoice->status->isIssued()) {
            return $this->errorResponse('A fatura ainda está a ser emitida. Tente novamente mais tarde.', Response::HTTP_CONFLICT);
        }

        try {
            $pdf = $drivers->make($invoice->provider)->pdf((string) $invoice->provider_invoice_id);
        } catch (InvoicePdfUnavailable $e) {
            Log::warning('Não foi possível obter o PDF da fatura no serviço de faturação.', [
                'invoice_id' => $invoice->id,
                'step' => $e->step,
                'http_status' => $e->httpStatus,
            ]);

            return $this->errorResponse('Não foi possível obter o PDF da fatura. Tente novamente dentro de instantes.', Response::HTTP_BAD_GATEWAY);
        }

        if ($pdf === null) {
            return $this->errorResponse('O PDF desta fatura não está disponível.', Response::HTTP_NOT_FOUND);
        }

        $filename = 'fatura-'.preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $invoice->number).'.pdf';

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
