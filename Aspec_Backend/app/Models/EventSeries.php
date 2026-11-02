<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventSeries extends Model
{
    use HasUuids;

    protected $fillable = [
        'frequency',
        'ends_on',
        'registration_deadline_days_before',
    ];

    protected function casts(): array
    {
        return [
            'ends_on' => 'date:Y-m-d',
            'registration_deadline_days_before' => 'integer',
        ];
    }

    public function eventInstances(): HasMany
    {
        return $this->hasMany(EventInstance::class, 'series_id');
    }
}
