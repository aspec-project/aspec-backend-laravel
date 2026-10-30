<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrayerCardResource;
use App\Models\EventInstance;
use App\Models\PrayerCard;
use App\Services\PrayerCards\PrayerCardDistributor;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AdminPrayerCardController extends Controller
{
    private const NO_PRAYER_CARDS_MESSAGE = 'Este evento não tem cartões de oração.';

    private const ALREADY_DISTRIBUTED_MESSAGE = 'Os cartões deste evento já foram distribuídos.';

    /**
     * Estado dos cartões de oração de um evento no backoffice: contagens e quem entregou (presença).
     * Nunca devolve o conteúdo dos cartões nem quem recebeu o cartão de quem (RGPD art. 9.º).
     */
    public function show(EventInstance $eventInstance): JsonResponse
    {
        if (! $eventInstance->prayer_cards_enabled) {
            return $this->errorResponse(self::NO_PRAYER_CARDS_MESSAGE, Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(
            $this->status($eventInstance),
            'Estado dos cartões de oração obtido com sucesso.'
        )->header('Cache-Control', 'private, no-store');
    }

    /**
     * Botão "Distribuir agora": distribui os cartões antes do fim do prazo, a partir do início do
     * evento (antes → 409) e só uma vez (segundo pedido → 409, nunca redistribui). Depois disto o
     * prazo fecha para todos. Sem cartões, o evento fica apenas marcado.
     */
    public function distribute(EventInstance $eventInstance, PrayerCardDistributor $distributor): JsonResponse
    {
        if (! $eventInstance->prayer_cards_enabled) {
            return $this->errorResponse(self::NO_PRAYER_CARDS_MESSAGE, Response::HTTP_NOT_FOUND);
        }

        if ($eventInstance->prayer_cards_distributed_at !== null) {
            return $this->errorResponse(self::ALREADY_DISTRIBUTED_MESSAGE, Response::HTTP_CONFLICT);
        }

        if (now()->lessThan($eventInstance->prayerCardsWindowOpensAt())) {
            return $this->errorResponse(
                'Ainda não é possível distribuir: o evento ainda não começou.',
                Response::HTTP_CONFLICT
            );
        }

        if ($distributor->distribute($eventInstance)->alreadyDistributed) {
            return $this->errorResponse(self::ALREADY_DISTRIBUTED_MESSAGE, Response::HTTP_CONFLICT);
        }

        return $this->successResponse(
            $this->status($eventInstance->fresh()),
            'Cartões de oração distribuídos.'
        );
    }

    /**
     * Opções, prazo, contagens (inscritos, cartões, entregues, sem par) e participantes que entregaram
     * o cartão (nome, empresa, hora), por hora de entrega. Os cartões retirados não contam; sem par só
     * existe depois da distribuição.
     *
     * @return array<string, mixed>
     */
    private function status(EventInstance $event): array
    {
        $cards = $event->prayerCards()
            ->with('author.memberProfile')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $delivered = $cards->whereNotNull('recipient_user_id')->count();
        $distributed = $event->prayer_cards_distributed_at !== null;

        return [
            'enabled' => (bool) $event->prayer_cards_enabled,
            'prayer_verse' => $event->prayer_verse,
            'window' => PrayerCardResource::window($event),
            'registrations_count' => $event->registrations()->count(),
            'cards_count' => $cards->count(),
            'distributed_count' => $delivered,
            'unpaired_count' => $distributed ? $cards->count() - $delivered : 0,
            'distributed_at' => PrayerCardResource::lisbon($event->prayer_cards_distributed_at),
            'participants' => $cards->map(fn (PrayerCard $card) => [
                'name' => $card->author?->memberProfile?->name,
                'business_name' => $card->author?->memberProfile?->business_name,
                'submitted_at' => PrayerCardResource::lisbon($card->created_at),
            ])->values()->all(),
        ];
    }
}
