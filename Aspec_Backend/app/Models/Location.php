<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    public function member_profiles()
    {
        return $this->hasMany(MemberProfile::class);
    }
}
