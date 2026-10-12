<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberLogoRequest;
use App\Models\MemberProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MemberLogoController extends Controller
{
    /**
     * Grava o logótipo do membro autenticado em logos/{user_id} no disco public, substituindo o anterior.
     * Ordem: grava o novo, atualiza logo_path e só depois apaga o antigo; se a BD falhar, o antigo mantém-se.
     *
     * @throws Throwable quando a escrita na BD falha (resposta 500 pelo handler global)
     */
    public function store(StoreMemberLogoRequest $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->memberProfile;

        if (! $profile) {
            return $this->errorResponse('Perfil de membro não encontrado.', Response::HTTP_NOT_FOUND);
        }

        $oldPath = null;
        $newPath = null;

        try {
            DB::transaction(function () use ($request, $user, $profile, &$oldPath, &$newPath) {
                // Bloqueia a linha do perfil: dois uploads em simultâneo esperam um pelo outro e o caminho antigo lido é sempre o atual.
                $locked = MemberProfile::whereKey($profile->id)->lockForUpdate()->first();

                $oldPath = $locked->logo_path;
                $newPath = $request->file('logo')->store("logos/{$user->id}", 'public');

                $profile->update(['logo_path' => $newPath]);
            });
        } catch (Throwable $e) {
            // Rollback da BD não apaga ficheiros: sem isto ficaria um ficheiro órfão no disco.
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }

            throw $e;
        }

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return $this->successResponse(
            ['logo_url' => Storage::disk('public')->url($newPath)],
            'Logótipo atualizado com sucesso.',
            Response::HTTP_OK
        );
    }
}
