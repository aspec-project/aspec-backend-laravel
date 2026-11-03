<?php

namespace App\Http\Resources;

use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortfolioResource extends JsonResource
{
    /**
     * Imagem do portfólio: devolve o URL público em vez do path interno no disco.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'image_url' => MediaStorage::url($this->image_path),
        ];
    }
}
