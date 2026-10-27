<?php

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Http\Resources\NotificationPreferenceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NotificationPreferenceController extends Controller
{
    /**
     * Devolve as preferências dos emails de eventos (anúncio de novos eventos e agenda semanal)
     * do utilizador autenticado.
     */
    public function show(Request $request): JsonResponse
    {
        return $this->successResponse(
            new NotificationPreferenceResource($request->user()),
            'Preferências de notificação obtidas com sucesso.',
            Response::HTTP_OK
        );
    }

    /**
     * Grava as duas preferências do utilizador autenticado (só as dele: a rota não recebe id).
     */
    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->preferences());

        return $this->successResponse(
            new NotificationPreferenceResource($user),
            'Preferências de notificação atualizadas com sucesso.',
            Response::HTTP_OK
        );
    }
}
