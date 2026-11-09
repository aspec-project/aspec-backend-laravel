<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MyReviewResource extends JsonResource
{
    /**
     * Avaliação vista pelo autor ou pelo avaliado. Espera os reports por resolver carregados só com o motivo:
     * os códigos explicam a sinalização sem revelar o comentário do report nem quem o fez.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reviewer = $this->reviewerProfile;
        $reviewed = $this->reviewedProfile;

        $reviewerIsAnonymized = $reviewer?->trashed() ?? false;
        $reviewedIsAnonymized = $reviewed?->trashed() ?? false;

        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'rating' => (int) $this->rating,
            'comment' => $this->comment,
            'status' => $this->status?->name,
            'hidden_reason' => $this->status?->name === 'Hidden'
                ? $this->hidden_reason
                : null,
            'flagging_reasons' => $this->status?->name === 'Flagged'
                ? $this->reports->pluck('reason')->unique()->sort()->values()->all()
                : [],
            'reviewer' => [
                'id' => $reviewerIsAnonymized ? null : $reviewer?->id,
                'business_name' => $reviewerIsAnonymized
                    ? 'Empresa anonimizada'
                    : $reviewer?->business_name,
            ],
            'reviewed' => [
                'id' => $reviewedIsAnonymized ? null : $reviewed?->id,
                'business_name' => $reviewedIsAnonymized
                    ? 'Empresa anonimizada'
                    : $reviewed?->business_name,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
