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
use App\Services\Payments\SubscriptionService;
use Illuminate\Support\Facades\DB;
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
     * Aprova uma candidatura: Pending passa a Approved e é enviado o email com o link de ativação.
     * A conta só fica Active quando o membro paga (webhook do Stripe). O email sai depois do commit.
     *
     * @param string $id The ID of the user to approve.
     * @return JsonResponse 200 com o UserResource, 404 ou 409 (próprio, admin ou não pendente).
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

        DB::transaction(function () use ($user) {
            $user->forceFill([
                'account_status_id' => AccountStatus::where('name', 'Approved')->value('id'),
                'inactive_reason' => null,
            ])->save();

            app(SubscriptionService::class)->sendActivationLink($user);
        });

        $user = $this->loadUserRelations($user->fresh());

        return $this->successResponse(
            new UserResource($user),
            'Utilizador aprovado. Foi enviado o email de ativação.',
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

        app(SubscriptionService::class)->cancel($user);

        $user = $this->loadUserRelations($user->fresh());

        return $this->successResponse(
            new UserResource($user),
            'Utilizador bloqueado com sucesso.',
            Response::HTTP_OK
        );
    }



    /**
     * Desbloqueia um utilizador bloqueado, nunca para Active direto (o bloqueio cancelou o pagamento):
     * quem nunca subscreveu passa a Approved e recebe o link de ativação; quem já subscreveu fica
     * Inactive por falta de pagamento e recebe o link de reativação. O email sai depois do commit.
     * Esquece a sessão de Checkout guardada: uma sessão paga antes do bloqueio (subscrição já
     * cancelada pelo webhook) bloquearia para sempre o novo pagamento com 409.
     *
     * @param string $id The ID of the user to unblock.
     * @return JsonResponse 200 com o UserResource, 404 ou 409 (próprio, admin ou não bloqueado).
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

        $neverSubscribed = $user->subscriptions()->doesntExist();

        DB::transaction(function () use ($user, $neverSubscribed) {
            $subscriptions = app(SubscriptionService::class);

            if ($neverSubscribed) {
                $user->forceFill([
                    'account_status_id' => AccountStatus::where('name', 'Approved')->value('id'),
                    'inactive_reason' => null,
                    'stripe_checkout_session_id' => null,
                ])->save();

                $subscriptions->sendActivationLink($user);

                return;
            }

            $user->forceFill([
                'account_status_id' => AccountStatus::where('name', 'Inactive')->value('id'),
                'inactive_reason' => InactiveReason::Unpaid,
                'stripe_checkout_session_id' => null,
            ])->save();

            $subscriptions->sendReactivationLink($user);
        });

        $user = $this->loadUserRelations($user->fresh());

        return $this->successResponse(
            new UserResource($user),
            $neverSubscribed
                ? 'Utilizador desbloqueado. Foi enviado o email de ativação.'
                : 'Utilizador desbloqueado. Foi enviado o email de reativação.',
            Response::HTTP_OK
        );
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
