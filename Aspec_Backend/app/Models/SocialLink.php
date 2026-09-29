<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class SocialLink extends Pivot
{
    use HasUuids;

    protected $table = 'social_links';

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MemberProfile::class, 'profile_id');
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(SocialPlatform::class, 'platform_id');
    }
}
