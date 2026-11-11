<?php

namespace App\Http\Controllers\Api\Events;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventInstanceResource;
use App\Models\EventInstance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventInstanceController extends Controller
{
    /**
     * Agenda pública: eventos que ainda não começaram (scope notStarted, hora de Lisboa) ou, com series_id,
     * todas as sessões da série, com filtros, pesquisa e paginação.
     *
     * @param  Request  $request  Pedido com category_id, location_id, series_id, search, page e per_page.
     * @return JsonResponse 200 com items e pagination, ou 422 se os filtros forem inválidos.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category_id' => ['sometimes', 'uuid', 'exists:event_categories,id'],
            'location_id' => ['sometimes', 'uuid', 'exists:locations,id'],
            'series_id' => ['sometimes', 'uuid', 'exists:event_series,id'],
            'page' => ['nullable', 'integer', 'between:1,10000'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $viewerId = $request->user('sanctum')?->getAuthIdentifier();

        $events = EventInstance::query()
            ->with(['category', 'location'])
            ->withCount('registrations')
            ->when(
                $viewerId !== null,
                fn (Builder $query) => $query->withExists([
                    'registrations as is_registered' => fn (Builder $registrationQuery) => $registrationQuery
                        ->where('user_id', $viewerId),
                ])
            )
            ->when(
                // Mostrar apenas eventos futuros quando não é indicado o parâmetro series_id
                ! isset($filters['series_id']),
                fn (Builder $query) => $query->notStarted()
            )
            // Filtrar pelo series_id quando este é indicado
            ->when(
                isset($filters['series_id']),
                fn (Builder $query) => $query->where('series_id', $filters['series_id'])
            )
            ->when(
                isset($filters['category_id']),
                fn (Builder $query) => $query->where('category_id', $filters['category_id'])
            )
            ->when(
                isset($filters['location_id']),
                fn (Builder $query) => $query->where('location_id', $filters['location_id'])
            )
            ->when($search !== '', function (Builder $query) use ($search) {
                $term = '%'.$search.'%';

                $query->where(function (Builder $query) use ($term) {
                    $query
                        ->where('name', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhereHas(
                            'category',
                            fn (Builder $categoryQuery) => $categoryQuery->where('name', 'like', $term)
                        )
                        ->orWhereHas(
                            'location',
                            fn (Builder $locationQuery) => $locationQuery->where('name', 'like', $term)
                        );
                });
            })
            ->orderBy('event_date')
            ->orderBy('event_start_time')
            ->paginate((int) ($filters['per_page'] ?? 9));

        if ($viewerId === null) {
            $events->getCollection()->each(
                fn (EventInstance $event) => $event->setAttribute('is_registered', false)
            );
        }

        return $this->successResponse([
            'items' => EventInstanceResource::collection(
                $events->getCollection()
            )->resolve($request),
            'pagination' => [
                'current_page' => $events->currentPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'last_page' => $events->lastPage(),
            ],
        ], 'Agenda de eventos obtida com sucesso.');
    }

    /**
     * Display the specified event instance.
     */
    public function show(Request $request, EventInstance $eventInstance): JsonResponse
    {
        $viewerId = $request->user('sanctum')?->getAuthIdentifier();

        $eventInstance->load(['category', 'location']);
        $eventInstance->loadCount('registrations');
        $eventInstance->setAttribute(
            'is_registered',
            $viewerId !== null
                && $eventInstance->registrations()->where('user_id', $viewerId)->exists()
        );

        return $this->successResponse(
            new EventInstanceResource($eventInstance),
            'Evento obtido com sucesso.'
        );
    }
}
