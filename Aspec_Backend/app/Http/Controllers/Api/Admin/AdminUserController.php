<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\AccountStatus;
use App\Models\User;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AdminUserController extends Controller
{

    /**
     * Approve a user by setting their account status to "Active".
     *
     * @param string $id The ID of the user to approve.
     * @return JsonResponse A JSON response containing the approved user's data and a success message.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $user = User::with(['role', 'accountStatus'])
            ->findOrFail($id);

        if ($user->id === $request->user()->id) {
            return $this->errorResponse(
                'Não pode alterar o estado da própria conta.',
                Response::HTTP_CONFLICT
            );
        }

        if ($user->role?->name === 'Admin') {
            return $this->errorResponse(
                'Não pode alterar o estado de um administrador.',
                Response::HTTP_CONFLICT
            );
        }

        if ($user->accountStatus?->name !== 'Pending') {
            return $this->errorResponse(
                'Apenas utilizadores pendentes podem ser aprovados.',
                Response::HTTP_CONFLICT
            );
        }

        $activeStatus = AccountStatus::where('name', 'Active')
            ->firstOrFail();

        $user->update([
            'account_status_id' => $activeStatus->id,
        ]);

        return $this->successResponse(
            new UserResource(
                $user->fresh()->load([
                    'role',
                    'accountStatus',
                    'memberProfile',
                ])
            ),
            'Utilizador aprovado com sucesso.',
            Response::HTTP_OK
        );
    }


    /**
     * Reject a user by setting their account status to "Inactive".
     *
     * @param string $id The ID of the user to reject.
     * @return JsonResponse A JSON response containing the rejected user's data and a success message.
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $user = User::with(['role', 'accountStatus'])
            ->findOrFail($id);

        if ($user->id === $request->user()->id) {
            return $this->errorResponse(
                'Não pode alterar o estado da própria conta.',
                Response::HTTP_CONFLICT
            );
        }

        if ($user->role?->name === 'Admin') {
            return $this->errorResponse(
                'Não pode alterar o estado de um administrador.',
                Response::HTTP_CONFLICT
            );
        }

        if ($user->accountStatus?->name !== 'Pending') {
            return $this->errorResponse(
                'Apenas utilizadores pendentes podem ser rejeitados.',
                Response::HTTP_CONFLICT
            );
        }

        $user->deactivate();

        return $this->successResponse(
            new UserResource(
                $user->fresh()->load([
                    'role',
                    'accountStatus',
                    'memberProfile',
                ])
            ),
            'Utilizador rejeitado com sucesso.',
            Response::HTTP_OK
        );
    }


    /**
     * Block a user by deactivating their account.
     *
     * @param string $id The ID of the user to block.
     * @return JsonResponse A JSON response containing the blocked user's data and a success message.
     */
    public function block(string $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $user->deactivate();

        return $this->successResponse(
            new UserResource(
                $user->fresh()->load([
                    'role',
                    'accountStatus',
                    'memberProfile',
                ])
            ),
            'Utilizador bloqueado com sucesso.',
            Response::HTTP_OK
        );
    }



    /**
     * Unblock a user by activating their account.
     *
     * @param string $id The ID of the user to unblock.
     * @return JsonResponse A JSON response containing the unblocked user's data and a success message.
     */
    public function unblock(string $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $activeStatus = AccountStatus::where('name', 'Active')->firstOrFail();

        $user->update([
            'account_status_id' => $activeStatus->id,
        ]);

        return $this->successResponse(
            new UserResource(
                $user->fresh()->load([
                    'role',
                    'accountStatus',
                    'memberProfile',
                ])
            ),
            'Utilizador desbloqueado com sucesso.',
            Response::HTTP_OK
        );
    }

    
    //
}
