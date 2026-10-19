<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Portfolio extends Model
{
    use HasUuids;

    protected $fillable = ['profile_id', 'image_path'];

    public function profile()
    {
        return $this->belongsTo(MemberProfile::class, 'profile_id');
    }
}
