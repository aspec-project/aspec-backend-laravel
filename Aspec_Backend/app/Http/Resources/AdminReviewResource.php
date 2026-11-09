<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminReviewResource extends JsonResource
{
    /**
     * Avaliação para a moderação, com os reports por resolver. Nunca identifica quem reportou:
     * o admin decide sobre o conteúdo da avaliação.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => (int) $this->rating,
            'comment' => $this->comment,
            'status' => $this->status?->name,
            'hidden_reason' => $this->hidden_reason,
            'reviewed' => [
                'business_name' => $this->reviewedProfile?->business_name,
            ],
            'reviewer' => [
                'business_name' => $this->reviewerProfile?->business_name,
            ],
            'flagging_sources' => $this->reports
                ->pluck('source')
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'reports' => $this->reports
                ->whereNull('resolved_at')
                ->sortBy('created_at')
                ->values()
                ->map(fn ($report): array => [
                    'id' => $report->id,
                    'source' => $report->source,
                    'reason' => $report->reason,
                    'comment' => $report->comment,
                    'created_at' => $report->created_at?->toIso8601String(),
                ])
                ->all(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
