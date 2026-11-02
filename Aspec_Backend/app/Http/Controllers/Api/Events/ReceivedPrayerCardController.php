<?php

namespace App\Http\Controllers\Api\Events;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexReceivedPrayerCardsRequest;
use App\Http\Resources\ReceivedPrayerCardResource;
use App\Models\PrayerCard;
use Illuminate\Http\JsonResponse;

class ReceivedPrayerCardController extends Controller
{
    /**
     * "Os meus cartões de oração": cartões que o membro autenticado recebeu, sem os retirados e os
     * expirados, mais recentes primeiro (10 por página, até 50). period=current dá os que ainda estão
     * na semana de oração (pray_until ≥ agora) e period=previous o resto.
     */
    public function index(IndexReceivedPrayerCardsRequest $request): JsonResponse
    {
        $prayWeekStart = now()->subDays((int) config('prayer_cards.prayer_days'));
        $period = $request->validated('period');

        $cards = PrayerCard::query()
            ->where('recipient_user_id', $request->user()->id)
            ->when($period === 'current', fn ($query) => $query->where('received_at', '>=', $prayWeekStart))
            ->when($period === 'previous', fn ($query) => $query->where('received_at', '<', $prayWeekStart))
            ->with(['author.memberProfile', 'eventInstance'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate($request->validated('per_page') ?? 10);

        return $this->successResponse([
            'items' => ReceivedPrayerCardResource::collection($cards->getCollection())->resolve($request),
            'pagination' => [
                'current_page' => $cards->currentPage(),
                'per_page' => $cards->perPage(),
                'total' => $cards->total(),
                'last_page' => $cards->lastPage(),
            ],
        ], 'Cartões de oração obtidos com sucesso.')->header('Cache-Control', 'private, no-store');
    }
}
