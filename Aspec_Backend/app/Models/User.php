<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\InactiveReason;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, HasUuids, HasApiTokens, Billable;

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
            'trial_ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'inactive_reason' => InactiveReason::class,
        ];
    }

    protected $fillable = [
        'email',
        'password',
        'phone',
        'role_id',
        'account_status_id',
        // Dados de faturação: só gravados a partir de um Form Request validado.
        'billing_name',
        'nif',
        'billing_address',
        'billing_postal_code',
        'billing_city',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // Rede de segurança: os Resources já escolhem os campos, mas um toArray() ou log
        // do modelo esquecido não pode expor o NIF, a morada nem o estado da subscrição.
        'grace_ends_at',
        'inactive_reason',
        'stripe_checkout_session_id',
        'nif',
        'billing_name',
        'billing_address',
        'billing_postal_code',
        'billing_city',
        // Cliente Stripe e cartão: só o Cashier os grava (forceFill), nunca saem na API.
        'stripe_id',
        'pm_type',
        'pm_last_four',
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
     * Nome do cliente no Stripe: o nome de faturação (o User não tem `name`).
     */
    public function stripeName(): ?string
    {
        return $this->billing_name;
    }

    /**
     * Morada do cliente no Stripe, a partir da morada de faturação (sempre em Portugal).
     *
     * @return array<string, string> Vazio se ainda não houver morada de faturação.
     */
    public function stripeAddress(): array
    {
        if (blank($this->billing_address)) {
            return [];
        }

        return [
            'line1'       => $this->billing_address,
            'postal_code' => $this->billing_postal_code,
            'city'        => $this->billing_city,
            'country'     => 'PT',
        ];
    }

    /**
     * O telefone nunca é enviado ao Stripe: não é preciso para cobrar (minimização de dados, RGPD).
     */
    public function stripePhone(): ?string
    {
        return null;
    }

    /**
     * Anonimiza e apaga (soft delete) o utilizador e, se existir, o perfil de membro.
     * Horários, redes sociais e portfólio são apagados definitivamente, tal como as
     * pastas de logótipo e portfólio no disco público. Revoga tokens e sessões (SPA) e a conta fica Inactive.
     * Limpa os dados de faturação e o estado da subscrição; o motivo passa a Deleted (substitui
     * qualquer outro, mesmo Blocked, porque a conta deixa de existir). O cliente Stripe é tratado à parte.
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

            // forceFill: remember_token, email_verified_at e os campos de estado da subscrição não estão no $fillable.
            $this->forceFill([
                'email'                      => 'deleted_' . Str::uuid() . '@aspec.local',
                'phone'                      => '000000000',
                'password'                   => Hash::make(Str::random(32)),
                'remember_token'             => null,
                'email_verified_at'          => null,
                'trial_ends_at'              => null,
                'grace_ends_at'              => null,
                'stripe_checkout_session_id' => null,
                'billing_name'               => null,
                'nif'                        => null,
                'billing_address'            => null,
                'billing_postal_code'        => null,
                'billing_city'               => null,
                'account_status_id'          => AccountStatus::where('name', 'Inactive')->value('id'),
                'inactive_reason'            => InactiveReason::Deleted,
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
    public function deactivate(string $reason): void
    {
        $requestedReason = InactiveReason::from($reason);

        DB::transaction(function () use ($requestedReason) {
            $currentReason = $this->inactive_reason;

            $reasonToStore = in_array(
                $currentReason,
                [
                    InactiveReason::Blocked,
                    InactiveReason::Deleted,
                ],
                true
            )
                ? $currentReason
                : $requestedReason;

            $this->forceFill([
                'account_status_id' => AccountStatus::where(
                    'name',
                    'Inactive'
                )->value('id'),
                'inactive_reason' => $reasonToStore,
                'grace_ends_at' => null,
            ])->save();

            $this->tokens()->delete();

            DB::table(config('session.table'))
                ->where('user_id', $this->id)
                ->delete();
        });
    }
    




}
