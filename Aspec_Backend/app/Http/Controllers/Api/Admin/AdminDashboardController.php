<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\SubscriptionState;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminDashboardReviewResource;
use App\Http\Resources\AdminUserSummaryResource;
use App\Models\EventInstance;
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
 * Totais e listas do painel do backoffice, num só pedido e sem cache.
 * O mês e a semana (segunda a domingo) são calculados na hora de Lisboa.
 */
class AdminDashboardController extends Controller
{
    /**
     * Número de linhas de cada lista do painel.
     */
    private const LIST_LIMIT = 3;

    /**
     * Estados de subscrição que contam como membro ativo (os mesmos dos chips da lista de utilizadores).
     */
    private const ACTIVE_MEMBER_STATES = [
        SubscriptionState::Trial,
        SubscriptionState::Active,
        SubscriptionState::Grace,
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
    public function __invoke(Request $request): JsonResponse
    {
        return $this->successResponse([
            'stats' => $this->stats($this->periods()),
            'pending_applications' => AdminUserSummaryResource::collection($this->pendingApplications())->resolve($request),
            'recent_reviews' => AdminDashboardReviewResource::collection($this->recentReviews())->resolve($request),
        ], 'Dados do painel obtidos com sucesso.')->header('Cache-Control', 'private, no-store');
    }

    /**
     * Calcula os números dos quatro cartões do painel.
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

        return [
            'active_members' => [
                'total' => (int) $this->activeMembers()->count(),
                'new_this_month' => (int) $newThisMonth,
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
        ];
    }

    /**
     * Utilizadores em trial, ativos ou em carência, pelo scope dos estados de subscrição.
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
     * As candidaturas pendentes mais recentes, com as relações que o AdminUserSummaryResource usa.
     *
     * @return Collection<int, User>
     */
    private function pendingApplications(): Collection
    {
        return User::query()
            ->whereHas('accountStatus', fn (Builder $status) => $status->where('name', 'Pending'))
            ->with(['role', 'accountStatus', 'memberProfile.sector', 'memberProfile.location'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();
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
