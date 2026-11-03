<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAdminReviewsRequest;
use App\Http\Resources\AdminReviewResource;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class AdminReviewController extends Controller
{
    private const STATUS_FILTERS = [
        'flagged' => 'Flagged',
        'visible' => 'Visible',
        'hidden' => 'Hidden',
    ];

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
}
