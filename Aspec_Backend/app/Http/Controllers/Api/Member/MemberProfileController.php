<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMemberProfileRequest;
use App\Http\Resources\MemberProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class MemberProfileController extends Controller
{
    /**
     * Devolve o perfil do membro autenticado, com horário, redes sociais e portfólio.
     * Atua sempre sobre o próprio utilizador (sem id na rota); 404 se o user não tiver perfil (ex. admin).
     */
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->memberProfile;

        if (! $profile) {
            return $this->errorResponse('Perfil de membro não encontrado.', Response::HTTP_NOT_FOUND);
        }

        $profile->load(['user', 'sector', 'location', 'weekDays', 'socialPlatforms', 'portfolios']);

        return $this->successResponse(
            new MemberProfileResource($profile),
            'Perfil obtido com sucesso.',
            Response::HTTP_OK
        );
    }

    /**
     * Atualiza parcialmente o perfil do membro autenticado e os dados de conta (email, phone).
     * Campos ausentes ficam inalterados; business_hours e social_links, se enviados, substituem a lista atual (sync).
     * Campos sensíveis (role_id, account_status_id, password, ...) nunca são aceites.
     * Mudar o email fecha as outras sessões (tokens), mantém a atual e apaga os tokens de
     * reposição de password do email antigo (senão voltariam a valer se esse email voltasse a existir).
     */
    public function update(UpdateMemberProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->memberProfile;

        if (! $profile) {
            return $this->errorResponse('Perfil de membro não encontrado.', Response::HTTP_NOT_FOUND);
        }

        DB::transaction(function () use ($request, $user, $profile) {
            $profile->update($request->safe()->only([
                'name',
                'business_name',
                'congregation',
                'role_in_congregation',
                'sector_id',
                'location_id',
                'description',
                'website_url',
                'commercial_contacts',
                'address',
            ]));

            $oldEmail = $user->email;

            $user->update($request->safe()->only(['email', 'phone']));

            if ($user->wasChanged('email')) {
                $user->deletePasswordResetTokens($oldEmail);

                $currentToken = $user->currentAccessToken();

                $user->tokens()
                    ->when($currentToken instanceof PersonalAccessToken, fn ($query) => $query->whereKeyNot($currentToken->getKey()))
                    ->delete();
            }

            if ($request->has('business_hours')) {
                $profile->weekDays()->sync(
                    collect($request->validated('business_hours'))->mapWithKeys(fn ($slot) => [
                        $slot['week_day_id'] => [
                            'open_time' => $slot['open_time'],
                            'close_time' => $slot['close_time'],
                        ],
                    ])->all()
                );
            }

            if ($request->has('social_links')) {
                $profile->socialPlatforms()->sync(
                    collect($request->validated('social_links'))->mapWithKeys(fn ($link) => [
                        $link['platform_id'] => ['url' => $link['url']],
                    ])->all()
                );
            }
        });

        $profile->load(['user', 'sector', 'location', 'weekDays', 'socialPlatforms', 'portfolios']);

        return $this->successResponse(
            new MemberProfileResource($profile),
            'Perfil atualizado com sucesso.',
            Response::HTTP_OK
        );
    }
}
