<?php

namespace App\Http\Resources;

use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberSummaryResource extends JsonResource
{
    /**
     * Item da lista pública do diretório: identificação, descrição completa, setor, distrito, logótipo e avaliações.
     * Nunca inclui contactos, email nem congregação. Espera as relações sector e location carregadas.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'business_name' => $this->business_name,
            'description' => $this->description,
            'sector' => [
                'id' => $this->sector->id,
                'name' => $this->sector->name,
            ],
            'location' => [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ],
            'logo_url' => MediaStorage::url($this->logo_path),
            'average_rating' => isset($this->average_rating) ? round((float) $this->average_rating, 1) : null,
            'reviews_count' => (int) ($this->reviews_count ?? 0),
        ];
    }
}
