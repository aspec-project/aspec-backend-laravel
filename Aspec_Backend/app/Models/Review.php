<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasUuids;

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
}
