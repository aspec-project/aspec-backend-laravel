<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
    ];

    public function member_profiles(): HasMany
    {
        return $this->hasMany(MemberProfile::class);
    }

    public function eventInstances(): HasMany
    {
        return $this->hasMany(EventInstance::class, 'location_id');
    }
}