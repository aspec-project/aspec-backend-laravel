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
    private const PAST_EVENT_MESSAGE = 'Não é possível alterar um evento que já começou.';

    private const PRAYER_CARDS_LOCK_MESSAGE = 'Não é possível alterar os cartões de oração, a data ou a hora depois de existirem cartões preenchidos.';

    /**
     * Campos que ficam bloqueados depois do 1.º cartão de oração: o que o autor viu (versículo, prazo)
     * é o que o destinatário vê.
     */
    private const PRAYER_CARDS_LOCKED_FIELDS = [
        'prayer_cards_enabled',
        'prayer_verse',
        'event_date',
        'event_start_time',
    ];

    private const ATTENDEE_RELEVANT_FIELDS = [
        'name',
        'category_id',
        'event_date',
        'event_start_time',
        'event_end_time',
        'description',
        'location_id',
        'address',
        'venue_name',
        'latitude',
        'longitude',
        'is_online',
        'online_url',
        'registration_deadline',
        'place_id',
    ];

    /**
     * Lista os eventos do backoffice com filtros e paginação. "upcoming" (Agendados) são os eventos que
     * ainda não começaram e "past" (Passados) os já começados, a decorrer ou terminados (hora de Lisboa);
     * as contagens dos dois separadores seguem a mesma regra e respeitam os filtros.
     *
     * @param  Request  $request  Pedido com period, date_from, date_to, category_id, location_id, page e per_page.
     * @return JsonResponse 200 com items, pagination e counts, ou 422 se os filtros forem inválidos.
     */
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
                ->notStarted()
                ->count(),
            'past' => (clone $filteredEvents)
                ->started()
                ->count(),
        ];

        $period = $filters['period'] ?? 'upcoming';

        $eventsQuery = (clone $filteredEvents)
            ->with(['category', 'location'])
            ->withCount(['registrations', 'prayerCards'])
            ->withSeriesPosition();

        if ($period === 'past') {
            $eventsQuery
                ->started()
                ->orderByDesc('event_date')
                ->orderByDesc('event_start_time');
        } else {
            $eventsQuery
                ->notStarted()
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

    /**
     * @param  array<string, mixed>  $validated
     */
    private function hasAttendeeRelevantChanges(EventInstance $event, array $validated): bool
    {
        foreach (self::ATTENDEE_RELEVANT_FIELDS as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $original = $event->getRawOriginal($field);
            $updated = $validated[$field];

            if (
                $this->normalizeAttendeeRelevantValue($field, $original)
                !== $this->normalizeAttendeeRelevantValue($field, $updated)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indica se o pedido muda a ativação, o versículo, a data ou a hora de início de um evento que já
     * tem cartões de oração (os retirados e os expirados não contam).
     *
     * @param  array<string, mixed>  $validated
     */
    private function changesLockedPrayerCardFields(EventInstance $event, array $validated): bool
    {
        if (! $event->prayerCards()->exists()) {
            return false;
        }

        foreach (self::PRAYER_CARDS_LOCKED_FIELDS as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $original = $field === 'prayer_cards_enabled'
                ? (bool) $event->getRawOriginal($field)
                : $this->normalizeAttendeeRelevantValue($field, $event->getRawOriginal($field));
            $updated = $field === 'prayer_cards_enabled'
                ? (bool) $validated[$field]
                : $this->normalizeAttendeeRelevantValue($field, $validated[$field]);

            if ($original !== $updated) {
                return true;
            }
        }

        return false;
    }

    private function normalizeAttendeeRelevantValue(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $timezone = config('app.event_timezone', 'Europe/Lisbon');

        return match ($field) {
            'event_date' => Carbon::parse($value, $timezone)->toDateString(),
            'event_start_time', 'event_end_time' => substr((string) $value, 0, 5),
            'registration_deadline' => Carbon::parse($value, $timezone)->format('Y-m-d H:i:s'),
            'latitude', 'longitude' => number_format((float) $value, 7, '.', ''),
            'is_online' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $value,
        };
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

        if (($validated['place_id'] ?? null) !== null) {
            $validated['latitude'] = null;
            $validated['longitude'] = null;

            return null;
        }

        if (isset($validated['latitude'], $validated['longitude'])) {
            $validated['place_id'] = null;

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
        $validated['place_id'] = $result->placeId;

        return null;
    }

    /**
     * Armazena um novo evento ou série de eventos. Nas séries, o prazo de cada sessão é calculado por
     * EventSeriesSchedule::registrationDeadline (N dias antes, às 23:59 de Lisboa).
     *
     * @param  EventInstanceRequest  $request  Pedido validado com os dados do evento (e recurrence nas séries).
     * @param  Geocoder  $geocoder  Serviço que localiza a morada quando não são enviadas coordenadas.
     * @return JsonResponse 201 com o evento ou com a série e as sessões, 422 ou 503 (geocodificação indisponível).
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

            return $this->successResponse(
                new EventInstanceResource($this->findForAdmin($event->id)),
                'Evento criado com sucesso.',
                Response::HTTP_CREATED
            );
        }

        $deadlineDaysBefore = (int) $recurrence['registration_deadline_days_before'];
        $schedule = app(EventSeriesSchedule::class);

        $sessionDates = $schedule->generate(
            $validated['event_date'],
            $recurrence['ends_on'],
            $recurrence['frequency']
        );

        $series = DB::transaction(function () use (
            $validated,
            $recurrence,
            $sessionDates,
            $deadlineDaysBefore,
            $schedule
        ): EventSeries {
            $series = EventSeries::create([
                'frequency' => $recurrence['frequency'],
                'ends_on' => $recurrence['ends_on'],
                'registration_deadline_days_before' => $deadlineDaysBefore,
            ]);

            foreach ($sessionDates as $sessionDate) {
                $deadline = $schedule->registrationDeadline($sessionDate, $deadlineDaysBefore);

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
            ->withSeriesPosition()
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
        return $this->successResponse(
            new EventInstanceResource($this->findForAdmin($eventInstance->id)),
            'Evento obtido com sucesso.'
        );
    }

    /**
     * Lê o evento como o backoffice o mostra: categoria, localização, contagens de inscrições e de cartões
     * de oração e a posição na série ("Sessão X/Y"), numa só consulta e num modelo novo, só para a resposta.
     *
     * @param  string  $id  Id (UUID) do evento.
     * @return EventInstance Evento com as relações e as contagens carregadas.
     */
    private function findForAdmin(string $id): EventInstance
    {
        return EventInstance::with(['category', 'location'])
            ->withCount(['registrations', 'prayerCards'])
            ->withSeriesPosition()
            ->findOrFail($id);
    }

    /**
     * Atualiza um evento que ainda não começou e avisa por email os inscritos ativos quando mudam dados
     * que lhes interessam. O início é verificado antes e outra vez dentro da transação, com o evento trancado.
     *
     * @param  EventInstanceRequest  $request  Pedido validado com os dados completos do evento.
     * @param  EventInstance  $eventInstance  Evento a atualizar.
     * @param  Geocoder  $geocoder  Serviço que localiza a morada quando não são enviadas coordenadas.
     * @return JsonResponse 200 com o evento, 409 se já começou, 422 ou 503 (geocodificação indisponível).
     */
    public function update(
        EventInstanceRequest $request,
        EventInstance $eventInstance,
        Geocoder $geocoder
    ): JsonResponse {
        if ($eventInstance->hasStarted()) {
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

            if ($event->hasStarted()) {
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

            if ($this->changesLockedPrayerCardFields($event, $validated)) {
                throw ValidationException::withMessages([
                    'prayer_verse' => self::PRAYER_CARDS_LOCK_MESSAGE,
                ]);
            }

            $validated['event_start_time'] .= ':00';
            $validated['event_end_time'] .= ':00';

            foreach (['latitude', 'longitude'] as $coordinate) {
                if (isset($validated[$coordinate])) {
                    $validated[$coordinate] = number_format(
                        (float) $validated[$coordinate],
                        7,
                        '.',
                        ''
                    );
                }
            }

            if ($validated['is_online']) {
                $validated['address'] = null;
                $validated['latitude'] = null;
                $validated['longitude'] = null;
                $validated['place_id'] = null;
            } else {
                $validated['online_url'] = null;
            }

            $hasAttendeeRelevantChanges = $this->hasAttendeeRelevantChanges(
                $event,
                $validated
            );

            $event->update($validated);

            if ($hasAttendeeRelevantChanges) {
                $recipients = $event->registrations()
                    ->whereHas(
                        'user.accountStatus',
                        fn ($query) => $query->where('name', 'Active')
                    )
                    ->with('user')
                    ->get()
                    ->pluck('user')
                    ->filter()
                    ->values();

                if ($recipients->isNotEmpty()) {
                    Notification::send(
                        $recipients,
                        new EventUpdatedNotification($event->name, $justification)
                    );
                }
            }

            return $this->successResponse(
                new EventInstanceResource($this->findForAdmin($event->id)),
                'Evento atualizado com sucesso.'
            );
        });
    }

    /**
     * Apaga um evento que ainda não começou, com as inscrições, e avisa por email os inscritos ativos.
     * A justificação é obrigatória quando há inscritos.
     *
     * @param  Request  $request  Pedido com a justificação opcional ou obrigatória.
     * @param  EventInstance  $eventInstance  Evento a apagar.
     * @return JsonResponse 200 se foi apagado, 409 se já começou ou 422 (justificação).
     */
    public function destroy(Request $request, EventInstance $eventInstance): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $eventInstance): ?JsonResponse {
            $event = EventInstance::query()
                ->whereKey($eventInstance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($event->hasStarted()) {
                return $this->errorResponse(
                    self::PAST_EVENT_MESSAGE,
                    Response::HTTP_CONFLICT
                );
            }

            $registrations = $event->registrations()
                ->with('user.accountStatus')
                ->get();

            $validated = $request->validate([
                'justification' => $registrations->isNotEmpty()
                    ? ['required', 'string', 'max:2000']
                    : ['sometimes', 'nullable', 'string', 'max:2000'],
            ]);

            $justification = $validated['justification'] ?? null;

            $recipients = $registrations
                ->pluck('user')
                ->filter(fn ($user) => $user?->accountStatus?->name === 'Active')
                ->values();

            $notification = new EventCancelledNotification(
                $event->name,
                $event->event_date->format('d/m/Y'),
                substr((string) $event->event_start_time, 0, 5),
                $justification,
            );

            $event->registrations()->delete();
            $event->delete();

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, $notification);
            }

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
