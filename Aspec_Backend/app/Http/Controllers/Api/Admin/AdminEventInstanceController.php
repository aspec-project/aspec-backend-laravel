<?php

namespace App\Http\Controllers\Api\Admin;

use App\Contracts\Geocoder;
use App\Exceptions\GeocodingUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\EventInstanceRequest;
use App\Http\Resources\EventInstanceResource;
use App\Models\EventInstance;
use App\Models\Location;
use App\Notifications\EventCancelledNotification;
use App\Notifications\EventUpdatedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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

    /**
     * Geocodifica eventos presenciais quando as coordenadas não foram enviadas.
     *
     * @param  array<string, mixed>  $validated
     */
    private function geocodeMissingCoordinates(array &$validated, Geocoder $geocoder): ?JsonResponse
    {
        if ((bool) $validated['is_online']) {
            return null;
        }

        if (isset($validated['latitude'], $validated['longitude'])) {
            return null;
        }

        $locationName = Location::query()
            ->whereKey($validated['location_id'])
            ->value('name');

        $query = Str::squish($validated['address'].', '.$locationName);

        try {
            $result = $geocoder->geocode($query);
        } catch (GeocodingUnavailable) {
            return $this->errorResponse(
                'O serviço de localização não está disponível de momento. Tente novamente dentro de instantes.',
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        if ($result === null) {
            return $this->errorResponse(
                'Não foi possível encontrar esta morada no mapa. Verifique a morada e tente novamente.',
                Response::HTTP_NOT_FOUND
            );
        }

        $validated['latitude'] = $result->latitude;
        $validated['longitude'] = $result->longitude;

        return null;
    }

    public function store(EventInstanceRequest $request, Geocoder $geocoder): JsonResponse
    {
        $validated = $request->validatedEventData();

        $geocodingError = $this->geocodeMissingCoordinates($validated, $geocoder);

        if ($geocodingError !== null) {
            return $geocodingError;
        }

        $event = EventInstance::create($validated);
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
        EventInstance $eventInstance,
        Geocoder $geocoder
    ): JsonResponse {
        $validated = $request->validatedEventData();

        $justification = $validated['justification'] ?? null;
        unset($validated['justification']);

        $geocodingError = $this->geocodeMissingCoordinates($validated, $geocoder);

        if ($geocodingError !== null) {
            return $geocodingError;
        }

        return DB::transaction(function () use (
            $validated,
            $eventInstance,
            $justification
        ): JsonResponse {
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

            $recipients = $event->registrations()
                ->with('user')
                ->get()
                ->pluck('user')
                ->filter()
                ->values();

            if ($validated['is_online']) {
                $validated['address'] = null;
                $validated['latitude'] = null;
                $validated['longitude'] = null;
            } else {
                $validated['online_url'] = null;
            }

            $event->update($validated);

            if ($recipients->isNotEmpty()) {
                Notification::send(
                    $recipients,
                    new EventUpdatedNotification($event->name, $justification)
                );
            }

            $event->load(['category', 'location']);

            return $this->successResponse(
                new EventInstanceResource($event),
                'Evento atualizado com sucesso.'
            );
        });
    }

    public function destroy(Request $request, EventInstance $eventInstance): JsonResponse
    {
        DB::transaction(function () use ($request, $eventInstance): void {
            $event = EventInstance::query()
                ->whereKey($eventInstance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $registrations = $event->registrations()
                ->with('user')
                ->get();

            $validated = $request->validate([
                'justification' => $registrations->isNotEmpty()
                    ? ['required', 'string', 'max:2000']
                    : ['sometimes', 'nullable', 'string', 'max:2000'],
            ]);

            $justification = $validated['justification'] ?? null;

            $recipients = $registrations
                ->pluck('user')
                ->filter()
                ->values();

            $notification = new EventCancelledNotification(
                $event->name,
                $event->event_date->format('d/m/Y'),
                substr((string) $event->event_start_time, 0, 5),
                $justification,
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
