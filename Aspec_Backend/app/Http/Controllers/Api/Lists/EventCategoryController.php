<?php

namespace App\Http\Controllers\Api\Lists;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventCategoryResource;
use App\Models\EventCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lista pública das categorias de eventos (filtros da agenda e formulários).
 */
class EventCategoryController extends Controller
{
    /**
     * Lista todas as categorias, ordenadas por nome e sem paginação.
     *
     * @param  Request  $request  Pedido HTTP atual.
     * @return JsonResponse 200 com a lista de categorias.
     */
    public function index(Request $request): JsonResponse
    {
        $categories = EventCategory::query()
            ->orderBy('name')
            ->get();

        return $this->successResponse(
            EventCategoryResource::collection($categories)->resolve($request),
            'Categorias de eventos obtidas com sucesso.'
        );
    }
}
