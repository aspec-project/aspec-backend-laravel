<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resposta da anonimização de uma conta: só o id da conta (para o backoffice a tirar da lista) e a
 * data. O admin que anonimizou fica guardado no registo de prova, mas não é devolvido.
 */
class AccountAnonymizationResource extends JsonResource
{
    /**
     * O id é o da conta anonimizada, não o do registo de prova.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user_id,
            'anonymized_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
