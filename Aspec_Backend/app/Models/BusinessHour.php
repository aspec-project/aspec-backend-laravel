<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessHour extends Pivot
{
    use HasUuids;

    protected $table = 'business_hours';

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MemberProfile::class, 'profile_id');
    }

    public function weekDay(): BelongsTo
    {
        return $this->belongsTo(WeekDay::class, 'week_day_id');
    }
}
