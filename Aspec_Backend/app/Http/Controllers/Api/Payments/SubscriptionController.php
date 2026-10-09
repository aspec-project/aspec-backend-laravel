<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionController extends Controller
{
    /**
     * Devolve a subscrição do membro autenticado e os seus dados de faturação. Lê só a base de
     * dados (mantida pelos webhooks), sem chamar o Stripe; 404 se não houver subscrição (ex. admin).
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = $user->currentSubscription();

        if (! $subscription) {
            return $this->errorResponse('Não existe subscrição.', Response::HTTP_NOT_FOUND);
        }

        $subscription->setRelation('owner', $user);

        return $this->successResponse(
            new SubscriptionResource($subscription),
            'Subscrição obtida com sucesso.',
            Response::HTTP_OK
        );
    }
}
