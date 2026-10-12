<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fatura de um pagamento recebido pelo Stripe. Guardada por obrigação fiscal: nunca é apagada,
 * nem quando a conta do membro é anonimizada.
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'stripe_invoice_id',
        'provider',
        'provider_invoice_id',
        'number',
        'amount',
        'currency',
        'status',
        'pdf_url',
        'issued_at',
    ];

    /**
     * Rede de segurança: o pdf_url é um link público ao documento fiscal e o id no serviço de
     * faturação é interno; um toArray() ou log esquecido não os expõe.
     */
    protected $hidden = [
        'pdf_url',
        'provider_invoice_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => InvoiceStatus::class,
            'issued_at' => 'datetime',
        ];
    }

    /**
     * Membro a quem a fatura é emitida. Inclui contas apagadas (soft delete): quem pagou é faturado.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
