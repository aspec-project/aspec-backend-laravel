<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detalhe de um utilizador para o backoffice, usado só em rotas de administração.
 * Expõe de propósito o telefone e o inactive_reason (escondido no modelo User), o estado da
 * subscrição derivado (valor e rótulo, sem datas), as contagens de avaliações recebidas e
 * feitas (só números, nunca o conteúdo) e, só para admins, o número total de admins.
 * As contagens esperam o loadCount de AdminUserController::loadReviewCounts; sem ele dão 0.
 * O perfil usa o MemberProfileResource, o mesmo formato que o membro vê no seu perfil.
 * Não inclui faturação, NIF nem dados do Stripe.
 */
class AdminUserDetailResource extends JsonResource
{
    /**
     * Lista branca dos campos da conta, estado da subscrição e perfil de membro (ou null).
     * Quando o utilizador é admin acrescenta admins_count (total global de administradores).
     * review_counts está sempre presente (zeros sem perfil) e espera as contagens carregadas
     * por AdminUserController::loadReviewCounts.
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
            'review_counts' => $this->reviewCounts(),
            'member_profile' => $profile ? new MemberProfileResource($profile) : null,
        ];
    }

    /**
     * Contagens das avaliações recebidas pelo perfil e feitas por ele, por estado.
     * Sem perfil carregado devolve zeros nos dois lados.
     *
     * @return array{received: array{total: int, hidden: int, flagged: int}, given: array{total: int, hidden: int, flagged: int}}
     */
    private function reviewCounts(): array
    {
        $profile = $this->memberProfile;

        return [
            'received' => $this->countsSide(
                (int) $profile?->reviews_received_count,
                (int) $profile?->reviews_received_hidden_count,
                (int) $profile?->reviews_received_flagged_count,
            ),
            'given' => $this->countsSide(
                (int) $profile?->reviews_given_count,
                (int) $profile?->reviews_given_hidden_count,
                (int) $profile?->reviews_given_flagged_count,
            ),
        ];
    }

    /**
     * Monta um lado das contagens de avaliações.
     *
     * @param  int  $total  Avaliações em todos os estados.
     * @param  int  $hidden  Avaliações ocultas.
     * @param  int  $flagged  Avaliações sinalizadas.
     * @return array{total: int, hidden: int, flagged: int}
     */
    private function countsSide(int $total, int $hidden, int $flagged): array
    {
        return [
            'total' => $total,
            'hidden' => $hidden,
            'flagged' => $flagged,
        ];
    }
}
