<?php

namespace App\Http\Controllers\Api\Payments;

use App\Exceptions\ActivationLinkNoLongerValidException;
use App\Exceptions\SubscriptionInProgressException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartActivationRequest;
use App\Http\Resources\AccountActivationResource;
use App\Models\User;
use App\Services\Payments\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ativação e reativação da conta pelo link do email, sem login (rotas com signed:relative).
 * O utilizador vem como string e é procurado aqui, sem route model binding: assim a assinatura
 * é sempre validada primeiro e um link forjado dá 403, nunca 404.
 */
class AccountActivationController extends Controller
{
    /**
     * Valida o link e devolve o que a página de ativação mostra.
     *
     * @return JsonResponse 200, 404 (conta já não existe) ou 409 (conta que não pode pagar).
     */
    public function show(string $user): JsonResponse
    {
        $user = User::with(['accountStatus', 'memberProfile'])->findOrFail($user);

        if ($conflict = $this->ineligibleResponse($user)) {
            return $conflict;
        }

        return $this->successResponse(new AccountActivationResource($user), 'Link válido.', Response::HTTP_OK);
    }

    /**
     * Grava a faturação (só na ativação) e devolve o URL do Stripe Checkout. A conta não muda
     * aqui: só o webhook do Stripe a ativa, porque o regresso do browser pode ser forjado.
     *
     * @return JsonResponse 200 com checkout_url, 404, 409 ou 422 (só na ativação).
     */
    public function store(StartActivationRequest $request, string $user, SubscriptionService $subscriptions): JsonResponse
    {
        $user = User::with('accountStatus')->findOrFail($user);

        if ($conflict = $this->ineligibleResponse($user)) {
            return $conflict;
        }

        $cancelUrl = rtrim(config('app.frontend_url'), '/')."/ativacao/{$user->id}?"
            .http_build_query($request->only('expires', 'signature'));

        try {
            $checkoutUrl = $subscriptions->startCheckout($user, $request->validated(), $cancelUrl);
        } catch (ActivationLinkNoLongerValidException|SubscriptionInProgressException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_CONFLICT);
        }

        return $this->successResponse(
            ['checkout_url' => $checkoutUrl],
            'A redirecionar para o pagamento.',
            Response::HTTP_OK
        );
    }

    /**
     * 409 para contas que não podem pagar pelo link (só Approved e Inactive por falta de pagamento podem).
     */
    private function ineligibleResponse(User $user): ?JsonResponse
    {
        if ($user->activationType() !== null) {
            return null;
        }

        $message = $user->accountStatus?->name === 'Active'
            ? 'A conta já está ativa.'
            : 'Este link já não é válido para esta conta.';

        return $this->errorResponse($message, Response::HTTP_CONFLICT);
    }
}
