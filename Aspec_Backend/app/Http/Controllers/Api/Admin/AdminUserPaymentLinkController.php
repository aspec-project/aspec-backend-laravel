<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ActivationType;
use App\Enums\InactiveReason;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Payments\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reenvio, pelo backoffice, do email de que a conta precisa para (voltar a) pagar.
 */
class AdminUserPaymentLinkController extends Controller
{
    /**
     * Envia o email que o estado da conta pede: Approved → link de ativação; Inactive por falta de
     * pagamento ou cancelamento → link de reativação; Active em carência → lembrete de pagamento.
     * Usa a mesma regra do reenvio público (activationType()), mas não os limites dele, que um
     * atacante consegue esgotar; tem limites próprios por admin e por membro alvo. Não altera a conta.
     *
     * @param  User  $user  Conta alvo (contas anonimizadas não entram no binding: 404).
     * @return JsonResponse 200 com o id e o tipo de email, ou 409 se o estado não pede nenhum email de pagamento.
     */
    public function store(Request $request, User $user, SubscriptionService $subscriptions): JsonResponse
    {
        $user->loadMissing('accountStatus');

        $activationType = $user->activationType();

        $type = match (true) {
            $activationType === ActivationType::Activation => 'activation',
            $activationType === ActivationType::Reactivation => 'reactivation',
            $user->accountStatus?->name === 'Active' && $user->grace_ends_at !== null => 'payment_reminder',
            default => null,
        };

        if ($type === null) {
            return $this->errorResponse($this->refusalMessage($user), Response::HTTP_CONFLICT);
        }

        match ($type) {
            'activation' => $subscriptions->sendActivationLink($user),
            'reactivation' => $subscriptions->sendReactivationLink($user),
            'payment_reminder' => $subscriptions->sendPaymentReminder($user),
        };

        Log::info('Email de pagamento reenviado pelo backoffice.', [
            'user_id' => $user->id,
            'admin_id' => $request->user()->id,
            'type' => $type,
        ]);

        $messages = [
            'activation' => 'Foi enviado o email de ativação.',
            'reactivation' => 'Foi enviado o email de reativação.',
            'payment_reminder' => 'Foi enviado o lembrete de pagamento.',
        ];

        return $this->successResponse(['id' => $user->id, 'type' => $type], $messages[$type], Response::HTTP_OK);
    }

    /**
     * Mensagem do 409, para o admin saber o que fazer. O próprio admin cai em "já está ativa",
     * porque o middleware account.active garante que quem chama está Active.
     */
    private function refusalMessage(User $user): string
    {
        return match (true) {
            $user->accountStatus?->name === 'Active' => 'A conta já está ativa.',
            $user->accountStatus?->name === 'Pending' => 'A candidatura ainda não foi aprovada.',
            $user->inactive_reason === InactiveReason::Blocked => 'A conta está bloqueada. Use a opção Desbloquear.',
            default => 'Esta conta não pode ser reativada.',
        };
    }
}
