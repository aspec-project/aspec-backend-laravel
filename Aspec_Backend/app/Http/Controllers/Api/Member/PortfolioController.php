<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePortfolioRequest;
use App\Http\Resources\PortfolioResource;
use App\Models\MemberProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PortfolioController extends Controller
{
    private const MAX_IMAGES = 10;

    /**
     * Adiciona uma imagem ao portfólio do membro autenticado (máximo de 10).
     * O ficheiro fica em portfolios/{user_id} no disco public, com nome gerado; é apagado se a BD falhar.
     *
     * @throws Throwable quando a escrita na BD falha (resposta 500 pelo handler global)
     */
    public function store(StorePortfolioRequest $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->memberProfile;

        if (! $profile) {
            return $this->errorResponse('Perfil de membro não encontrado.', Response::HTTP_NOT_FOUND);
        }

        $path = null;

        try {
            $image = DB::transaction(function () use ($request, $user, $profile, &$path) {
                // Bloqueia a linha do perfil: dois uploads em simultâneo esperam um pelo outro e não passam ambos a contagem.
                MemberProfile::whereKey($profile->id)->lockForUpdate()->first();

                if ($profile->portfolios()->count() >= self::MAX_IMAGES) {
                    return null;
                }

                $path = $request->file('image')->store("portfolios/{$user->id}", 'public');

                return $profile->portfolios()->create(['image_path' => $path]);
            });
        } catch (Throwable $e) {
            // Rollback da BD não apaga ficheiros: sem isto ficaria um ficheiro órfão no disco.
            if ($path) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }

        if (! $image) {
            return $this->errorResponse('O portfólio já tem o máximo de 10 imagens.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->successResponse(
            new PortfolioResource($image),
            'Imagem adicionada ao portfólio.',
            Response::HTTP_CREATED
        );
    }

    /**
     * Remove uma imagem do portfólio do membro autenticado (linha e ficheiro).
     * A pesquisa é feita só nas imagens do próprio perfil: a de outro membro dá 404, sem revelar que existe.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $profile = $request->user()->memberProfile;

        if (! $profile) {
            return $this->errorResponse('Perfil de membro não encontrado.', Response::HTTP_NOT_FOUND);
        }

        // Um id que não é UUID nunca existe; evita levar à BD um valor inválido para a coluna.
        $image = Str::isUuid($id) ? $profile->portfolios()->find($id) : null;

        if (! $image) {
            return $this->errorResponse('Imagem não encontrada.', Response::HTTP_NOT_FOUND);
        }

        $image->delete();
        Storage::disk('public')->delete($image->image_path);

        return $this->successResponse(null, 'Imagem removida do portfólio.', Response::HTTP_OK);
    }
}
