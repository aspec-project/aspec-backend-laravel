<?php

namespace App\Http\Controllers\Api\Payments;

use App\Exceptions\ActivationLinkNoLongerValidException;
use App\Exceptions\SubscriptionInProgressException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResendActivationLinkRequest;
use App\Http\Requests\StartActivationRequest;
use App\Http\Resources\AccountActivationResource;
use App\Jobs\ResendActivationLinkJob;
use App\Models\User;
use App\Services\Payments\SubscriptionService;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ativação e reativação da conta pelo link do email, sem login (rotas com signed:relative).
 * O utilizador vem como string e é procurado aqui, sem route model binding: assim a assinatura
 * é sempre validada primeiro e um link forjado dá 403, nunca 404. O pedido de um novo link
 * (resend) é público e sem assinatura.
 */
class AccountActivationController extends Controller
{
    private const DAILY_RESEND_LIMIT = 5;

    private const DAILY_RESEND_DECAY_SECONDS = 86400;

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
     * Pede um novo link de ativação/reativação. Responde sempre 200 com a mesma mensagem, exista
     * a conta ou não, para não revelar quem é membro; a procura e o envio correm depois da resposta.
     * No máximo 5 pedidos por email por dia (qualquer IP), contados depois dos limites por IP;
     * 429 sem Retry-After para não revelar quando outros pediram.
     *
     * @throws ThrottleRequestsException Se o limite diário do email estiver esgotado.
     */
    public function resend(ResendActivationLinkRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $key = 'activation-resend-email:'.Str::lower(trim($email));

        if (RateLimiter::tooManyAttempts($key, self::DAILY_RESEND_LIMIT)) {
            throw new ThrottleRequestsException;
        }

        RateLimiter::hit($key, self::DAILY_RESEND_DECAY_SECONDS);

        ResendActivationLinkJob::dispatchAfterResponse($email);

        return $this->successResponse(
            null,
            'Se a conta existir e aguardar ativação, enviámos um novo link.',
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
