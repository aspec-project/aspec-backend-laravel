<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MemberSummaryResource extends JsonResource
{
    /**
     * Item da lista pública do diretório: identificação, setor, distrito, logótipo e avaliações.
     * Nunca inclui contactos, email nem congregação. Espera as relações sector e location carregadas.
     * Sem avaliações ainda, average_rating é null e reviews_count é 0.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'business_name' => $this->business_name,
            'sector' => [
                'id' => $this->sector->id,
                'name' => $this->sector->name,
            ],
            'location' => [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ],
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'average_rating' => isset($this->average_rating) ? round((float) $this->average_rating, 1) : null,
            'reviews_count' => (int) ($this->reviews_count ?? 0),
        ];
    }
}
