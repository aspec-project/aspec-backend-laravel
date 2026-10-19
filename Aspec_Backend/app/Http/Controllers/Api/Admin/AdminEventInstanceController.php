<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\EventInstanceRequest;
use App\Http\Resources\EventInstanceResource;
use App\Models\EventInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminEventInstanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'between:1,10000'],
        ]);

        $events = EventInstance::query()
            ->with(['category', 'location'])
            ->orderBy('event_date')
            ->orderBy('event_start_time')
            ->paginate(15);

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
        ], 'Lista de eventos obtida com sucesso.');
    }

    public function store(EventInstanceRequest $request): JsonResponse
    {
        $event = EventInstance::create($request->validated());
        $event->load(['category', 'location']);

        return $this->successResponse(
            new EventInstanceResource($event),
            'Evento criado com sucesso.',
            Response::HTTP_CREATED
        );
    }

    public function show(EventInstance $eventInstance): JsonResponse
    {
        $eventInstance->load(['category', 'location']);

        return $this->successResponse(
            new EventInstanceResource($eventInstance),
            'Evento obtido com sucesso.'
        );
    }

    public function update(
        EventInstanceRequest $request,
        EventInstance $eventInstance
    ): JsonResponse {
        $validated = $request->validated();

        if ($validated['is_online']) {
            $validated['address'] = null;
            $validated['latitude'] = null;
            $validated['longitude'] = null;
        } else {
            $validated['online_url'] = null;
        }

        $eventInstance->update($validated);
        $eventInstance->load(['category', 'location']);

        return $this->successResponse(
            new EventInstanceResource($eventInstance),
            'Evento atualizado com sucesso.'
        );
    }

    public function destroy(EventInstance $eventInstance): JsonResponse
    {
        $eventInstance->delete();

        return $this->successResponse(
            null,
            'Evento eliminado com sucesso.'
        );
    }
}
