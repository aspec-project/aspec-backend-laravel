<?php

namespace App\Http\Controllers\Api\Admin;

use App\Contracts\Geocoder;
use App\Exceptions\GeocodingUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\EventInstanceRequest;
use App\Http\Resources\EventInstanceResource;
use App\Jobs\AnnounceNewEventJob;
use App\Models\EventInstance;
use App\Models\EventSeries;
use App\Models\Location;
use App\Notifications\EventCancelledNotification;
use App\Notifications\EventUpdatedNotification;
use App\Services\Events\EventSeriesSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AdminEventInstanceController extends Controller
{
    private const PAST_EVENT_MESSAGE = 'Não é possível alterar um evento que já decorreu.';

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'period' => ['sometimes', 'in:upcoming,past'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d'],
            'category_id' => ['sometimes', 'uuid', 'exists:event_categories,id'],
            'location_id' => ['sometimes', 'uuid', 'exists:locations,id'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
            'page' => ['nullable', 'integer', 'between:1,10000'],
        ]);

        if (
            isset($filters['date_from'], $filters['date_to'])
            && $filters['date_to'] < $filters['date_from']
        ) {
            throw ValidationException::withMessages([
                'date_to' => 'A data final tem de ser igual ou posterior à data inicial.',
            ]);
        }

        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $today = now($timezone)->toDateString();

        $filteredEvents = EventInstance::query()
            ->when(
                isset($filters['date_from']),
                fn ($query) => $query->whereDate('event_date', '>=', $filters['date_from'])
            )
            ->when(
                isset($filters['date_to']),
                fn ($query) => $query->whereDate('event_date', '<=', $filters['date_to'])
            )
            ->when(
                isset($filters['category_id']),
                fn ($query) => $query->where('category_id', $filters['category_id'])
            )
            ->when(
                isset($filters['location_id']),
                fn ($query) => $query->where('location_id', $filters['location_id'])
            );

        $counts = [
            'upcoming' => (clone $filteredEvents)
                ->whereDate('event_date', '>=', $today)
                ->count(),
            'past' => (clone $filteredEvents)
                ->whereDate('event_date', '<', $today)
                ->count(),
        ];

        $period = $filters['period'] ?? 'upcoming';

        $eventsQuery = (clone $filteredEvents)
            ->with(['category', 'location'])
            ->withCount('registrations');

        if ($period === 'past') {
            $eventsQuery
                ->whereDate('event_date', '<', $today)
                ->orderByDesc('event_date')
                ->orderByDesc('event_start_time');
        } else {
            $eventsQuery
                ->whereDate('event_date', '>=', $today)
                ->orderBy('event_date')
                ->orderBy('event_start_time');
        }

        $events = $eventsQuery->paginate((int) ($filters['per_page'] ?? 6));

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
            'counts' => $counts,
        ], 'Lista de eventos obtida com sucesso.');
    }

    private function eventHasPassed(EventInstance $event): bool
    {
        $today = now(config('app.event_timezone', 'Europe/Lisbon'))
            ->toDateString();

        return $event->event_date->toDateString() < $today;
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

    /**
     * Armazena um novo evento ou série de eventos.
     */
    public function store(EventInstanceRequest $request, Geocoder $geocoder): JsonResponse
    {
        $validated = $request->validatedEventData();
        $recurrence = $validated['recurrence'] ?? null;

        unset($validated['recurrence']);

        $geocodingError = $this->geocodeMissingCoordinates($validated, $geocoder);

        if ($geocodingError !== null) {
            return $geocodingError;
        }

        if ($recurrence === null) {
            $event = EventInstance::create($validated);
            AnnounceNewEventJob::dispatch($event->id);
            $event->load(['category', 'location']);

            return $this->successResponse(
                new EventInstanceResource($event),
                'Evento criado com sucesso.',
                Response::HTTP_CREATED
            );
        }

        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $deadlineDaysBefore = (int) $recurrence['registration_deadline_days_before'];

        $sessionDates = app(EventSeriesSchedule::class)->generate(
            $validated['event_date'],
            $recurrence['ends_on'],
            $recurrence['frequency']
        );

        $series = DB::transaction(function () use (
            $validated,
            $recurrence,
            $sessionDates,
            $deadlineDaysBefore,
            $timezone
        ): EventSeries {
            $series = EventSeries::create([
                'frequency' => $recurrence['frequency'],
                'ends_on' => $recurrence['ends_on'],
                'registration_deadline_days_before' => $deadlineDaysBefore,
            ]);

            foreach ($sessionDates as $sessionDate) {
                $deadline = Carbon::createFromFormat(
                    '!Y-m-d',
                    $sessionDate,
                    $timezone
                )
                    ->subDays($deadlineDaysBefore)
                    ->setTime(23, 59);

                $sessionData = $validated;
                $sessionData['series_id'] = $series->id;
                $sessionData['event_date'] = $sessionDate;
                $sessionData['registration_deadline'] = $deadline->format('Y-m-d H:i:s');

                EventInstance::create($sessionData);
            }

            return $series;
        });

        $sessions = $series->eventInstances()
            ->with(['category', 'location'])
            ->orderBy('event_date')
            ->orderBy('event_start_time')
            ->get();

        return $this->successResponse([
            'series' => [
                'id' => $series->id,
                'frequency' => $series->frequency,
                'ends_on' => $series->ends_on->format('Y-m-d'),
                'registration_deadline_days_before' => $series->registration_deadline_days_before,
            ],
            'sessions' => EventInstanceResource::collection($sessions)->resolve($request),
        ], 'Série de eventos criada com sucesso.', Response::HTTP_CREATED);
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
        if ($this->eventHasPassed($eventInstance)) {
            return $this->errorResponse(
                self::PAST_EVENT_MESSAGE,
                Response::HTTP_CONFLICT
            );
        }
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

            if ($this->eventHasPassed($event)) {
                return $this->errorResponse(
                    self::PAST_EVENT_MESSAGE,
                    Response::HTTP_CONFLICT
                );
            }

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
        $result = DB::transaction(function () use ($request, $eventInstance): ?JsonResponse {
            $event = EventInstance::query()
                ->whereKey($eventInstance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->eventHasPassed($event)) {
                return $this->errorResponse(
                    self::PAST_EVENT_MESSAGE,
                    Response::HTTP_CONFLICT
                );
            }

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

            return null;
        });

        if ($result !== null) {
            return $result;
        }

        return $this->successResponse(
            null,
            'Evento eliminado com sucesso.'
        );
    }
}
