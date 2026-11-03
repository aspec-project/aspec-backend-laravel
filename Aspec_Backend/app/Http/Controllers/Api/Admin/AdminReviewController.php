<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\HideAdminReviewRequest;
use App\Http\Requests\IndexAdminReviewsRequest;
use App\Http\Resources\AdminReviewResource;
use App\Models\Review;
use App\Models\ReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AdminReviewController extends Controller
{
    private const STATUS_FILTERS = [
        'flagged' => 'Flagged',
        'visible' => 'Visible',
        'hidden' => 'Hidden',
    ];

    /**
     * Lista as avaliações de acordo com os filtros fornecidos.
     */
    public function index(IndexAdminReviewsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $statusFilter = $validated['status'] ?? 'all';
        $statusName = self::STATUS_FILTERS[$statusFilter] ?? null;

        $countsByStatus = Review::query()
            ->join(
                'review_statuses',
                'reviews.status_id',
                '=',
                'review_statuses.id'
            )
            ->select('review_statuses.name')
            ->selectRaw('COUNT(reviews.id) as total')
            ->groupBy('review_statuses.name')
            ->get()
            ->mapWithKeys(
                fn (object $row): array => [
                    $row->name => (int) $row->total,
                ]
            );

        $counts = [
            'all' => (int) Review::query()->count(),
            'flagged' => (int) ($countsByStatus['Flagged'] ?? 0),
            'visible' => (int) ($countsByStatus['Visible'] ?? 0),
            'hidden' => (int) ($countsByStatus['Hidden'] ?? 0),
        ];

        $reviews = Review::query()
            ->with([
                'status',
                'reviewerProfile' => fn ($profile) => $profile->withTrashed(),
                'reviewedProfile' => fn ($profile) => $profile->withTrashed(),
                'reports:id,review_id,source',
            ])
            ->when(
                $statusName !== null,
                fn (Builder $query) => $query->whereHas(
                    'status',
                    fn (Builder $statusQuery) => $statusQuery
                        ->where('name', $statusName)
                )
            )
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(
                (int) ($validated['per_page'] ?? 10),
                ['*'],
                'page',
                (int) ($validated['page'] ?? 1)
            );

        return $this->successResponse([
            'items' => AdminReviewResource::collection(
                $reviews->getCollection()
            )->resolve($request),
            'pagination' => [
                'current_page' => $reviews->currentPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'last_page' => $reviews->lastPage(),
            ],
            'counts' => $counts,
        ], 'Lista de avaliações obtida com sucesso.')
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * Oculta uma avaliação.
     */
    public function hide(
        HideAdminReviewRequest $request,
        Review $review
    ): JsonResponse {
        $validated = $request->validated();

        return $this->changeModerationStatus(
            $review,
            ['Visible', 'Flagged'],
            'Hidden',
            $validated['justification'] ?? null,
            'Apenas avaliações visíveis ou sinalizadas podem ser ocultadas.',
            'Avaliação ocultada com sucesso.'
        );
    }

    /**
     * Mostra uma avaliação que estava oculta.
     */
    public function show(Review $review): JsonResponse
    {
        return $this->changeModerationStatus(
            $review,
            ['Hidden'],
            'Visible',
            null,
            'Apenas avaliações ocultas podem ser mostradas.',
            'Avaliação mostrada com sucesso.'
        );
    }

    /**
     * Remove a sinalização de uma avaliação.
     */
    public function unflag(Review $review): JsonResponse
    {
        return $this->changeModerationStatus(
            $review,
            ['Flagged'],
            'Visible',
            null,
            'Apenas avaliações sinalizadas podem ter a sinalização removida.',
            'Sinalização removida com sucesso.'
        );
    }

    /**
     * Altera o status de moderação de uma avaliação.
     *
     * @param  array<int, string>  $allowedCurrentStatuses
     */
    private function changeModerationStatus(
        Review $review,
        array $allowedCurrentStatuses,
        string $targetStatus,
        ?string $hiddenReason,
        string $conflictMessage,
        string $successMessage
    ): JsonResponse {
        return DB::transaction(function () use (
            $review,
            $allowedCurrentStatuses,
            $targetStatus,
            $hiddenReason,
            $conflictMessage,
            $successMessage
        ): JsonResponse {
            $review = Review::query()
                ->lockForUpdate()
                ->findOrFail($review->getKey());

            $currentStatus = $review->status()->value('name');

            if (! in_array($currentStatus, $allowedCurrentStatuses, true)) {
                return $this->errorResponse(
                    $conflictMessage,
                    Response::HTTP_CONFLICT
                );
            }

            $targetStatusId = ReviewStatus::query()
                ->where('name', $targetStatus)
                ->valueOrFail('id');

            $review->update([
                'status_id' => $targetStatusId,
                'hidden_reason' => $targetStatus === 'Hidden'
                    ? $hiddenReason
                    : null,
            ]);

            $resolvedAt = now();

            $review->reports()
                ->whereNull('resolved_at')
                ->update([
                    'resolved_at' => $resolvedAt,
                    'updated_at' => $resolvedAt,
                ]);

            $review->load([
                'status',
                'reviewerProfile' => fn ($profile) => $profile->withTrashed(),
                'reviewedProfile' => fn ($profile) => $profile->withTrashed(),
                'reports:id,review_id,source',
            ]);

            return $this->successResponse(
                new AdminReviewResource($review),
                $successMessage,
                Response::HTTP_OK
            )->header('Cache-Control', 'private, no-store');
        });
    }
}
