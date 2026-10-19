<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fatura mostrada só ao próprio membro. Nunca expõe o id do Stripe, o driver, o id/caminho do
 * documento no serviço de faturação, o link público do fornecedor nem o utilizador.
 */
class InvoiceResource extends JsonResource
{
    /**
     * Valor em euros (a coluna guarda cêntimos). Enquanto a fatura não está emitida não mostra número
     * nem data de emissão. paid_at é a criação da linha, que nasce no webhook do pagamento.
     * pdf_available diz ao frontend se mostra o botão de download (o driver log não gera PDF).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $issued = $this->status->isIssued();

        return [
            'id' => $this->id,
            'number' => $issued ? $this->number : null,
            'amount' => $this->amount / 100,
            'currency' => $this->currency,
            'status' => $this->status->publicStatus(),
            'paid_at' => $this->created_at?->toIso8601String(),
            'issued_at' => $issued ? $this->issued_at?->toIso8601String() : null,
            'pdf_available' => $issued && $this->provider !== 'log' && filled($this->provider_invoice_id),
        ];
    }
}
