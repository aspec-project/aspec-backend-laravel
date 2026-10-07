<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, HasUuids, HasApiTokens;

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

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function accountStatus()
    {
        return $this->belongsTo(AccountStatus::class);
    }

    public function memberProfile()
    {
        return $this->hasOne(MemberProfile::class);
    }

    /**
     * Anonimiza e apaga (soft delete) o utilizador e, se existir, o perfil de membro.
     * Horários, redes sociais e portfólio são apagados definitivamente, tal como as
     * pastas de logótipo e portfólio no disco público. Revoga tokens e sessões (SPA) e a conta fica Inactive.
     * Funciona também sem perfil (ex.: admin) e com perfil já apagado (soft delete).
     */
    public function anonymizeAndDelete(): void
    {
        DB::transaction(function () {
            // withTrashed: um perfil já apagado (soft delete) também tem de ser anonimizado.
            $profile = MemberProfile::withTrashed()->where('user_id', $this->id)->first();

            if ($profile) {
                $profile->update([
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

                $profile->socialPlatforms()->detach();
                $profile->weekDays()->detach();
                $profile->portfolios()->delete();

                $profile->delete();
            }

            // forceFill: remember_token e email_verified_at não estão no $fillable.
            $this->forceFill([
                'email'             => 'deleted_' . Str::uuid() . '@aspec.local',
                'phone'             => '000000000',
                'password'          => Hash::make(Str::random(32)),
                'remember_token'    => null,
                'email_verified_at' => null,
                'trial_ends_at'     => null,
                'account_status_id' => AccountStatus::where('name', 'Inactive')->value('id'),
            ])->save();

            $this->tokens()->delete();

            // Nome da tabela vem da config (SESSION_TABLE é configurável); a ligação por omissão
            // mantém o delete dentro desta transação, para entrar no rollback se algo falhar
            // (por isso SESSION_CONNECTION tem de ficar vazio).
            DB::table(config('session.table'))->where('user_id', $this->id)->delete();

            $this->delete();
        });

        // Os ficheiros só são apagados depois do commit (também de uma transação exterior),
        // para não se perderem imagens se a base de dados fizer rollback.
        DB::afterCommit(function () {
            Storage::disk('public')->deleteDirectory("logos/{$this->id}");
            Storage::disk('public')->deleteDirectory("portfolios/{$this->id}");
        });
    }

        /**
     * Passa a conta a Inactive e termina todas as sessões (revoga os tokens).
     */
    public function deactivate(): void
    {
        DB::transaction(function () {
            $this->update([
                'account_status_id' => AccountStatus::where('name', 'Inactive')->value('id'),
            ]);
            $this->tokens()->delete();
        });
    }
    




}
