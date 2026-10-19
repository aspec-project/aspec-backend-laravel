<?php

namespace App\Http\Controllers\Api\Events;

use App\Http\Controllers\Controller;
use App\Models\EventInstance;
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
}
