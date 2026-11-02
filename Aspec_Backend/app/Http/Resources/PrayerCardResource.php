<?php

namespace App\Http\Resources;

use App\Models\EventInstance;
use App\Models\PrayerCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Cartão de oração do próprio membro num evento (o recurso é o evento): versículo, data, prazo,
 * estado da distribuição e o cartão enviado. Nunca indica quem recebeu o cartão.
 *
 * @mixin EventInstance
 */
class PrayerCardResource extends JsonResource
{
    /**
     * Recebe o evento e o cartão do membro (null se ainda não enviou).
     */
    public function __construct(EventInstance $event, private readonly ?PrayerCard $card)
    {
        parent::__construct($event);
    }

    /**
     * distribution_status: pending antes da distribuição; depois, distributed se o cartão foi entregue
     * a alguém e unpaired se não houve outros cartões. submitted_at é a hora da 1.ª entrega (editar
     * não a muda). Datas em ISO 8601 na hora de Lisboa.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'prayer_verse' => $this->prayer_verse,
            'event_date' => $this->event_date->format('Y-m-d'),
            'window' => self::window($this->resource),
            'distribution_status' => $this->distributionStatus(),
            'card' => $this->card === null ? null : [
                'personal_request' => $this->card->personal_request,
                'work_request' => $this->card->work_request,
                'salvation_request' => $this->card->salvation_request,
                'submitted_at' => self::lisbon($this->card->created_at),
                'updated_at' => self::lisbon($this->card->updated_at),
            ],
        ];
    }

    /**
     * Prazo do cartão (estado, abertura e fecho), partilhado com o estado do backoffice.
     *
     * @return array{status: string, opens_at: string, closes_at: string}
     */
    public static function window(EventInstance $event): array
    {
        return [
            'status' => $event->prayerCardsWindowStatus(),
            'opens_at' => self::lisbon($event->prayerCardsWindowOpensAt()),
            'closes_at' => self::lisbon($event->prayerCardsWindowClosesAt()),
        ];
    }

    /**
     * Data e hora em ISO 8601 com o fuso de Lisboa (null se não existir).
     */
    public static function lisbon(?Carbon $moment): ?string
    {
        return $moment?->copy()->setTimezone(config('app.event_timezone', 'Europe/Lisbon'))->toIso8601String();
    }

    private function distributionStatus(): string
    {
        if ($this->prayer_cards_distributed_at === null) {
            return 'pending';
        }

        return $this->card !== null && $this->card->recipient_user_id === null ? 'unpaired' : 'distributed';
    }
}
