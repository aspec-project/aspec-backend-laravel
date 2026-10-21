<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representação JSON de um inscrito num evento para o backoffice: nome, empresa e contactos pessoais.
 * Recebe a linha já calculada pelo AdminEventAttendeeController (a mesma que vai para o CSV).
 */
class EventAttendeeResource extends JsonResource
{
    /**
     * Devolve o id da inscrição, o utilizador, o nome, a empresa, o email e o telefone pessoais e a data de inscrição (ISO 8601, UTC).
     *
     * @param  Request  $request  Pedido HTTP atual.
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'user_id' => $this->resource['user_id'],
            'name' => $this->resource['name'],
            'business_name' => $this->resource['business_name'],
            'email' => $this->resource['email'],
            'phone' => $this->resource['phone'],
            'registered_at' => $this->resource['registered_at']->toIso8601String(),
        ];
    }
}
