<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\AccountStatus;
use App\Models\User;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use App\Enums\InactiveReason;
use Illuminate\Database\Eloquent\Builder;

class AdminUserController extends Controller
{
    private function loadUserRelations(User $user): User
    {
        return $user->load([
            'role',
            'accountStatus',
            'memberProfile.user',
            'memberProfile.sector',
            'memberProfile.location',
            'memberProfile.weekDays',
            'memberProfile.socialPlatforms',
            'memberProfile.portfolios',
        ]);
    }



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

        $user = $this->loadUserRelations($user->fresh());

        return $this->successResponse(
            new UserResource($user),
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

        $user->deactivate('rejected');

        $user = $this->loadUserRelations($user->fresh());

        return $this->successResponse(
            new UserResource($user),
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
    public function block(Request $request, string $id): JsonResponse
    {
        $user = User::with([
            'role',
            'accountStatus',
        ])->findOrFail($id);

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

        if (
            $user->accountStatus?->name === 'Inactive'
            && $user->inactive_reason === InactiveReason::Blocked
        ) {
            return $this->errorResponse(
                'O utilizador já está bloqueado.',
                Response::HTTP_CONFLICT
            );
        }

        $user->deactivate(InactiveReason::Blocked->value);

        $user = $this->loadUserRelations($user->fresh());

        return $this->successResponse(
            new UserResource($user),
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
    public function unblock(Request $request, string $id): JsonResponse
    {
        $user = User::with([
            'role',
            'accountStatus',
        ])->findOrFail($id);

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

        if (
            $user->accountStatus?->name !== 'Inactive'
            || $user->inactive_reason !== InactiveReason::Blocked
        ) {
            return $this->errorResponse(
                'Apenas utilizadores bloqueados podem ser desbloqueados.',
                Response::HTTP_CONFLICT
            );
        }

        /*
        * A transição final depende do fluxo de subscrição:
        *
        * - sem subscrição: Approved + link de ativação;
        * - com subscrição: Inactive + unpaid + link de reativação.
        */
    }


    /**
     * List users with optional filtering by status and search term.
     *
     * @param Request $request The incoming HTTP request containing optional filters.
     * @return JsonResponse A JSON response containing the list of users and pagination details.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => [
                'sometimes',
                'nullable',
                'string',
                'exists:account_statuses,name',
            ],
            'search' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
            'page' => [
                'sometimes',
                'integer',
                'min:1',
            ],
        ]);

        $query = User::query()
            ->with(['role', 'accountStatus']);

        if ($request->filled('status')) {
            $status = $request->input('status');

            $query->whereHas(
                'accountStatus',
                fn (Builder $statusQuery) => $statusQuery->where('name', $status)
            );
        }

        $search = trim((string) $request->input('search', ''));

        if ($search !== '') {
            $query->where(function (Builder $userQuery) use ($search) {
                $userQuery
                    ->where('email', 'like', "%{$search}%")
                    ->orWhereHas(
                        'memberProfile',
                        function (Builder $profileQuery) use ($search) {
                            $profileQuery
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('business_name', 'like', "%{$search}%");
                        }
                    );
            });
        }

        $users = $query
            ->orderBy('email')
            ->paginate(15);

        return $this->successResponse([
            'items' => UserResource::collection(
                $users->getCollection()
            )->resolve($request),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ], 'Lista de utilizadores obtida com sucesso.');
    }

    
    //
}
