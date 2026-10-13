<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SocialLinkResource extends JsonResource
{
    /**
     * Rede social do perfil: recebe a SocialPlatform da relação socialPlatforms, com o URL no pivot.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'platform_id' => $this->id,
            'platform' => $this->name,
            'url' => $this->pivot->url,
        ];
    }
}
