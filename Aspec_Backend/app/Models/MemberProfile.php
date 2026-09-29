<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class MemberProfile extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'sector_id',
        'location_id',
        'name',
        'business_name',
        'congregation',
        'role_in_congregation',
        'logo_path',
        'description',
        'website_url',
        'address',
        'commercial_contacts',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sector()
    {
        return $this->belongsTo(Sector::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function weekDays(): BelongsToMany
    {
        return $this->belongsToMany(WeekDay::class, 'business_hours', 'profile_id', 'week_day_id')
            ->using(BusinessHour::class)
            ->withPivot('open_time', 'close_time')
            ->withTimestamps()
            ->orderBy('week_days.id');
    }

    public function socialPlatforms(): BelongsToMany
    {
        return $this->belongsToMany(SocialPlatform::class, 'social_links', 'profile_id', 'platform_id')
            ->using(SocialLink::class)
            ->withPivot('url')
            ->withTimestamps();
    }

    public function portfolios(): HasMany
    {
        return $this->hasMany(Portfolio::class, 'profile_id');
    }
}
