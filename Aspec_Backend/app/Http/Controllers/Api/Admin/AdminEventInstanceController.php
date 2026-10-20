<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\EventInstanceRequest;
use App\Http\Resources\EventInstanceResource;
use App\Models\EventInstance;
use App\Notifications\EventCancelledNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
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

        return DB::transaction(function () use ($validated, $eventInstance): JsonResponse {
            $event = EventInstance::query()
                ->whereKey($eventInstance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $registrationCount = $event->registrations()->count();

            if ((int) $validated['max_attendees'] < $registrationCount) {
                throw ValidationException::withMessages([
                    'max_attendees' => 'A lotação máxima não pode ser inferior ao número de inscrições existentes.',
                ]);
            }

            if ($validated['is_online']) {
                $validated['address'] = null;
                $validated['latitude'] = null;
                $validated['longitude'] = null;
            } else {
                $validated['online_url'] = null;
            }

            $event->update($validated);
            $event->load(['category', 'location']);

            return $this->successResponse(
                new EventInstanceResource($event),
                'Evento atualizado com sucesso.'
            );
        });
    }

    public function destroy(EventInstance $eventInstance): JsonResponse
    {
        DB::transaction(function () use ($eventInstance): void {
            $event = EventInstance::query()
                ->whereKey($eventInstance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $registrations = $event->registrations()
                ->with('user')
                ->get();

            $recipients = $registrations
                ->pluck('user')
                ->filter()
                ->values();

            $notification = new EventCancelledNotification(
                $event->name,
                $event->event_date->format('d/m/Y'),
                substr((string) $event->event_start_time, 0, 5),
            );

            $event->registrations()->delete();
            $event->delete();

            Notification::send($recipients, $notification);
        });

        return $this->successResponse(
            null,
            'Evento eliminado com sucesso.'
        );
    }
}
