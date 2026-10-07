<?php

namespace App\Http\Controllers\Api\Admin;

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
    public function approve(string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $activeStatus = AccountStatus::where('name', 'Active')->firstOrFail();
        $user->update(['account_status_id' => $activeStatus->id]);

        return $this->successResponse(
            new UserResource($user->load(['role', 'accountStatus', 'memberProfile'])),
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
    public function reject(string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $inactiveStatus = AccountStatus::where('name', 'Inactive')->firstOrFail();
        $user->update(['account_status_id' => $inactiveStatus->id]);

        return $this->successResponse(
            new UserResource(
                $user->load(['role', 'accountStatus', 'memberProfile'])),
            'Utilizador rejeitado com sucesso.',
            Response::HTTP_OK
        );
    }

    
    //
}
