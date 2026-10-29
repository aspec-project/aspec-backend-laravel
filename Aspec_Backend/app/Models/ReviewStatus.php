<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewStatus extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
    ];

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'status_id');
    }
}
