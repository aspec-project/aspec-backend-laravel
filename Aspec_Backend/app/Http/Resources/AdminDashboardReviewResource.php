<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Linha das avaliações recentes do painel do backoffice.
 * Só a nota, o estado e os nomes dos negócios: sem comentário, nomes de pessoas, ids de perfis nem datas.
 */
class AdminDashboardReviewResource extends JsonResource
{
    /**
     * Lista branca dos campos da linha. Espera as relações status, reviewerProfile e reviewedProfile
     * carregadas, com os perfis apagados incluídos (withTrashed).
     *
     * @param  Request  $request  Pedido atual.
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => (int) $this->rating,
            'status' => $this->status?->name,
            'reviewed' => [
                'business_name' => $this->reviewedProfile?->business_name,
            ],
            'reviewer' => [
                'business_name' => $this->reviewerProfile?->business_name,
            ],
        ];
    }
}
