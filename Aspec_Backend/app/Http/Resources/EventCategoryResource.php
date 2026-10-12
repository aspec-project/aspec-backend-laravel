<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representação JSON de uma categoria de evento, igual na lista pública e no backoffice.
 */
class EventCategoryResource extends JsonResource
{
    /**
     * Devolve só o id, o nome e a cor (sem timestamps).
     *
     * @param Request $request Pedido HTTP atual.
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
        ];
    }
}
