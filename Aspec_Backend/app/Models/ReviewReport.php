<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewReport extends Model
{
    use HasUuids;

    public const SOURCE_MEMBER = 'member';

    public const SOURCE_AUTOMATIC = 'automatic';

    protected $fillable = [
        'review_id',
        'reporter_profile_id',
        'reason',
        'comment',
        'source',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function reporterProfile(): BelongsTo
    {
        return $this->belongsTo(MemberProfile::class, 'reporter_profile_id')
            ->withTrashed();
    }
}
