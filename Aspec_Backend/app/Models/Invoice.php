<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Carbon\CarbonInterface;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fatura de um pagamento recebido pelo Stripe. Guardada por obrigação fiscal: nunca é apagada,
 * nem quando a conta do membro é anonimizada. period_start/period_end são o período pago, do
 * Stripe (fim exclusivo); null em faturas antigas. vat_rate, vat_exemption_code e vat_exemption_reason
 * são o imposto usado na emissão; null antes de emitida e nas faturas anteriores a estas colunas.
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUuids;

    /** Fuso das datas do período mostradas ao membro e na fatura (Portugal continental). */
    public const TIMEZONE = 'Europe/Lisbon';

    protected $fillable = [
        'stripe_invoice_id',
        'provider',
        'provider_invoice_id',
        'number',
        'amount',
        'currency',
        'vat_rate',
        'vat_exemption_code',
        'vat_exemption_reason',
        'period_start',
        'period_end',
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
            'vat_rate' => 'integer',
            'status' => InvoiceStatus::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    /**
     * Período pago em datas de Lisboa, com o último dia pago como fim: o fim do Stripe é exclusivo
     * (é o início do período seguinte), por isso recua um dia. Null em faturas sem período.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}|null
     */
    public function paidPeriodDates(): ?array
    {
        if ($this->period_start === null || $this->period_end === null) {
            return null;
        }

        return [
            $this->period_start->copy()->timezone(self::TIMEZONE)->startOfDay(),
            $this->period_end->copy()->timezone(self::TIMEZONE)->subDay()->startOfDay(),
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
