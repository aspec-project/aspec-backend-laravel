<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\InactiveReason;
use App\Enums\SubscriptionState;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexDashboardPendingApplicationsRequest;
use App\Http\Resources\AdminDashboardReviewResource;
use App\Http\Resources\AdminUserSummaryResource;
use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\Review;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Painel do backoffice: totais e listas num só pedido e a lista paginada das candidaturas pendentes, sem cache.
 * O mês e a semana (segunda a domingo) são calculados na hora de Lisboa.
 */
class AdminDashboardController extends Controller
{
    /**
     * Número de linhas de cada lista do painel e de cada página das candidaturas pendentes.
     */
    private const LIST_LIMIT = 3;

    /**
     * Estados de subscrição que contam como membro ativo (os mesmos dos chips "Em trial" e "Ativos" da lista de utilizadores).
     * As contas em carência contam só nas quotas em atraso.
     */
    private const ACTIVE_MEMBER_STATES = [
        SubscriptionState::Trial,
        SubscriptionState::Active,
    ];

    /**
     * Nomes dos estados de avaliação que esperam pela moderação do admin.
     */
    private const FLAGGED_STATUSES = ['Flagged'];

    /**
     * Devolve os totais dos cartões, as candidaturas pendentes mais recentes e as avaliações alteradas mais recentemente.
     *
     * @param  Request  $request  Pedido atual (sem parâmetros).
     * @return JsonResponse 200 com stats, pending_applications e recent_reviews, com Cache-Control private, no-store.
     */
    public function index(Request $request): JsonResponse
    {
        $pendingApplications = $this->pendingApplicationsQuery()->limit(self::LIST_LIMIT)->get();

        return $this->successResponse([
            'stats' => $this->stats($this->periods()),
            'pending_applications' => AdminUserSummaryResource::collection($pendingApplications)->resolve($request),
            'recent_reviews' => AdminDashboardReviewResource::collection($this->recentReviews())->resolve($request),
        ], 'Dados do painel obtidos com sucesso.')->header('Cache-Control', 'private, no-store');
    }

    /**
     * Devolve uma página das candidaturas pendentes, das mais recentes para as mais antigas, 3 por página.
     * A página 1 é sempre igual à lista pending_applications do painel.
     *
     * @param  IndexDashboardPendingApplicationsRequest  $request  Pedido com a página validada.
     * @return JsonResponse 200 com items e pagination, com Cache-Control private, no-store.
     */
    public function pendingApplications(IndexDashboardPendingApplicationsRequest $request): JsonResponse
    {
        $applications = $this->pendingApplicationsQuery()->paginate(self::LIST_LIMIT);

        return $this->successResponse([
            'items' => AdminUserSummaryResource::collection($applications->getCollection())->resolve($request),
            'pagination' => [
                'current_page' => $applications->currentPage(),
                'per_page' => $applications->perPage(),
                'total' => $applications->total(),
                'last_page' => $applications->lastPage(),
            ],
        ], 'Candidaturas pendentes obtidas com sucesso.')->header('Cache-Control', 'private, no-store');
    }

    /**
     * Calcula os seis grupos de números dos cartões do painel.
     * Os membros ativos trazem o total, os novos deste mês e a divisão em trial, ativos e administradores.
     *
     * @param  array{month_start: CarbonImmutable, month_end: CarbonImmutable, week_start: CarbonImmutable, week_end: CarbonImmutable}  $periods  Limites do mês e da semana em Lisboa.
     * @return array<string, array<string, int>>
     */
    private function stats(array $periods): array
    {
        $newThisMonth = $this->activeMembers()
            ->whereIn('users.id', DB::table('subscriptions')
                ->select('user_id')
                ->groupBy('user_id')
                ->havingRaw('MIN(created_at) >= ? AND MIN(created_at) < ?', [
                    $periods['month_start']->utc()->toDateTimeString(),
                    $periods['month_start']->addMonth()->utc()->toDateTimeString(),
                ]))
            ->count();

        $pendingTotal = User::query()
            ->whereHas('accountStatus', fn (Builder $status) => $status->where('name', 'Pending'))
            ->count();

        $eventsThisMonth = EventInstance::query()
            ->whereDate('event_date', '>=', $periods['month_start']->toDateString())
            ->whereDate('event_date', '<=', $periods['month_end']->toDateString())
            ->count();

        $eventsThisWeek = EventInstance::query()
            ->whereDate('event_date', '>=', $periods['week_start']->toDateString())
            ->whereDate('event_date', '<=', $periods['week_end']->toDateString())
            ->count();

        $flagged = Review::query()
            ->whereHas('status', fn (Builder $status) => $status->whereIn('name', self::FLAGGED_STATUSES))
            ->count();

        $newReviewsThisWeek = Review::query()
            ->where('created_at', '>=', $periods['week_start']->utc()->toDateTimeString())
            ->where('created_at', '<', $periods['week_start']->addWeek()->utc()->toDateTimeString())
            ->count();

        $activeSplit = $this->activeMemberCounts();

        return [
            'active_members' => [
                'total' => $activeSplit['trial'] + $activeSplit['active'],
                'new_this_month' => (int) $newThisMonth,
                ...$activeSplit,
            ],
            'pending_applications' => [
                'total' => (int) $pendingTotal,
            ],
            'events' => [
                'this_month' => (int) $eventsThisMonth,
                'this_week' => (int) $eventsThisWeek,
            ],
            'reviews' => [
                'flagged' => (int) $flagged,
                'new_this_week' => (int) $newReviewsThisWeek,
            ],
            'overdue_payments' => $this->overduePayments(),
            'event_registrations' => $this->registrationsThisWeek($periods),
        ];
    }

    /**
     * Membros ativos divididos por estado (trial e ativo) e quantos deles são administradores,
     * numa só consulta. O total é a soma dos dois estados, que nunca se sobrepõem.
     * O admin técnico (isento) e os admins em carência ou inativos não contam.
     *
     * @return array{trial: int, active: int, admins: int}
     */
    private function activeMemberCounts(): array
    {
        $trialQuery = User::query()
            ->inSubscriptionState(SubscriptionState::Trial)
            ->selectRaw("'trial' as bucket, COUNT(*) as aggregate")
            ->toBase();

        $adminsQuery = $this->activeMembers()
            ->whereHas('role', fn (Builder $role) => $role->where('name', 'Admin'))
            ->selectRaw("'admins' as bucket, COUNT(*) as aggregate")
            ->toBase();

        $counts = User::query()
            ->inSubscriptionState(SubscriptionState::Active)
            ->selectRaw("'active' as bucket, COUNT(*) as aggregate")
            ->toBase()
            ->unionAll($trialQuery)
            ->unionAll($adminsQuery)
            ->pluck('aggregate', 'bucket');

        return [
            'trial' => (int) ($counts['trial'] ?? 0),
            'active' => (int) ($counts['active'] ?? 0),
            'admins' => (int) ($counts['admins'] ?? 0),
        ];
    }

    /**
     * Contas com quotas em atraso: em carência (cobrança falhada, ainda com acesso) e inativas por falta de pagamento.
     * Usa o scope dos estados de subscrição, com as duas contagens numa só consulta.
     *
     * @return array{total: int, grace: int, unpaid: int}
     */
    private function overduePayments(): array
    {
        $graceQuery = User::query()
            ->inSubscriptionState(SubscriptionState::Grace)
            ->selectRaw("'grace' as bucket, COUNT(*) as aggregate")
            ->toBase();

        $counts = User::query()
            ->inSubscriptionState(SubscriptionState::Lapsed)
            ->where('inactive_reason', InactiveReason::Unpaid->value)
            ->selectRaw("'unpaid' as bucket, COUNT(*) as aggregate")
            ->toBase()
            ->unionAll($graceQuery)
            ->pluck('aggregate', 'bucket');

        $grace = (int) ($counts['grace'] ?? 0);
        $unpaid = (int) ($counts['unpaid'] ?? 0);

        return [
            'total' => $grace + $unpaid,
            'grace' => $grace,
            'unpaid' => $unpaid,
        ];
    }

    /**
     * Inscrições em eventos com data na semana corrente (segunda a domingo, em Lisboa) e
     * quantos eventos distintos as recebem, numa só consulta.
     * Cada linha conta: sessões de séries, eventos online e inscrições de contas já apagadas.
     *
     * @param  array{week_start: CarbonImmutable, week_end: CarbonImmutable}  $periods  Limites da semana em Lisboa.
     * @return array{this_week: int, events_with_registrations: int}
     */
    private function registrationsThisWeek(array $periods): array
    {
        $counts = EventRegistration::query()
            ->whereHas('eventInstance', fn (Builder $event) => $event
                ->whereDate('event_date', '>=', $periods['week_start']->toDateString())
                ->whereDate('event_date', '<=', $periods['week_end']->toDateString()))
            ->toBase()
            ->selectRaw('COUNT(*) as registrations, COUNT(DISTINCT event_instance_id) as events')
            ->first();

        return [
            'this_week' => (int) $counts->registrations,
            'events_with_registrations' => (int) $counts->events,
        ];
    }

    /**
     * Utilizadores em trial ou ativos, pelo scope dos estados de subscrição.
     *
     * @return Builder<User>
     */
    private function activeMembers(): Builder
    {
        return User::query()->where(function (Builder $query) {
            foreach (self::ACTIVE_MEMBER_STATES as $state) {
                $query->orWhere(fn (Builder $user) => $user->inSubscriptionState($state));
            }
        });
    }

    /**
     * Consulta-base das candidaturas pendentes, das mais recentes para as mais antigas (desempate pelo id),
     * com as relações que o AdminUserSummaryResource usa. Partilhada pelo painel e pela lista paginada.
     *
     * @return Builder<User>
     */
    private function pendingApplicationsQuery(): Builder
    {
        return User::query()
            ->whereHas('accountStatus', fn (Builder $status) => $status->where('name', 'Pending'))
            ->with(['role', 'accountStatus', 'memberProfile.sector', 'memberProfile.location'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * As avaliações alteradas mais recentemente, de qualquer estado, incluindo os perfis apagados.
     *
     * @return Collection<int, Review>
     */
    private function recentReviews(): Collection
    {
        return Review::query()
            ->with([
                'status',
                'reviewerProfile' => fn ($profile) => $profile->withTrashed(),
                'reviewedProfile' => fn ($profile) => $profile->withTrashed(),
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    /**
     * Limites do mês e da semana correntes (segunda a domingo) na hora de Lisboa.
     *
     * @return array{month_start: CarbonImmutable, month_end: CarbonImmutable, week_start: CarbonImmutable, week_end: CarbonImmutable}
     */
    private function periods(): array
    {
        $now = CarbonImmutable::now(config('app.event_timezone', 'Europe/Lisbon'));

        return [
            'month_start' => $now->startOfMonth(),
            'month_end' => $now->endOfMonth(),
            'week_start' => $now->startOfWeek(CarbonInterface::MONDAY),
            'week_end' => $now->endOfWeek(CarbonInterface::SUNDAY),
        ];
    }
}
