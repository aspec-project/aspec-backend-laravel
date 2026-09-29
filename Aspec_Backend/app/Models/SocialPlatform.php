<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasUuids;

class SocialPlatform extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
    ];
}
