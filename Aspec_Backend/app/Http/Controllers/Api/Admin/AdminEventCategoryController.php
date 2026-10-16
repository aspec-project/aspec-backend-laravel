<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\EventCategoryRequest;
use App\Http\Resources\EventCategoryResource;
use App\Models\EventCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gestão das categorias de eventos no backoffice (só administradores ativos).
 */
class AdminEventCategoryController extends Controller
{
    /**
     * Lista todas as categorias com o número de eventos de cada uma, ordenadas por nome e sem paginação.
     *
     * @param Request $request Pedido HTTP atual.
     * @return JsonResponse 200 com a lista de categorias.
     */
    public function index(Request $request): JsonResponse
    {
        $categories = EventCategory::query()
            ->withCount('eventInstances')
            ->orderBy('name')
            ->get();

        return $this->successResponse(
            EventCategoryResource::collection($categories)->resolve($request),
            'Lista de categorias obtida com sucesso.'
        );
    }

    /**
     * Cria uma categoria só com os campos validados (nome e cor) e devolve-a com o número de eventos (0).
     *
     * @param EventCategoryRequest $request Pedido com o nome e a cor.
     * @return JsonResponse 201 com a categoria criada.
     */
    public function store(EventCategoryRequest $request): JsonResponse
    {
        $category = EventCategory::create($request->validated());
        $category->loadCount('eventInstances');

        return $this->successResponse(
            new EventCategoryResource($category),
            'Categoria criada com sucesso.',
            Response::HTTP_CREATED
        );
    }

    /**
     * Atualiza o nome e a cor de uma categoria e devolve-a com o número de eventos.
     *
     * @param EventCategoryRequest $request Pedido com o nome e a cor.
     * @param EventCategory $eventCategory Categoria obtida pelo id da rota.
     * @return JsonResponse 200 com a categoria atualizada.
     */
    public function update(EventCategoryRequest $request, EventCategory $eventCategory): JsonResponse
    {
        $eventCategory->update($request->validated());
        $eventCategory->loadCount('eventInstances');

        return $this->successResponse(
            new EventCategoryResource($eventCategory),
            'Categoria atualizada com sucesso.'
        );
    }

    /**
     * Elimina uma categoria, desde que não tenha eventos associados.
     *
     * @param EventCategory $eventCategory Categoria obtida pelo id da rota.
     * @return JsonResponse 200 com data a null, ou 409 se a categoria tiver eventos.
     */
    public function destroy(EventCategory $eventCategory): JsonResponse
    {
        if ($eventCategory->hasEvents()) {
            return $this->errorResponse(
                'Não é possível eliminar uma categoria com eventos associados.',
                Response::HTTP_CONFLICT
            );
        }

        $eventCategory->delete();

        return $this->successResponse(null, 'Categoria eliminada com sucesso.');
    }
}
