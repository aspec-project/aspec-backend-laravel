<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'commercial_email',
        'commercial_phone',
        'address',
        'postal_code',
        'city',
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

    /**
     * Perfis que aparecem no diretório público: conta Active e perfil não apagado, seja qual for o papel.
     * Perfis ou utilizadores soft deleted (incluindo anonimizados) ficam de fora pelo SoftDeletes de cada modelo.
     */
    public function scopeListedInDirectory(Builder $query): void
    {
        $query->whereHas('user', fn (Builder $user) => $user
            ->whereHas('accountStatus', fn (Builder $status) => $status->where('name', 'Active')));
    }

    /**
     * Perfis com média de avaliações visíveis igual ou superior a $min.
     * Ainda não existem avaliações, por isso nenhum perfil atinge a média mínima e a lista fica vazia.
     */
    public function scopeWithMinAverageRating(Builder $query, float $min): void
    {
        $query->whereRaw('0 = 1');
    }
}
