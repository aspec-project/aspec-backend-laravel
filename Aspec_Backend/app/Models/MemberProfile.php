<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class MemberProfile extends Model
{
    use HasFactory, HasUuids, softDeletes;

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
}
