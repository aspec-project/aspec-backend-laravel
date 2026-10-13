<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventInstanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'max_attendees' => $this->max_attendees,
            'registration_deadline' => $this->registration_deadline?->toIso8601String(),
            'organizer_notes' => $this->organizer_notes,
        ];
    }
}
