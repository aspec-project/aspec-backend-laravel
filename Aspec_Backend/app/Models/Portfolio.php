<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasUuids;

class Portfolio extends Model
{
    use HasUuids;

    protected $fillable = ['profile_id', 'image_path'];

    public function profile()
    {
        return $this->belongsTo(MemberProfile::class, 'profile_id');
    }
}
