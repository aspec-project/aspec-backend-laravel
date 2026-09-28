<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, softDeletes, HasUuids, HasApiTokens;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected $fillable = [
        'email',
        'password',
        'phone',
        'role_id',
        'account_status_id',
        'trial_ends_at',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function accountStatus()
    {
        return $this->belongsTo(AccountStatus::class);
    }

    public function member_profile()
    {
        return $this->hasOne(MemberProfile::class);
    }

    public function anonymizeAndDelete()
    {
        if (!$this->memberProfile) {
            throw new \Exception('O utilizador não tem um perfil de membro associado.');}

        DB::transaction(function () {

            if ($this->memberProfile) {
                $this->memberProfile->update([
                    'name'                 => 'Utilizador Anónimo',
                    'business_name'        => 'Empresa Removida',
                    'congregation'         => 'N/A',
                    'role_in_congregation' => 'N/A',
                    'logo_path'            => null,
                    'description'          => null,
                    'website_url'          => null,
                    'commercial_contacts'  => null,
                    'address'              => null,
                ]);
                
                $this->memberProfile->delete(); 
            }

            $this->update([
                'email'    => 'deleted_' . Str::uuid() . '@aspec.local',
                'phone'    => '000000000',
                'password' => Hash::make(Str::random(32)), 
            ]);

            $this->delete();
        });
    }
}
