<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Item da lista de utilizadores do backoffice, usado só em rotas de administração.
 * Expõe de propósito o inactive_reason (escondido no modelo User) para o admin distinguir
 * bloqueados, recusados e pagamentos em falta. Não inclui telefone, faturação nem dados do Stripe.
 */
class AdminUserSummaryResource extends JsonResource
{
    /**
     * Lista branca dos campos do item. Espera as relações role, accountStatus,
     * memberProfile.sector e memberProfile.location carregadas.
     *
     * @param Request $request Pedido atual.
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->memberProfile;

        return [
            'id' => $this->id,
            'email' => $this->email,
            'created_at' => $this->created_at?->toIso8601String(),
            'role' => [
                'id' => $this->role?->id,
                'name' => $this->role?->name,
            ],
            'account_status' => [
                'id' => $this->accountStatus?->id,
                'name' => $this->accountStatus?->name,
            ],
            'inactive_reason' => $this->inactive_reason?->value,
            'member_profile' => $profile ? [
                'name' => $profile->name,
                'business_name' => $profile->business_name,
                'sector' => $profile->sector ? [
                    'id' => $profile->sector->id,
                    'name' => $profile->sector->name,
                ] : null,
                'location' => $profile->location ? [
                    'id' => $profile->location->id,
                    'name' => $profile->location->name,
                ] : null,
            ] : null,
        ];
    }
}
