<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberReviewReportRequest;
use App\Models\Review;
use App\Models\ReviewReport;
use App\Models\ReviewStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class MemberReviewReportController extends Controller
{
    public function store(
        StoreMemberReviewReportRequest $request,
        Review $review
    ): JsonResponse {
        $user = $request->user();

        if ($user->role?->name !== 'Member') {
            return $this->errorResponse(
                'Só membros podem sinalizar avaliações.',
                Response::HTTP_FORBIDDEN
            );
        }

        $reporterProfile = $user->memberProfile;

        if ($reporterProfile === null) {
            return $this->errorResponse(
                'Perfil de membro não encontrado.',
                Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validated();

        return DB::transaction(function () use (
            $review,
            $reporterProfile,
            $validated
        ): JsonResponse {
            $review = Review::query()
                ->lockForUpdate()
                ->findOrFail($review->getKey());

            if (
                (string) $review->reviewer_profile_id
                === (string) $reporterProfile->getKey()
            ) {
                return $this->errorResponse(
                    'Não pode sinalizar a própria avaliação.',
                    Response::HTTP_FORBIDDEN
                );
            }

            $currentStatus = $review->status()->value('name');

            if (! in_array($currentStatus, ['Visible', 'Flagged'], true)) {
                return $this->errorResponse(
                    'Só avaliações visíveis ou já sinalizadas podem ser reportadas.',
                    Response::HTTP_CONFLICT
                );
            }

            $hasPendingReport = $review->reports()
                ->where('reporter_profile_id', $reporterProfile->getKey())
                ->whereNull('resolved_at')
                ->exists();

            if ($hasPendingReport) {
                return $this->errorResponse(
                    'Já sinalizou esta avaliação e o report ainda está pendente.',
                    Response::HTTP_CONFLICT
                );
            }

            $report = $review->reports()->create([
                'reporter_profile_id' => $reporterProfile->getKey(),
                'reason' => $validated['reason'],
                'comment' => $validated['comment'] ?? null,
                'source' => ReviewReport::SOURCE_MEMBER,
            ]);

            if ($currentStatus === 'Visible') {
                $flaggedStatusId = ReviewStatus::query()
                    ->where('name', 'Flagged')
                    ->valueOrFail('id');

                $review->update([
                    'status_id' => $flaggedStatusId,
                ]);
            }

            return $this->successResponse([
                'id' => $report->id,
                'review_id' => $report->review_id,
                'reason' => $report->reason,
                'comment' => $report->comment,
                'source' => $report->source,
                'resolved_at' => $report->resolved_at?->toIso8601String(),
                'created_at' => $report->created_at?->toIso8601String(),
            ], 'Avaliação sinalizada para moderação.', Response::HTTP_CREATED);
        });
    }
}
