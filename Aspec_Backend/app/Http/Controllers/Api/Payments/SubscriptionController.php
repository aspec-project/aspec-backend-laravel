<?php

namespace App\Http\Controllers\Api\Payments;

use App\Exceptions\SubscriptionNotFoundException;
use App\Exceptions\SubscriptionStateConflictException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Services\Payments\SubscriptionService;
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

    /**
     * Agenda o cancelamento da subscrição do membro autenticado para o fim do período pago; o
     * acesso mantém-se até lá. 404 sem subscrição, 409 se o estado não o permite, 502 se o Stripe falhar.
     */
    public function cancel(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        try {
            $subscription = $subscriptions->cancelAtPeriodEnd($request->user());
        } catch (SubscriptionNotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (SubscriptionStateConflictException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_CONFLICT);
        }

        $subscription->setRelation('owner', $request->user());

        return $this->successResponse(
            new SubscriptionResource($subscription),
            "Cancelamento agendado. Mantém o acesso até {$subscription->ends_at->format('d/m/Y')}.",
            Response::HTTP_OK
        );
    }

    /**
     * Retoma a subscrição com cancelamento agendado do membro autenticado. 404 sem subscrição,
     * 409 sem cancelamento agendado, 502 se o Stripe falhar.
     */
    public function resume(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        try {
            $subscription = $subscriptions->resume($request->user());
        } catch (SubscriptionNotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (SubscriptionStateConflictException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_CONFLICT);
        }

        $subscription->setRelation('owner', $request->user());

        return $this->successResponse(
            new SubscriptionResource($subscription),
            'A subscrição foi retomada.',
            Response::HTTP_OK
        );
    }
}
