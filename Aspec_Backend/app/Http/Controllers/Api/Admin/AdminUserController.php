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
    //
}
