<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detalhe de um utilizador para o backoffice, usado só em rotas de administração.
 * Expõe de propósito o telefone e o inactive_reason (escondido no modelo User), o estado da
 * subscrição derivado (valor e rótulo, sem datas) e, só para admins, o número total de admins.
 * O perfil usa o MemberProfileResource, o mesmo formato que o membro vê no seu perfil.
 * Não inclui faturação, NIF nem dados do Stripe.
 */
class AdminUserDetailResource extends JsonResource
{
    /**
     * Lista branca dos campos da conta, estado da subscrição e perfil de membro (ou null).
     * Quando o utilizador é admin acrescenta admins_count (total global de administradores).
     * Espera as relações role, accountStatus e memberProfile (com user, sector, location,
     * weekDays, socialPlatforms e portfolios) carregadas.
     *
     * @param  Request  $request  Pedido atual.
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->memberProfile;
        $state = $this->resource->subscriptionState();

        return [
            'id' => $this->id,
            'email' => $this->email,
            'phone' => $this->phone,
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
            'subscription_state' => [
                'value' => $state->value,
                'label' => $state->label(),
            ],
            'admins_count' => $this->when(
                $this->role?->name === 'Admin',
                fn () => User::whereHas('role', fn ($role) => $role->where('name', 'Admin'))->count()
            ),
            'member_profile' => $profile ? new MemberProfileResource($profile) : null,
        ];
    }
}
