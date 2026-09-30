<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemberProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}
