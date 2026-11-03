<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => (int) $this->rating,
            'comment' => $this->comment,
            'status' => $this->status?->name,
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
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
