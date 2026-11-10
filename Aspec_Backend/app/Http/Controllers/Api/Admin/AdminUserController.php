<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\InactiveReason;
use App\Enums\SubscriptionState;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAdminUserRequest;
use App\Http\Resources\AdminUserDetailResource;
use App\Http\Resources\AdminUserSummaryResource;
use App\Http\Resources\UserResource;
use App\Models\AccountStatus;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserBlockedNotification;
use App\Notifications\UserRejectedNotification;
use App\Services\Payments\SubscriptionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Response;

class AdminUserController extends Controller
{
    private const USERS_PER_PAGE = 12;

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
     * Lê o utilizador com o papel e o estado e bloqueia a linha até ao fim da transação, para que
     * duas ações de admins sobre a mesma conta se façam uma depois da outra.
     *
     * @param  string  $id  Id (UUID) do utilizador.
     * @return User Utilizador bloqueado para escrita.
     *
     * @throws ModelNotFoundException Se não existir ou estiver apagado (404).
     */
    private function lockUserForStatusChange(string $id): User
    {
        return User::with(['role', 'accountStatus'])
            ->lockForUpdate()
            ->findOrFail($id);
    }

    /**
     * Guardas comuns às mudanças de estado: um admin não altera a própria conta nem a de outro admin.
     *
     * @return string|null Código do erro ('self' ou 'admin'), ou null se a mudança for permitida.
     */
    private function statusChangeGuard(Request $request, User $user): ?string
    {
        if ($user->id === $request->user()->id) {
            return 'self';
        }

        if ($user->role?->name === 'Admin') {
            return 'admin';
        }

        return null;
    }

    /**
     * Resposta 409 para o código de erro devolvido pela transação de uma mudança de estado.
     *
     * @param  string  $error  Código do erro.
     * @return JsonResponse 409 com a mensagem correspondente.
     */
    private function statusConflictResponse(string $error): JsonResponse
    {
        $message = match ($error) {
            'self' => 'Não pode alterar o estado da própria conta.',
            'admin' => 'Não pode alterar o estado de um administrador.',
            'approve_not_pending' => 'Apenas utilizadores pendentes podem ser aprovados.',
            'reject_not_pending' => 'Apenas utilizadores pendentes podem ser rejeitados.',
            'already_blocked' => 'O utilizador já está bloqueado.',
            'not_blocked' => 'Apenas utilizadores bloqueados podem ser desbloqueados.',
        };

        return $this->errorResponse($message, Response::HTTP_CONFLICT);
    }

    /**
     * Aprova uma candidatura: Pending passa a Approved e é enviado o email com o link de ativação.
     * A conta só fica Active quando o membro paga (webhook do Stripe). O estado é lido com o utilizador
     * bloqueado na transação: com dois admins em simultâneo, o segundo vê a conta já aprovada e recebe 409.
     * O email sai depois do commit.
     *
     * @param  string  $id  The ID of the user to approve.
     * @return JsonResponse 200 com o UserResource, 404 ou 409 (próprio, admin ou não pendente).
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $error = DB::transaction(function () use ($request, $id) {
            $user = $this->lockUserForStatusChange($id);

            $error = $this->statusChangeGuard($request, $user);

            if ($error !== null) {
                return $error;
            }

            if ($user->accountStatus?->name !== 'Pending') {
                return 'approve_not_pending';
            }

            $user->forceFill([
                'account_status_id' => AccountStatus::where('name', 'Approved')->value('id'),
                'inactive_reason' => null,
            ])->save();

            app(SubscriptionService::class)->sendActivationLink($user);

            return null;
        });

        if ($error !== null) {
            return $this->statusConflictResponse($error);
        }

        $user = $this->loadUserRelations(User::findOrFail($id));

        return $this->successResponse(
            new UserResource($user),
            'Utilizador aprovado. Foi enviado o email de ativação.',
            Response::HTTP_OK
        );
    }

    /**
     * Rejeita uma candidatura: Pending passa a Inactive, a conta é anonimizada e apagada e é enviado
     * o email de rejeição. O estado é lido e alterado com o utilizador bloqueado na transação (o segundo
     * de dois admins recebe 409); o cancelamento, a anonimização e o email correm depois do commit,
     * porque falam com o Stripe ou com o servidor de email.
     *
     * @param  string  $id  The ID of the user to reject.
     * @return JsonResponse 200 com sucesso, 404, 409 (próprio, admin ou não pendente) ou 422 (motivo).
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $user = null;
        $validated = [];
        $originalEmail = null;

        $error = DB::transaction(function () use ($request, $id, &$user, &$validated, &$originalEmail) {
            $user = $this->lockUserForStatusChange($id);

            $error = $this->statusChangeGuard($request, $user);

            if ($error !== null) {
                return $error;
            }

            if ($user->accountStatus?->name !== 'Pending') {
                return 'reject_not_pending';
            }

            $validated = $request->validate([
                'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ]);

            $originalEmail = $user->email;

            $user->deactivate(InactiveReason::Deleted->value);

            return null;
        });

        if ($error !== null) {
            return $this->statusConflictResponse($error);
        }

        app(SubscriptionService::class)->cancel($user);

        if (! $user->anonymizeAndDelete($request->user())) {
            return $this->errorResponse(
                'Recurso não encontrado.',
                Response::HTTP_NOT_FOUND
            );
        }

        rescue(
            fn () => Notification::route('mail', $originalEmail)->notify(
                new UserRejectedNotification($validated['reason'] ?? null)
            ),
            fn () => Log::warning(
                'Falha ao enviar o email de rejeição da candidatura.',
                ['user_id' => $user->id]
            ),
            report: false
        );

        return $this->successResponse(
            null,
            'Utilizador rejeitado com sucesso.',
            Response::HTTP_OK
        );
    }

    /**
     * Bloqueia um utilizador: a conta passa a Inactive (motivo blocked), as sessões terminam, as inscrições
     * em eventos que ainda não começaram são apagadas (sem emails; não voltam ao desbloquear), a subscrição
     * é cancelada e é enviado o email de bloqueio. O estado é lido e alterado com o utilizador bloqueado
     * na transação (o segundo de dois admins recebe 409 e nada é apagado); o cancelamento no Stripe e o
     * email correm depois do commit, fora de qualquer transação.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @param  string  $id  The ID of the user to block.
     * @return JsonResponse 200 com o UserResource, 404, 409 (próprio, admin ou já bloqueado) ou 422 (motivo).
     */
    public function block(Request $request, string $id): JsonResponse
    {
        $user = null;
        $validated = [];

        $error = DB::transaction(function () use ($request, $id, &$user, &$validated) {
            $user = $this->lockUserForStatusChange($id);

            $error = $this->statusChangeGuard($request, $user);

            if ($error !== null) {
                return $error;
            }

            if (
                $user->accountStatus?->name === 'Inactive'
                && $user->inactive_reason === InactiveReason::Blocked
            ) {
                return 'already_blocked';
            }

            $validated = $request->validate([
                'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ]);

            $user->deactivate(InactiveReason::Blocked->value);
            $user->deleteUpcomingEventRegistrations();

            return null;
        });

        if ($error !== null) {
            return $this->statusConflictResponse($error);
        }

        app(SubscriptionService::class)->cancel($user);

        $user = $this->loadUserRelations($user->fresh());

        rescue(
            fn () => Notification::send(
                $user,
                new UserBlockedNotification($validated['reason'] ?? null)
            ),
            fn () => Log::warning(
                'Falha ao enviar o email de bloqueio do utilizador.',
                ['user_id' => $user->id]
            ),
            report: false
        );

        return $this->successResponse(
            new UserResource($user),
            'Utilizador bloqueado com sucesso.',
            Response::HTTP_OK
        );
    }

    /**
     * Desbloqueia um utilizador bloqueado, nunca para Active direto (o bloqueio cancelou o pagamento):
     * quem nunca subscreveu passa a Approved e recebe o link de ativação; quem já subscreveu fica
     * Inactive por falta de pagamento e recebe o link de reativação. O estado e as subscrições são lidos
     * com o utilizador bloqueado na transação (o segundo de dois admins recebe 409). O email sai depois
     * do commit. Esquece a sessão de Checkout guardada: uma sessão paga antes do bloqueio (subscrição já
     * cancelada pelo webhook) bloquearia para sempre o novo pagamento com 409.
     *
     * @param  string  $id  The ID of the user to unblock.
     * @return JsonResponse 200 com o UserResource, 404 ou 409 (próprio, admin ou não bloqueado).
     */
    public function unblock(Request $request, string $id): JsonResponse
    {
        $neverSubscribed = false;

        $error = DB::transaction(function () use ($request, $id, &$neverSubscribed) {
            $user = $this->lockUserForStatusChange($id);

            $error = $this->statusChangeGuard($request, $user);

            if ($error !== null) {
                return $error;
            }

            if (
                $user->accountStatus?->name !== 'Inactive'
                || $user->inactive_reason !== InactiveReason::Blocked
            ) {
                return 'not_blocked';
            }

            $neverSubscribed = $user->subscriptions()->doesntExist();
            $subscriptions = app(SubscriptionService::class);

            if ($neverSubscribed) {
                $user->forceFill([
                    'account_status_id' => AccountStatus::where('name', 'Approved')->value('id'),
                    'inactive_reason' => null,
                    'stripe_checkout_session_id' => null,
                ])->save();

                $subscriptions->sendActivationLink($user);

                return null;
            }

            $user->forceFill([
                'account_status_id' => AccountStatus::where('name', 'Inactive')->value('id'),
                'inactive_reason' => InactiveReason::Unpaid,
                'stripe_checkout_session_id' => null,
            ])->save();

            $subscriptions->sendReactivationLink($user);

            return null;
        });

        if ($error !== null) {
            return $this->statusConflictResponse($error);
        }

        $user = $this->loadUserRelations(User::findOrFail($id));

        return $this->successResponse(
            new UserResource($user),
            $neverSubscribed
                ? 'Utilizador desbloqueado. Foi enviado o email de ativação.'
                : 'Utilizador desbloqueado. Foi enviado o email de reativação.',
            Response::HTTP_OK
        );
    }

    /**
     * Promotes a user to the Admin role.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @param  string  $id  The ID of the user to promote.
     * @return JsonResponse A JSON response indicating the result of the promotion.
     */
    public function promote(Request $request, string $id): JsonResponse
    {
        $user = User::with(['role', 'accountStatus'])->findOrFail($id);

        if ($user->id === $request->user()->id) {
            return $this->errorResponse(
                'Não pode alterar o seu próprio papel.',
                Response::HTTP_CONFLICT
            );
        }

        if ($user->role?->name === 'Admin') {
            return $this->errorResponse(
                'O utilizador já é administrador.',
                Response::HTTP_CONFLICT
            );
        }

        if ($user->accountStatus?->name !== 'Active') {
            return $this->errorResponse(
                'Apenas contas ativas podem ser promovidas a administrador.',
                Response::HTTP_CONFLICT
            );
        }

        $user->forceFill([
            'role_id' => Role::where('name', 'Admin')->value('id'),
        ])->save();

        $user = $this->loadUserRelations($user->fresh());

        return $this->successResponse(
            new UserResource($user),
            'Utilizador promovido a administrador.'
        );
    }

    /**
     * Demotes a user from the Admin role to the Member role.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @param  string  $id  The ID of the user to demote.
     * @return JsonResponse A JSON response indicating the result of the demotion.
     */
    public function demote(Request $request, string $id): JsonResponse
    {
        $adminRoleId = Role::where('name', 'Admin')->value('id');
        $memberRoleId = Role::where('name', 'Member')->value('id');

        $error = DB::transaction(function () use ($request, $id, $adminRoleId, $memberRoleId) {
            // Bloqueia os admins em ordem consistente para serializar despromoções simultâneas.
            $admins = User::where('role_id', $adminRoleId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $user = User::with(['role', 'accountStatus'])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($user->role_id !== $adminRoleId) {
                return 'not_admin';
            }

            if ($user->subscription_exempt) {
                return 'technical_admin';
            }

            if ($user->id === $request->user()->id) {
                return 'self';
            }

            if ($admins->count() <= 1) {
                return 'last_admin';
            }

            $user->forceFill([
                'role_id' => $memberRoleId,
            ])->save();

            return null;
        });

        if ($error !== null) {
            $message = match ($error) {
                'self' => 'Não pode alterar o seu próprio papel.',
                'not_admin' => 'O utilizador não é administrador.',
                'last_admin' => 'Tem de existir pelo menos um administrador.',
                'technical_admin' => 'O administrador técnico não pode ser despromovido.',
            };

            return $this->errorResponse($message, Response::HTTP_CONFLICT);
        }

        $user = $this->loadUserRelations(
            User::with(['role', 'accountStatus'])->findOrFail($id)
        );

        return $this->successResponse(
            new UserResource($user),
            'Administrador despromovido a membro.'
        );
    }

    /**
     * Lista os utilizadores do backoffice, paginada (12 por página) e por ordem alfabética do nome
     * profissional (email se não houver perfil), com desempate pelo id. Admins só com include_admins,
     * e nesse caso primeiro. As contagens por separador seguem a pesquisa e o include_admins;
     * admins_count é o total global de administradores (ignora filtros), usado no aviso de despromover.
     *
     * @param  IndexAdminUserRequest  $request  Filtros validados.
     * @return JsonResponse 200 com items, pagination, counts e admins_count.
     */
    public function index(IndexAdminUserRequest $request): JsonResponse
    {
        $includeAdmins = $request->boolean('include_admins');
        $search = trim((string) $request->input('search', ''));

        $base = User::query()
            ->when(! $includeAdmins, fn (Builder $query) => $query->whereDoesntHave(
                'role',
                fn (Builder $roleQuery) => $roleQuery->where('name', 'Admin')
            ))
            ->when($search !== '', fn (Builder $query) => $this->applySearch($query, $search));

        $users = $this->applyStatusFilter(
            (clone $base)->with(['role', 'accountStatus', 'memberProfile.sector', 'memberProfile.location']),
            $request->input('status'),
            $request->filled('blocked') ? $request->boolean('blocked') : null
        )
            ->when($request->filled('subscription_state'),
                fn (Builder $query) => $query->inSubscriptionState(
                    SubscriptionState::from($request->input('subscription_state'))
                ))
            ->when($includeAdmins, fn (Builder $query) => $query->orderByRaw(
                'CASE WHEN users.role_id = ? THEN 0 ELSE 1 END',
                [Role::where('name', 'Admin')->value('id')]
            ))
            ->orderByRaw('LOWER(COALESCE((SELECT member_profiles.name FROM member_profiles WHERE member_profiles.user_id = users.id AND member_profiles.deleted_at IS NULL LIMIT 1), users.email))')
            ->orderBy('users.id')
            ->paginate(self::USERS_PER_PAGE);

        return $this->successResponse([
            'items' => AdminUserSummaryResource::collection($users->getCollection())->resolve($request),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
            'counts' => $this->countByTab($base),
            'subscription_state_counts' => User::subscriptionStateCounts($base),
            'admins_count' => User::whereHas('role', fn (Builder $role) => $role->where('name', 'Admin'))->count(),
        ], 'Lista de utilizadores obtida com sucesso.');
    }

    /**
     * Detalhe de um utilizador para o backoffice: dados da conta e perfil de membro completo
     * (horário, redes sociais e portfólio) e contagens das avaliações recebidas e feitas
     * (só números). Inclui admins e a própria conta, só para leitura.
     *
     * @param  string  $id  Id (UUID) do utilizador.
     * @return JsonResponse 200 com o AdminUserDetailResource; 404 se não existir ou estiver apagado.
     */
    public function show(string $id): JsonResponse
    {
        $user = $this->loadUserRelations(User::findOrFail($id));
        $this->loadReviewCounts($user);

        return $this->successResponse(
            new AdminUserDetailResource($user),
            'Utilizador obtido com sucesso.'
        );
    }

    /**
     * Carrega no perfil do utilizador as contagens de avaliações recebidas e feitas
     * (total, ocultas e sinalizadas) numa só consulta. Sem perfil não faz nada.
     *
     * @param  User  $user  Utilizador com a relação memberProfile já carregada.
     */
    private function loadReviewCounts(User $user): void
    {
        $user->memberProfile?->loadCount([
            'reviewsReceived as reviews_received_count',
            'reviewsReceived as reviews_received_hidden_count' => fn (Builder $query) => $query->whereRelation('status', 'name', 'Hidden'),
            'reviewsReceived as reviews_received_flagged_count' => fn (Builder $query) => $query->whereRelation('status', 'name', 'Flagged'),
            'reviewsWritten as reviews_given_count',
            'reviewsWritten as reviews_given_hidden_count' => fn (Builder $query) => $query->whereRelation('status', 'name', 'Hidden'),
            'reviewsWritten as reviews_given_flagged_count' => fn (Builder $query) => $query->whereRelation('status', 'name', 'Flagged'),
        ]);
    }

    /**
     * Restringe a consulta aos utilizadores cujo email, nome profissional ou nome do negócio
     * contêm o texto pesquisado.
     *
     * @param  Builder  $query  Consulta de utilizadores.
     * @param  string  $search  Texto pesquisado, já sem espaços nas pontas.
     */
    private function applySearch(Builder $query, string $search): void
    {
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

    /**
     * Aplica o filtro de um separador: estado da conta e, com Inactive, bloqueados ou não.
     * Os inativos não bloqueados incluem os que não têm motivo (NULL).
     *
     * @param  Builder  $query  Consulta de utilizadores.
     * @param  string|null  $status  Nome do estado da conta, ou null para todos.
     * @param  bool|null  $blocked  true só bloqueados, false só não bloqueados, null sem filtro.
     * @return Builder A mesma consulta, filtrada.
     */
    private function applyStatusFilter(Builder $query, ?string $status, ?bool $blocked): Builder
    {
        if ($status !== null && $status !== '') {
            $query->whereHas(
                'accountStatus',
                fn (Builder $statusQuery) => $statusQuery->where('name', $status)
            );
        }

        if ($blocked === true) {
            $query->where('users.inactive_reason', InactiveReason::Blocked->value);
        }

        if ($blocked === false) {
            $query->where(fn (Builder $reasonQuery) => $reasonQuery
                ->whereNull('users.inactive_reason')
                ->orWhere('users.inactive_reason', '!=', InactiveReason::Blocked->value));
        }

        return $query;
    }

    /**
     * Conta os utilizadores de cada separador sobre a mesma consulta base (pesquisa e include_admins).
     *
     * @param  Builder  $base  Consulta base, sem filtro de estado.
     * @return array<string, int> Uma contagem por separador.
     */
    private function countByTab(Builder $base): array
    {
        return [
            'all' => (clone $base)->count(),
            'active' => $this->applyStatusFilter(clone $base, 'Active', null)->count(),
            'pending' => $this->applyStatusFilter(clone $base, 'Pending', null)->count(),
            'approved' => $this->applyStatusFilter(clone $base, 'Approved', null)->count(),
            'blocked' => $this->applyStatusFilter(clone $base, 'Inactive', true)->count(),
            'inactive' => $this->applyStatusFilter(clone $base, 'Inactive', false)->count(),
        ];
    }

    //
}
