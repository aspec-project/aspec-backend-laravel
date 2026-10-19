<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Sector extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
    ];

    public function member_profiles()
    {
        return $this->hasMany(MemberProfile::class);
    }
}
