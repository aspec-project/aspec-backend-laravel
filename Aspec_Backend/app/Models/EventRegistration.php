<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistration extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'event_instance_id',
        'user_id',
    ];

    public function eventInstance(): BelongsTo
    {
        return $this->belongsTo(EventInstance::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}