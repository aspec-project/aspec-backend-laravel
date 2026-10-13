<?php

namespace App\Http\Controllers\Api\Directory;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexMemberRequest;
use App\Http\Resources\MemberSummaryResource;
use App\Models\MemberProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class MemberController extends Controller
{
    /**
     * Lista pública e paginada do diretório (só contas Active com perfil), igual para visitantes e membros.
     * Filtra por texto (nome, empresa ou descrição), setor, distrito e avaliação mínima;
     * ordena por empresa, nome e id para a paginação ser estável.
     */
    public function index(IndexMemberRequest $request): JsonResponse
    {
        $members = MemberProfile::listedInDirectory()
            ->with(['sector:id,name', 'location:id,name'])
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                // O ! é o caráter de escape porque escreve-se igual em MySQL e SQLite (a \ não).
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $request->input('search')).'%';

                // Agrupado: um orWhere solto escapava ao scope e devolvia contas que não estão Active.
                $query->where(fn (Builder $text) => $text
                    ->whereRaw("name LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("business_name LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("description LIKE ? ESCAPE '!'", [$term]));
            })
            ->when($request->filled('sector_id'), fn (Builder $query) => $query->where('sector_id', $request->input('sector_id')))
            ->when($request->filled('location_id'), fn (Builder $query) => $query->where('location_id', $request->input('location_id')))
            ->when($request->filled('min_rating'), fn (Builder $query) => $query->withMinAverageRating($request->float('min_rating')))
            ->orderBy('business_name')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 12));

        return $this->successResponse([
            'items' => MemberSummaryResource::collection($members->getCollection())->resolve($request),
            'pagination' => [
                'current_page' => $members->currentPage(),
                'per_page' => $members->perPage(),
                'total' => $members->total(),
                'last_page' => $members->lastPage(),
            ],
        ], 'Membros obtidos com sucesso.', Response::HTTP_OK);
    }
}
