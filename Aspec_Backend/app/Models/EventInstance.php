<?php

namespace App\Models;

use Database\Factories\EventInstanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
        'venue_name',
        'place_id',
        'latitude',
        'longitude',
        'max_attendees',
        'registration_deadline',
        'organizer_notes',
        'is_online',
        'online_url',
        'series_id',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date:Y-m-d',
            'registration_deadline' => 'datetime',
            'max_attendees' => 'integer',
            'is_online' => 'boolean',
            'announced_at' => 'datetime',
        ];
    }

    /**
     * Início do evento (data + hora de início) na hora de Portugal, o fuso em que o backoffice guarda os eventos.
     */
    public function startsAt(): Carbon
    {
        return Carbon::createFromFormat(
            'Y-m-d H:i',
            $this->event_date->format('Y-m-d').' '.substr((string) $this->event_start_time, 0, 5),
            config('app.event_timezone', 'Europe/Lisbon')
        );
    }

    /**
     * Indica se o prazo de inscrição já passou. O prazo está guardado na hora de Portugal, por isso
     * é lido a partir do valor em bruto (o cast datetime interpretá-lo-ia em UTC), como no EventInstanceResource.
     */
    public function registrationDeadlinePassed(): bool
    {
        $timezone = config('app.event_timezone', 'Europe/Lisbon');

        return now($timezone)->greaterThan(Carbon::parse($this->getRawOriginal('registration_deadline'), $timezone));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'category_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(EventSeries::class, 'series_id');
    }
}
