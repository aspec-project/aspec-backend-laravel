<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberLogoRequest;
use App\Jobs\DeleteMediaJob;
use App\Models\MemberProfile;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MemberLogoController extends Controller
{
    /**
     * Grava o logótipo do membro autenticado em logos/{profile_id} no disco de media, substituindo o anterior.
     * A pasta usa o id do perfil (já público no diretório) para o URL não revelar o id do utilizador.
     * Ordem: grava o novo, atualiza logo_path e só depois apaga o antigo; se a gravação ou a BD falharem, o antigo mantém-se.
     *
     * @throws Throwable quando a gravação do ficheiro ou a escrita na BD falham (resposta 500 pelo handler global)
     */
    public function store(StoreMemberLogoRequest $request): JsonResponse
    {
        $profile = $request->user()->memberProfile;

        if (! $profile) {
            return $this->errorResponse('Perfil de membro não encontrado.', Response::HTTP_NOT_FOUND);
        }

        $oldPath = null;
        $newPath = null;

        try {
            $found = DB::transaction(function () use ($request, $profile, &$oldPath, &$newPath) {
                // Bloqueia a linha do perfil: dois uploads em simultâneo esperam um pelo outro e o caminho antigo lido é sempre o atual.
                $locked = MemberProfile::whereKey($profile->id)->lockForUpdate()->first();

                // Anonimizado entre o pedido e o bloqueio: não grava nada (a imagem ficaria pública).
                if (! $locked) {
                    return false;
                }

                $oldPath = $locked->logo_path;
                $newPath = MediaStorage::disk()->putFile("logos/{$profile->id}", $request->file('logo'));

                // Com 'throw' => false o disco devolve false numa falha (ex. rede do R2) em vez de lançar.
                if ($newPath === false) {
                    throw new RuntimeException('Não foi possível gravar o logótipo no disco de media.');
                }

                $profile->update(['logo_path' => $newPath]);

                return true;
            });
        } catch (Throwable $e) {
            // Rollback da BD não apaga ficheiros: sem isto ficaria um ficheiro órfão no disco.
            if ($newPath) {
                MediaStorage::disk()->delete($newPath);
            }

            throw $e;
        }

        if (! $found) {
            return $this->errorResponse('Perfil de membro não encontrado.', Response::HTTP_NOT_FOUND);
        }

        if ($oldPath) {
            rescue(fn () => DeleteMediaJob::dispatch($oldPath), report: true);
        }

        return $this->successResponse(
            ['logo_url' => MediaStorage::url($newPath)],
            'Logótipo atualizado com sucesso.',
            Response::HTTP_OK
        );
    }
}
