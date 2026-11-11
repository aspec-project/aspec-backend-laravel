<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Avaliação devolvida ao autor depois de a criar ou editar: os campos públicos e o estado de moderação.
 */
class AuthorReviewResource extends ReviewResource
{
    /**
     * Junta o estado da avaliação (Visible, Flagged ou Hidden) aos campos públicos.
     *
     * @param  Request  $request  Pedido HTTP atual.
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'status' => $this->status?->name,
        ];
    }
}
