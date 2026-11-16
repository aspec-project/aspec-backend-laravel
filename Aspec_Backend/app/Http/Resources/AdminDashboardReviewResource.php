<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Linha das avaliações recentes do painel do backoffice.
 * Só a nota, o estado e os nomes dos negócios: sem comentário, nomes de pessoas, ids de perfis nem datas.
 * Perfis anonimizados (apagados) aparecem como «Empresa anonimizada», como no público e na área de membro.
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
        $reviewer = $this->reviewerProfile;
        $reviewed = $this->reviewedProfile;

        return [
            'id' => $this->id,
            'rating' => (int) $this->rating,
            'status' => $this->status?->name,
            'reviewed' => [
                'business_name' => $reviewed?->trashed() ? 'Empresa anonimizada' : $reviewed?->business_name,
            ],
            'reviewer' => [
                'business_name' => $reviewer?->trashed() ? 'Empresa anonimizada' : $reviewer?->business_name,
            ],
        ];
    }
}
