<?php

namespace App\Http\Resources;

use App\Models\PrayerCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cartão de oração recebido, mostrado só ao destinatário: nome, email e telefone pessoais do autor
 * (lidos da conta no momento do pedido), evento, os 3 pedidos e o versículo.
 *
 * @mixin PrayerCard
 */
class ReceivedPrayerCardResource extends JsonResource
{
    /**
     * pray_until = receção + semana de oração; visible_until = último dia visível (data do evento +
     * meses de retenção), depois o comando de expiração apaga o conteúdo.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $event = $this->eventInstance;

        return [
            'id' => $this->id,
            'author' => [
                'name' => $this->author?->memberProfile?->name,
                'email' => $this->author?->email,
                'phone' => $this->author?->phone,
            ],
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'event_date' => $event->event_date->format('Y-m-d'),
            ],
            'personal_request' => $this->personal_request,
            'work_request' => $this->work_request,
            'salvation_request' => $this->salvation_request,
            'prayer_verse' => $event->prayer_verse,
            'received_at' => PrayerCardResource::lisbon($this->received_at),
            'pray_until' => PrayerCardResource::lisbon($this->received_at?->copy()->addDays((int) config('prayer_cards.prayer_days'))),
            'visible_until' => $event->event_date->copy()
                ->addMonthsNoOverflow((int) config('prayer_cards.retention_months'))
                ->format('Y-m-d'),
        ];
    }
}
