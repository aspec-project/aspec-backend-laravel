<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\InactiveReason;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccountAnonymizationResource;
use App\Models\AccountAnonymization;
use App\Models\User;
use App\Notifications\AccountAnonymizedNotification;
use App\Services\Payments\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anonimização de contas pelo backoffice, a pedido do titular (RGPD art. 17).
 */
class AdminUserAnonymizationController extends Controller
{
    /**
     * Anonimiza a conta e deixa prova de quem o fez e quando. Recusa contas de administrador (incluindo
     * a própria): para anonimizar um admin, despromover primeiro. Primeiro desativa a conta (motivo
     * Deleted): o webhook do fim da subscrição só mexe em contas Active, por isso não manda um link de
     * reativação a quem pediu para ser apagado, e a ativação deixa de aceitar um Checkout novo. Depois
     * cancela a subscrição, para expirar um Checkout aberto enquanto as subscrições locais ainda não
     * estão canceladas. Não corre numa transação, porque fala com o Stripe. Se a anonimização falhar a
     * meio, o admin pode repetir o pedido (a conta só sai do binding quando é apagada). Uma falha no
     * email de confirmação não muda a resposta.
     *
     * @param  User  $user  Conta a anonimizar (contas já apagadas não entram no binding: 404).
     * @return JsonResponse 200 com o id e a data, 404 se já foi anonimizada entretanto ou 409 se for administrador.
     */
    public function store(Request $request, User $user, SubscriptionService $subscriptions): JsonResponse
    {
        if ($user->role?->name === 'Admin') {
            return $this->errorResponse(
                'Não é possível anonimizar um administrador.',
                Response::HTTP_CONFLICT
            );
        }

        $originalEmail = $user->email;

        $user->deactivate(InactiveReason::Deleted->value);

        $subscriptions->cancel($user);

        if (! $user->anonymizeAndDelete($request->user())) {
            return $this->errorResponse('Recurso não encontrado.', Response::HTTP_NOT_FOUND);
        }

        rescue(
            fn () => Notification::route('mail', $originalEmail)->notify(new AccountAnonymizedNotification),
            fn () => Log::warning('Falha ao enviar o email de confirmação da anonimização.', ['user_id' => $user->id]),
            report: false
        );

        return $this->successResponse(
            new AccountAnonymizationResource(AccountAnonymization::where('user_id', $user->id)->firstOrFail()),
            'Membro anonimizado com sucesso.',
            Response::HTTP_OK
        );
    }
}
