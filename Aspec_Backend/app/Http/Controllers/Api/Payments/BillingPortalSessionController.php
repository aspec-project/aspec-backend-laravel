<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Services\Payments\StripeSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BillingPortalSessionController extends Controller
{
    /**
     * Cria uma sessão do portal de faturação do Stripe para o membro autenticado atualizar o
     * cartão (o cartão nunca passa pelo nosso servidor) e devolve o URL, de uso imediato.
     * 404 sem cliente Stripe ou sem subscrição (ex. admin); falhas do Stripe dão 502;
     * 500 se o portal não estiver configurado (fora de local/testing).
     */
    public function store(Request $request, StripeSubscriptionService $stripe): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasStripeId() || ! $user->currentSubscription()) {
            return $this->errorResponse('Não existe subscrição.', Response::HTTP_NOT_FOUND);
        }

        $url = $stripe->billingPortalUrl($user, rtrim(config('app.frontend_url'), '/').'/conta/subscricao');

        return $this->successResponse(
            ['url' => $url],
            'A redirecionar para o portal de pagamentos.',
            Response::HTTP_OK
        );
    }
}
