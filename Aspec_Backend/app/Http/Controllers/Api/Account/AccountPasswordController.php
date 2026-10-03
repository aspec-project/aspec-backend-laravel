<?php

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AccountPasswordController extends Controller
{
    /**
     * Altera a password do utilizador autenticado (membro ou admin, com ou sem perfil)
     * e revoga todos os seus tokens, incluindo o atual: tem de iniciar sessão novamente.
     * Envia um email de aviso ao próprio utilizador; o limite de falhas está no Form Request.
     */
    public function update(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        // Numa transação para que nunca fique a password nova com os tokens antigos ainda válidos.
        DB::transaction(function () use ($request, $user) {
            $user->update(['password' => $request->validated('password')]);
            $user->tokens()->delete();
        });

        // Só depois do commit: nunca avisar de uma alteração que foi revertida.
        // Uma falha no email não pode virar 500: a password já mudou e os tokens já foram revogados.
        try {
            $user->notify(new PasswordChangedNotification);
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->successResponse(
            null,
            'Password alterada com sucesso. Inicie sessão novamente.',
            Response::HTTP_OK
        );
    }
}
