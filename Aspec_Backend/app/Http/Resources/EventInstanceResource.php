<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class EventInstanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();
        $hasRegistrationCount = array_key_exists('registrations_count', $attributes);
        $registrationCount = $hasRegistrationCount
            ? (int) $attributes['registrations_count']
            : null;
        $availableSpots = $hasRegistrationCount
            ? max(0, (int) $this->max_attendees - $registrationCount)
            : null;

        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $deadline = Carbon::parse(
            $this->getRawOriginal('registration_deadline'),
            $timezone
        );
        $now = now($timezone);

        $deadlineHasPassed = $now->greaterThan($deadline);
        $registrationOpen = $hasRegistrationCount
            && $availableSpots > 0
            && $now->lessThanOrEqualTo($deadline);

        $hasIsRegistered = array_key_exists('is_registered', $attributes);
        $isRegistered = $hasIsRegistered && (bool) $attributes['is_registered'];

        $viewer = $request->user('sanctum');
        $isAdminRoute = $request->is('api/admin/*');

        $isActiveAdmin = $isAdminRoute
            && $viewer?->accountStatus?->name === 'Active'
            && $viewer?->role?->name === 'Admin';

        $canSeeOnlineUrl = $isActiveAdmin || ($isRegistered && $deadlineHasPassed);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'color' => $this->category->color,
            ]),
            'location' => $this->whenLoaded('location', fn () => [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ]),
            'event_date' => $this->event_date?->format('Y-m-d'),
            'event_start_time' => substr((string) $this->event_start_time, 0, 5),
            'event_end_time' => substr((string) $this->event_end_time, 0, 5),
            'description' => $this->description,
            'address' => $this->address,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'max_attendees' => $this->max_attendees,
            'registration_deadline' => $this->registration_deadline?->toIso8601String(),
            'organizer_notes' => $this->organizer_notes,
            'registrations_count' => $this->when($hasRegistrationCount, $registrationCount),
            'available_spots' => $this->when($hasRegistrationCount, $availableSpots),
            'registration_open' => $this->when($hasRegistrationCount, $registrationOpen),
            'is_registered' => $this->when($hasIsRegistered, $isRegistered),
            'online_url' => $this->when($canSeeOnlineUrl, $this->online_url),
        ];
    }
}
