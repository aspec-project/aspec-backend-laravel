<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexMemberReviewsRequest;
use App\Http\Requests\StoreMemberReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\MemberProfile;
use App\Models\Review;
use App\Models\ReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class MemberReviewController extends Controller
{
    public function index(
        IndexMemberReviewsRequest $request,
        string $member
    ): JsonResponse {
        $profile = MemberProfile::listedInDirectory()->find($member);

        if ($profile === null) {
            return $this->errorResponse(
                'Membro não encontrado.',
                Response::HTTP_NOT_FOUND
            );
        }

        $visibleStatus = ReviewStatus::query()
            ->where('name', 'Visible')
            ->firstOrFail();

        $visibleReviews = Review::query()
            ->where('reviewed_profile_id', $profile->getKey())
            ->where('status_id', $visibleStatus->id);

        $reviewsCount = (clone $visibleReviews)->count();
        $averageRating = (clone $visibleReviews)->avg('rating');

        $data = [
            'average_rating' => $averageRating === null
                ? null
                : round((float) $averageRating, 1),
            'reviews_count' => $reviewsCount,
        ];

        $viewer = $request->user('sanctum');
        $canSeeReviews = $viewer?->accountStatus?->name === 'Active';

        $viewerProfileId = $canSeeReviews
            ? $viewer?->memberProfile?->getKey()
            : null;

        if ($canSeeReviews) {
            $distributionCounts = (clone $visibleReviews)
                ->selectRaw('rating, COUNT(*) as total')
                ->groupBy('rating')
                ->pluck('total', 'rating');

            $ratingDistribution = array_map(
                fn (int $rating): array => [
                    'rating' => $rating,
                    'count' => (int) ($distributionCounts[$rating] ?? 0),
                ],
                range(1, 5)
            );

            $validated = $request->validated();
            $rating = $validated['rating'] ?? null;
            $sort = $validated['sort'] ?? 'newest';
            $page = $validated['page'] ?? 1;

            $reviewsQuery = (clone $visibleReviews)
                ->with('reviewerProfile')
                ->when(
                    $rating !== null,
                    function (Builder $query) use ($rating, $viewerProfileId): void {
                        $query->where(function (Builder $ratingQuery) use ($rating, $viewerProfileId): void {
                            $ratingQuery->where('rating', $rating);

                            if ($viewerProfileId !== null) {
                                $ratingQuery->orWhere('reviewer_profile_id', $viewerProfileId);
                            }
                        });
                    }
                );

            if ($viewerProfileId !== null) {
                $reviewsQuery->orderByRaw(
                    'CASE WHEN reviewer_profile_id = ? THEN 0 ELSE 1 END',
                    [$viewerProfileId]
                );
            }

            switch ($sort) {
                case 'oldest':
                    $reviewsQuery
                        ->orderBy('created_at')
                        ->orderBy('id');
                    break;

                case 'highest':
                    $reviewsQuery
                        ->orderByDesc('rating')
                        ->orderByDesc('created_at')
                        ->orderByDesc('id');
                    break;

                case 'lowest':
                    $reviewsQuery
                        ->orderBy('rating')
                        ->orderByDesc('created_at')
                        ->orderByDesc('id');
                    break;

                case 'newest':
                default:
                    $reviewsQuery
                        ->orderByDesc('created_at')
                        ->orderByDesc('id');
                    break;
            }

            $reviews = $reviewsQuery->paginate(5, ['*'], 'page', $page);

            $data += [
                'items' => ReviewResource::collection(
                    $reviews->getCollection()
                )->resolve($request),
                'pagination' => [
                    'current_page' => $reviews->currentPage(),
                    'per_page' => $reviews->perPage(),
                    'total' => $reviews->total(),
                    'last_page' => $reviews->lastPage(),
                ],
                'rating_distribution' => $ratingDistribution,
            ];
        }

        return $this->successResponse(
            $data,
            'Avaliações obtidas com sucesso.'
        )
            ->header('Cache-Control', 'private, no-store')
            ->header('Vary', 'Cookie, Authorization');
    }

    public function store(
        StoreMemberReviewRequest $request,
        string $member
    ): JsonResponse {
        $user = $request->user();

        if (! in_array($user->role?->name, ['Member', 'Admin'], true)) {
            return $this->errorResponse(
                'Só membros e administradores podem avaliar outros membros.',
                Response::HTTP_FORBIDDEN
            );
        }

        $reviewerProfile = $user->memberProfile;

        if ($reviewerProfile === null) {
            return $this->errorResponse(
                'Perfil de membro não encontrado.',
                Response::HTTP_FORBIDDEN
            );
        }

        $reviewedProfile = MemberProfile::listedInDirectory()->find($member);

        if ($reviewedProfile === null) {
            return $this->errorResponse(
                'Membro não encontrado.',
                Response::HTTP_NOT_FOUND
            );
        }

        if ($reviewerProfile->is($reviewedProfile)) {
            return $this->errorResponse(
                'Não pode avaliar o próprio perfil.',
                Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validated();

        [$review, $created] = DB::transaction(function () use (
            $reviewerProfile,
            $reviewedProfile,
            $validated
        ): array {
            // Serializa as submissões para este perfil; a unique key da BD continua
            // a ser a proteção final contra avaliações duplicadas.
            $lockedProfile = MemberProfile::listedInDirectory()
                ->whereKey($reviewedProfile->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $review = Review::query()
                ->where('reviewer_profile_id', $reviewerProfile->getKey())
                ->where('reviewed_profile_id', $lockedProfile->getKey())
                ->lockForUpdate()
                ->first();

            if ($review !== null) {
                $review->update($validated);

                return [$review, false];
            }

            $visibleStatus = ReviewStatus::query()
                ->where('name', 'Visible')
                ->firstOrFail();

            $review = Review::query()->create([
                'reviewer_profile_id' => $reviewerProfile->getKey(),
                'reviewed_profile_id' => $lockedProfile->getKey(),
                'rating' => $validated['rating'],
                'comment' => $validated['comment'],
                'status_id' => $visibleStatus->id,
            ]);

            return [$review, true];
        });
        $review->load('reviewerProfile');

        return $this->successResponse(
            new ReviewResource($review),
            $created
                ? 'Avaliação criada com sucesso.'
                : 'Avaliação atualizada com sucesso.',
            $created ? Response::HTTP_CREATED : Response::HTTP_OK
        );
    }

    public function destroy(Request $request, string $member): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->role?->name, ['Member', 'Admin'], true)) {
            return $this->errorResponse(
                'Só membros e administradores podem apagar avaliações.',
                Response::HTTP_FORBIDDEN
            );
        }

        $reviewerProfile = $user->memberProfile;

        if ($reviewerProfile === null) {
            return $this->errorResponse(
                'Perfil de membro não encontrado.',
                Response::HTTP_FORBIDDEN
            );
        }

        $reviewedProfile = MemberProfile::listedInDirectory()->find($member);

        if ($reviewedProfile === null) {
            return $this->errorResponse(
                'Membro não encontrado.',
                Response::HTTP_NOT_FOUND
            );
        }

        return DB::transaction(function () use ($reviewerProfile, $reviewedProfile): JsonResponse {
            $lockedProfile = MemberProfile::listedInDirectory()
                ->whereKey($reviewedProfile->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedProfile === null) {
                return $this->errorResponse(
                    'Membro não encontrado.',
                    Response::HTTP_NOT_FOUND
                );
            }

            $review = Review::query()
                ->where('reviewer_profile_id', $reviewerProfile->getKey())
                ->where('reviewed_profile_id', $lockedProfile->getKey())
                ->lockForUpdate()
                ->first();

            if ($review === null) {
                return $this->errorResponse(
                    'Avaliação não encontrada.',
                    Response::HTTP_NOT_FOUND
                );
            }

            $review->delete();

            return $this->successResponse(
                null,
                'Avaliação apagada com sucesso.',
                Response::HTTP_OK
            );
        });
    }
}
