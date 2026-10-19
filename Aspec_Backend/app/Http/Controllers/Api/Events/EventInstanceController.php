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
     * Display a listing of the event instances.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category_id' => ['sometimes', 'uuid', 'exists:event_categories,id'],
            'location_id' => ['sometimes', 'uuid', 'exists:locations,id'],
            'page' => ['nullable', 'integer', 'between:1,10000'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $now = now(config('app.event_timezone', 'Europe/Lisbon'));
        $today = $now->toDateString();
        $currentTime = $now->format('H:i:s');
        $search = trim((string) ($filters['search'] ?? ''));

        $events = EventInstance::query()
            ->with(['category', 'location'])
            ->where(function (Builder $query) use ($today, $currentTime) {
                $query
                    ->where('event_date', '>', $today)
                    ->orWhere(function (Builder $query) use ($today, $currentTime) {
                        $query
                            ->where('event_date', $today)
                            ->where('event_start_time', '>=', $currentTime);
                    });
            })
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
    public function show(EventInstance $eventInstance): JsonResponse
    {
        $eventInstance->load(['category', 'location']);

        return $this->successResponse(
            new EventInstanceResource($eventInstance),
            'Evento obtido com sucesso.'
        );
    }
}
