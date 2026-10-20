<?php

namespace App\Http\Controllers\Api\Events;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventInstanceResource;
use App\Models\EventInstance;
use App\Models\EventRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EventRegistrationController extends Controller
{
    public function store(Request $request, EventInstance $eventInstance): JsonResponse
    {
        $userId = $request->user()->getAuthIdentifier();
        $timezone = config('app.event_timezone', 'Europe/Lisbon');

        return DB::transaction(function () use ($eventInstance, $userId, $timezone): JsonResponse {
            $event = EventInstance::query()
                ->whereKey($eventInstance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $now = now($timezone);
            $deadline = Carbon::parse(
                $event->getRawOriginal('registration_deadline'),
                $timezone
            );

            if ($now->greaterThan($deadline)) {
                return $this->errorResponse(
                    'O prazo de inscrição para este evento terminou.',
                    Response::HTTP_CONFLICT
                );
            }

            if ($event->registrations()->where('user_id', $userId)->exists()) {
                return $this->errorResponse(
                    'Já está inscrito neste evento.',
                    Response::HTTP_CONFLICT
                );
            }

            if ($event->registrations()->count() >= $event->max_attendees) {
                return $this->errorResponse(
                    'O evento atingiu a lotação máxima.',
                    Response::HTTP_CONFLICT
                );
            }

            $registration = $event->registrations()->create([
                'user_id' => $userId,
            ]);

            return $this->successResponse(
                [
                    'id' => $registration->id,
                    'event_instance_id' => $event->id,
                ],
                'Inscrição efetuada com sucesso.',
                Response::HTTP_CREATED
            );
        });
    }

    public function destroy(Request $request, EventInstance $eventInstance): JsonResponse
    {
        $userId = $request->user()->getAuthIdentifier();
        $timezone = config('app.event_timezone', 'Europe/Lisbon');

        return DB::transaction(function () use ($eventInstance, $userId, $timezone): JsonResponse {
            $event = EventInstance::query()
                ->whereKey($eventInstance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $registration = $event->registrations()
                ->where('user_id', $userId)
                ->first();

            if (! $registration) {
                return $this->errorResponse(
                    'Não existe inscrição sua para este evento.',
                    Response::HTTP_NOT_FOUND
                );
            }

            $deadline = Carbon::parse(
                $event->getRawOriginal('registration_deadline'),
                $timezone
            );

            if (now($timezone)->greaterThan($deadline)) {
                return $this->errorResponse(
                    'O prazo para cancelar a inscrição terminou.',
                    Response::HTTP_CONFLICT
                );
            }

            $registration->delete();

            return $this->successResponse(
                null,
                'Inscrição cancelada com sucesso.'
            );
        });
    }

    public function registrants(Request $request, EventInstance $eventInstance): JsonResponse
    {
        $registrants = $eventInstance->registrations()
            ->with([
                'user' => fn ($query) => $query->withTrashed()
                    ->select(['id'])
                    ->with([
                        'memberProfile' => fn ($profileQuery) => $profileQuery
                            ->withTrashed()
                            ->select(['id', 'user_id', 'business_name']),
                    ]),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (EventRegistration $registration): array => [
                'business_name' => $registration->user?->memberProfile?->business_name,
            ])
            ->values();

        return $this->successResponse(
            $registrants,
            'Lista de inscritos obtida com sucesso.'
        );
    }

    public function mine(Request $request): JsonResponse
    {
        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $now = now($timezone);

        $eventStart = static fn (EventRegistration $registration): Carbon => Carbon::parse(
            $registration->eventInstance->event_date->format('Y-m-d')
                .' '.$registration->eventInstance->event_start_time,
            $timezone
        );

        $registrations = EventRegistration::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->with([
                'eventInstance' => fn ($query) => $query
                    ->with(['category', 'location'])
                    ->withCount('registrations'),
            ])
            ->get()
            ->sort(function (EventRegistration $left, EventRegistration $right) use ($eventStart, $now): int {
                $leftStart = $eventStart($left);
                $rightStart = $eventStart($right);

                $leftIsUpcoming = $leftStart->greaterThanOrEqualTo($now);
                $rightIsUpcoming = $rightStart->greaterThanOrEqualTo($now);

                if ($leftIsUpcoming !== $rightIsUpcoming) {
                    return $leftIsUpcoming ? -1 : 1;
                }

                return $leftIsUpcoming
                    ? $leftStart->getTimestamp() <=> $rightStart->getTimestamp()
                    : $rightStart->getTimestamp() <=> $leftStart->getTimestamp();
            })
            ->values()
            ->map(function (EventRegistration $registration) use ($request): array {
                $event = $registration->eventInstance;
                $event->setAttribute('is_registered', true);

                return [
                    'id' => $registration->id,
                    'created_at' => $registration->created_at?->toIso8601String(),
                    'event' => (new EventInstanceResource($event))->resolve($request),
                ];
            });

        return $this->successResponse(
            $registrations,
            'Lista de inscrições obtida com sucesso.'
        );
    }
}
