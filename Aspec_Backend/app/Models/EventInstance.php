<?php

namespace App\Models;

use Database\Factories\EventInstanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventInstance extends Model
{
    /** @use HasFactory<EventInstanceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'category_id',
        'location_id',
        'name',
        'event_date',
        'event_start_time',
        'event_end_time',
        'description',
        'address',
        'latitude',
        'longitude',
        'max_attendees',
        'registration_deadline',
        'organizer_notes',
        'is_online',
        'online_url',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date:Y-m-d',
            'registration_deadline' => 'datetime',
            'max_attendees' => 'integer',
            'is_online' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'category_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
