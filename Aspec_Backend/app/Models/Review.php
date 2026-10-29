<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'reviewer_profile_id',
        'reviewed_profile_id',
        'rating',
        'comment',
        'status_id',
    ];

    public function reviewerProfile(): BelongsTo
    {
        return $this->belongsTo(MemberProfile::class, 'reviewer_profile_id');
    }

    public function reviewedProfile(): BelongsTo
    {
        return $this->belongsTo(MemberProfile::class, 'reviewed_profile_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ReviewStatus::class, 'status_id');
    }

    /**
     * Só avaliações publicadas (estado Visible); Hidden e Flagged nunca contam para a média nem aparecem.
     */
    public function scopeVisible(Builder $query): void
    {
        $query->whereRelation('status', 'name', 'Visible');
    }
}
