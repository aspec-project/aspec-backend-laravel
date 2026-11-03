<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePortfolioRequest;
use App\Http\Resources\PortfolioResource;
use App\Jobs\DeleteMediaJob;
use App\Models\MemberProfile;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PortfolioController extends Controller
{
    private const MAX_IMAGES = 10;

    /**
     * Adiciona uma imagem ao portfólio do membro autenticado (máximo de 10).
     * O ficheiro fica em portfolios/{profile_id} no disco de media (o id do perfil já é público; o do utilizador não),
     * com nome gerado; é apagado se a BD falhar.
     *
     * @throws Throwable quando a gravação do ficheiro ou a escrita na BD falham (resposta 500 pelo handler global)
     */
    public function store(StorePortfolioRequest $request): JsonResponse
    {
        $profile = $request->user()->memberProfile;

        if (! $profile) {
            return $this->errorResponse('Perfil de membro não encontrado.', Response::HTTP_NOT_FOUND);
        }

        $path = null;
        $profileGone = false;

        try {
            $image = DB::transaction(function () use ($request, $profile, &$path, &$profileGone) {
                // Bloqueia a linha do perfil: dois uploads em simultâneo esperam um pelo outro e não passam ambos a contagem.
                if (! MemberProfile::whereKey($profile->id)->lockForUpdate()->exists()) {
                    // Anonimizado entre o pedido e o bloqueio: não grava nada (a imagem ficaria pública).
                    $profileGone = true;

                    return null;
                }

                if ($profile->portfolios()->count() >= self::MAX_IMAGES) {
                    return null;
                }

                $path = MediaStorage::disk()->putFile("portfolios/{$profile->id}", $request->file('image'));

                // Com 'throw' => false o disco devolve false numa falha (ex. rede do R2) em vez de lançar.
                if ($path === false) {
                    throw new RuntimeException('Não foi possível gravar a imagem do portfólio no disco de media.');
                }

                return $profile->portfolios()->create(['image_path' => $path]);
            });
        } catch (Throwable $e) {
            // Rollback da BD não apaga ficheiros: sem isto ficaria um ficheiro órfão no disco.
            if ($path) {
                MediaStorage::disk()->delete($path);
            }

            throw $e;
        }

        if ($profileGone) {
            return $this->errorResponse('Perfil de membro não encontrado.', Response::HTTP_NOT_FOUND);
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
     * Bloqueia o perfil, como o upload e a anonimização, para não correr ao mesmo tempo que eles.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $profile = $request->user()->memberProfile;

        if (! $profile) {
            return $this->errorResponse('Perfil de membro não encontrado.', Response::HTTP_NOT_FOUND);
        }

        // Um id que não é UUID nunca existe; evita levar à BD um valor inválido para a coluna.
        $image = ! Str::isUuid($id) ? null : DB::transaction(function () use ($profile, $id) {
            if (! MemberProfile::whereKey($profile->id)->lockForUpdate()->exists()) {
                return null;
            }

            $image = $profile->portfolios()->find($id);
            $image?->delete();

            return $image;
        });

        if (! $image) {
            return $this->errorResponse('Imagem não encontrada.', Response::HTTP_NOT_FOUND);
        }

        rescue(fn () => DeleteMediaJob::dispatch($image->image_path), report: true);

        return $this->successResponse(null, 'Imagem removida do portfólio.', Response::HTTP_OK);
    }
}
