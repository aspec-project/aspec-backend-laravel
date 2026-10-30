<?php

namespace App\Http\Controllers\Api\Events;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePrayerCardRequest;
use App\Http\Resources\PrayerCardResource;
use App\Models\EventInstance;
use App\Models\PrayerCard;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PrayerCardController extends Controller
{
    private const NO_PRAYER_CARDS_MESSAGE = 'Este evento não tem cartões de oração.';

    private const NOT_REGISTERED_MESSAGE = 'Só os membros inscritos neste evento podem preencher o cartão de oração.';

    private const NO_CARD_MESSAGE = 'Não tem cartão de oração neste evento.';

    /**
     * Cartão de oração do membro autenticado no evento: versículo, prazo, estado da distribuição e o
     * cartão enviado (null se ainda não enviou). Só para membros inscritos e com perfil.
     */
    public function show(Request $request, EventInstance $eventInstance): JsonResponse
    {
        $error = $this->participationError($eventInstance, $request->user());

        if ($error !== null) {
            return $error;
        }

        return $this->successResponse(
            new PrayerCardResource($eventInstance, $this->ownCard($eventInstance, $request->user())),
            'Cartão de oração obtido com sucesso.'
        )->header('Cache-Control', 'private, no-store');
    }

    /**
     * Envia (201) ou edita (200) o cartão do membro, do início do evento às 23:59 desse dia (Lisboa) e
     * só enquanto os cartões não foram distribuídos. Um cartão por membro por evento.
     */
    public function update(UpdatePrayerCardRequest $request, EventInstance $eventInstance): JsonResponse
    {
        $user = $request->user();
        $error = $this->participationError($eventInstance, $user);

        if ($error !== null) {
            return $error;
        }

        // O lock do evento é o mesmo da distribuição: um cartão enviado no último segundo ou entra
        // na distribuição ou recebe 409, nunca fica de fora sem aviso.
        return DB::transaction(function () use ($request, $eventInstance, $user): JsonResponse {
            $event = EventInstance::query()->whereKey($eventInstance->getKey())->lockForUpdate()->firstOrFail();

            if (! $event->prayer_cards_enabled) {
                return $this->errorResponse(self::NO_PRAYER_CARDS_MESSAGE, Response::HTTP_NOT_FOUND);
            }

            if (! $this->stillParticipates($event, $user)) {
                return $this->errorResponse(self::NOT_REGISTERED_MESSAGE, Response::HTTP_FORBIDDEN);
            }

            $windowStatus = $event->prayerCardsWindowStatus();

            if ($windowStatus === 'not_open') {
                return $this->errorResponse('O preenchimento do cartão abre no início do evento.', Response::HTTP_CONFLICT);
            }

            if ($windowStatus === 'closed') {
                return $this->errorResponse('O prazo para preencher o cartão terminou.', Response::HTTP_CONFLICT);
            }

            $card = $this->ownCard($event, $user);
            $created = $card === null;

            $card ??= new PrayerCard([
                'event_instance_id' => $event->id,
                'author_user_id' => $user->id,
            ]);

            $card->fill([...$request->requests(), 'consent_given_at' => now()])->save();

            return $this->successResponse(
                new PrayerCardResource($event, $card),
                $created ? 'Cartão de oração enviado com sucesso.' : 'Cartão de oração atualizado com sucesso.',
                $created ? Response::HTTP_CREATED : Response::HTTP_OK
            );
        });
    }

    /**
     * Retira o cartão do membro em qualquer momento (RGPD art. 7.º, n.º 3). Antes da distribuição a
     * linha é apagada (deixa de contar como presença); depois, o conteúdo é apagado com soft delete
     * e o destinatário deixa de o ver.
     */
    public function destroy(Request $request, EventInstance $eventInstance): JsonResponse
    {
        return DB::transaction(function () use ($request, $eventInstance): JsonResponse {
            $event = EventInstance::query()->whereKey($eventInstance->getKey())->lockForUpdate()->firstOrFail();
            $card = $this->ownCard($event, $request->user());

            if ($card === null) {
                return $this->errorResponse(self::NO_CARD_MESSAGE, Response::HTTP_NOT_FOUND);
            }

            if ($event->prayer_cards_distributed_at === null) {
                $card->forceDelete();
            } else {
                $card->erase();
            }

            return $this->successResponse(null, 'Cartão de oração retirado.');
        });
    }

    /**
     * 404 se o evento não tem cartões; 403 se o membro não está inscrito ou não tem perfil.
     */
    private function participationError(EventInstance $event, User $user): ?JsonResponse
    {
        if (! $event->prayer_cards_enabled) {
            return $this->errorResponse(self::NO_PRAYER_CARDS_MESSAGE, Response::HTTP_NOT_FOUND);
        }

        $registered = $event->registrations()->where('user_id', $user->id)->exists();

        if (! $registered || ! $user->memberProfile()->exists()) {
            return $this->errorResponse(self::NOT_REGISTERED_MESSAGE, Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * Repete a verificação do autor dentro da transação, com a conta bloqueada: uma anonimização ou
     * um bloqueio confirmados depois da verificação inicial impedem que o cartão seja gravado.
     */
    private function stillParticipates(EventInstance $event, User $user): bool
    {
        return User::query()->activeWithProfile()->whereKey($user->id)->lockForUpdate()->exists()
            && $event->registrations()->where('user_id', $user->id)->exists();
    }

    /**
     * Cartão do membro neste evento, sem os retirados.
     */
    private function ownCard(EventInstance $event, User $user): ?PrayerCard
    {
        return $event->prayerCards()->where('author_user_id', $user->id)->first();
    }
}
