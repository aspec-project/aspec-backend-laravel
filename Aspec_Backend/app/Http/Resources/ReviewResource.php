<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reviewer = $this->reviewerProfile;
        $reviewerIsAnonymized = $reviewer?->trashed() ?? false;
        $viewerProfileId = $request->user('sanctum')?->memberProfile?->getKey();

        return [
            'id' => $this->id,
            'rating' => (int) $this->rating,
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'reviewer' => [
                'id' => $reviewerIsAnonymized ? null : $reviewer?->id,
                'business_name' => $reviewerIsAnonymized
                    ? 'Empresa anonimizada'
                    : $reviewer?->business_name,
            ],
            'is_mine' => $viewerProfileId !== null
                && (string) $this->reviewer_profile_id === (string) $viewerProfileId,
        ];
    }
}
