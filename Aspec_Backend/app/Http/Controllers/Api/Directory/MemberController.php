<?php

namespace App\Http\Controllers\Api\Directory;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexMemberRequest;
use App\Http\Resources\MemberDetailResource;
use App\Http\Resources\MemberSummaryResource;
use App\Models\MemberProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MemberController extends Controller
{
    /**
     * Lista pública e paginada do diretório (só contas Active com perfil), igual para visitantes e membros.
     * Filtra por texto (nome, empresa ou descrição), setor, distrito e avaliação mínima;
     * ordena por empresa, nome e id para a paginação ser estável.
     * Cada item traz a média das avaliações Visible (1 casa decimal) e o número de avaliações Visible.
     * A média e a contagem são calculadas depois de paginar, numa única consulta só para os perfis da página:
     * no select da consulta paginada, as subconsultas corriam para todos os perfis antes do LIMIT.
     * Só é válido porque a ordenação não depende da média; o filtro min_rating continua no where, antes de paginar.
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
            ->paginate($request->validated('per_page') ?? 12);

        $this->loadRatingSummary($members->getCollection());

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

    /**
     * Copia average_rating e reviews_count para os perfis da página, com uma única consulta (nenhuma se a página estiver vazia).
     * Os atributos ficam como originais, tal como viriam do select, para o modelo não os considerar alterados.
     *
     * @param  Collection<int, MemberProfile>  $profiles
     */
    private function loadRatingSummary(Collection $profiles): void
    {
        if ($profiles->isEmpty()) {
            return;
        }

        $summaries = MemberProfile::query()
            ->select('member_profiles.id')
            ->whereKey($profiles->modelKeys())
            ->withRatingSummary()
            ->get()
            ->keyBy('id');

        foreach ($profiles as $profile) {
            $summary = $summaries->get($profile->id);

            $profile->forceFill([
                'average_rating' => $summary?->average_rating,
                'reviews_count' => $summary?->reviews_count ?? 0,
            ])->syncOriginalAttributes(['average_rating', 'reviews_count']);
        }
    }

    /**
     * Perfil público de um membro do diretório. Rota pública com utilizador opcional:
     * só quem consulta com conta Active (qualquer papel) recebe contactos comerciais, website, morada e redes sociais.
     * Perfil inexistente ou fora do diretório dá o mesmo 404, para não confirmar que o id existe.
     * A resposta depende de quem consulta, por isso nunca fica em cache partilhada (private, no-store).
     * Média e contagem das avaliações Visible iguais às da lista (mesmo scope e mesmo formato).
     */
    public function show(Request $request, string $member): JsonResponse
    {
        $profile = MemberProfile::listedInDirectory()
            ->withRatingSummary()
            ->with([
                'sector:id,name',
                'location:id,name',
                'weekDays',
                'socialPlatforms',
                'portfolios' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            ])
            ->find($member);

        if (! $profile) {
            return $this->errorResponse('Membro não encontrado.', Response::HTTP_NOT_FOUND);
        }

        // Guard sanctum explícito: sem auth:sanctum o guard por omissão é o web, que ignora tokens Bearer.
        $viewer = $request->user('sanctum');
        $showContacts = $viewer?->accountStatus?->name === 'Active';

        return $this->successResponse(
            new MemberDetailResource($profile, $showContacts),
            'Perfil do membro obtido com sucesso.',
            Response::HTTP_OK
        )->header('Cache-Control', 'private, no-store')
            ->header('Vary', 'Cookie, Authorization');
    }
}
