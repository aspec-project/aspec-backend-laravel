<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\MemberProfile;
use App\Models\Review;
use App\Models\ReviewStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class MemberReviewController extends Controller
{
    public function store(
        StoreMemberReviewRequest $request,
        string $member
    ): JsonResponse {
        $user = $request->user();

        if ($user->role?->name !== 'Member') {
            return $this->errorResponse(
                'Só membros podem avaliar outros membros.',
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

        return $this->successResponse(
            new ReviewResource($review),
            $created
                ? 'Avaliação criada com sucesso.'
                : 'Avaliação atualizada com sucesso.',
            $created ? Response::HTTP_CREATED : Response::HTTP_OK
        );
    }
}
