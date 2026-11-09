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

    public const REASON_OFFENSIVE_OR_DISRESPECTFUL_CONTENT = 'offensive_or_disrespectful_content';

    public const REASON_FALSE_OR_UNSUBSTANTIATED_CLAIMS = 'false_or_unsubstantiated_claims';

    public const REASON_NO_BUSINESS_RELATIONSHIP = 'no_business_relationship';

    public const REASON_SPAM_OR_ADVERTISING = 'spam_or_advertising';

    public const REASON_OTHER = 'other';

    public const REASONS = [
        self::REASON_OFFENSIVE_OR_DISRESPECTFUL_CONTENT,
        self::REASON_FALSE_OR_UNSUBSTANTIATED_CLAIMS,
        self::REASON_NO_BUSINESS_RELATIONSHIP,
        self::REASON_SPAM_OR_ADVERTISING,
        self::REASON_OTHER,
    ];

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
