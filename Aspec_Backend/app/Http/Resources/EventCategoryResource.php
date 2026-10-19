<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representação JSON de uma categoria de evento; o backoffice recebe também o número de eventos.
 */
class EventCategoryResource extends JsonResource
{
    /**
     * Devolve o id, o nome e a cor (sem timestamps) e, quando a contagem foi carregada (backoffice), o número de eventos.
     *
     * @param  Request  $request  Pedido HTTP atual.
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'events_count' => $this->whenCounted('eventInstances'),
        ];
    }
}
