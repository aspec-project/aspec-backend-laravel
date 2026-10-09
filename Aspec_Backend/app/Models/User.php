<?php

namespace App\Models;

use App\Enums\ActivationType;
use App\Enums\InactiveReason;
use App\Jobs\DeleteStripeCustomerJob;
use App\Services\Payments\StripeCustomerService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
use Stripe\Subscription as StripeSubscription;

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

    /**
     * Os dados de faturação só são gravados a partir de um Form Request validado.
     */
    protected $fillable = [
        'email',
        'password',
        'phone',
        'role_id',
        'account_status_id',
        'billing_name',
        'nif',
        'billing_address',
        'billing_postal_code',
        'billing_city',
    ];

    /**
     * Rede de segurança: os Resources já escolhem os campos, mas um toArray() ou log esquecido não
     * pode expor o NIF, a morada, o estado da subscrição nem o cliente Stripe e o cartão.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'grace_ends_at',
        'inactive_reason',
        'stripe_checkout_session_id',
        'nif',
        'billing_name',
        'billing_address',
        'billing_postal_code',
        'billing_city',
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
     * Faturas emitidas pela plataforma (InvoiceExpress ou log) para os pagamentos do membro.
     * Não se chama invoices() porque esse nome já é do Billable (faturas do Stripe).
     */
    public function issuedInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Regra única de quem pode pagar pelo link do email: Approved ativa (com trial) e
     * Inactive por falta de pagamento reativa (sem trial). Bloqueadas, recusadas, apagadas,
     * pendentes, ativas e Inactive sem motivo devolvem null.
     */
    public function activationType(): ?ActivationType
    {
        $status = $this->accountStatus?->name;

        return match (true) {
            $status === 'Approved' => ActivationType::Activation,
            $status === 'Inactive' && $this->inactive_reason === InactiveReason::Unpaid => ActivationType::Reactivation,
            default => null,
        };
    }

    /**
     * Indica se o utilizador tem uma subscrição em curso (trial, ativa ou em carência; as
     * terminadas e as incompletas não contam).
     *
     * @param  string|null  $exceptStripeId  Subscrição a ignorar (ex.: a que o webhook acabou de criar).
     */
    public function hasOngoingSubscription(?string $exceptStripeId = null): bool
    {
        return $this->subscriptions()
            ->active()
            ->when($exceptStripeId, fn ($query) => $query->where('stripe_id', '!=', $exceptStripeId))
            ->exists();
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
     * qualquer outro, mesmo Blocked, porque a conta deixa de existir).
     * Apaga o cliente no Stripe antes da transação (sem a prender à espera da rede; se a transação
     * falhar, uma nova tentativa recebe "cliente já apagado", que conta como sucesso). Se o Stripe
     * falhar, agenda DeleteStripeCustomerJob depois do commit e mantém o stripe_id até o job ter sucesso.
     * As subscrições locais ficam canceladas logo (senão subscribed() continuava true até ao webhook).
     * As sessões são apagadas na ligação por omissão, dentro da transação (SESSION_CONNECTION tem de
     * ficar vazio); os ficheiros só depois do commit, para não se perderem num rollback.
     * Funciona também sem perfil (ex.: admin) e com perfil já apagado (soft delete).
     * Não chamar dentro de outra transação: a chamada ao Stripe não pode ser desfeita por um rollback exterior.
     */
    public function anonymizeAndDelete(): void
    {
        $stripeCustomerDeleted = $this->deleteStripeCustomer();

        DB::transaction(function () use ($stripeCustomerDeleted) {
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
                'pm_type'                    => null,
                'pm_last_four'               => null,
                'account_status_id'          => AccountStatus::where('name', 'Inactive')->value('id'),
                'inactive_reason'            => InactiveReason::Deleted,
            ]);

            if ($stripeCustomerDeleted) {
                $this->stripe_id = null;
            }

            $this->save();

            $this->subscriptions()
                ->where('stripe_status', '!=', StripeSubscription::STATUS_CANCELED)
                ->update([
                    'stripe_status' => StripeSubscription::STATUS_CANCELED,
                    'ends_at'       => now(),
                ]);

            $this->tokens()->delete();

            DB::table(config('session.table'))->where('user_id', $this->id)->delete();

            $this->delete();

            if (! $stripeCustomerDeleted) {
                DeleteStripeCustomerJob::dispatch($this->id)->afterCommit();
            }
        });

        DB::afterCommit(function () {
            Storage::disk('public')->deleteDirectory("logos/{$this->id}");
            Storage::disk('public')->deleteDirectory("portfolios/{$this->id}");
        });
    }

    /**
     * Apaga o cliente Stripe do utilizador, se existir.
     *
     * @return bool true se não havia cliente ou se foi apagado; false se o Stripe falhou.
     */
    private function deleteStripeCustomer(): bool
    {
        if (blank($this->stripe_id)) {
            return true;
        }

        try {
            app(StripeCustomerService::class)->delete($this->stripe_id);

            return true;
        } catch (ApiErrorException $e) {
            Log::warning('Falha ao apagar o cliente Stripe; job de recurso agendado.', [
                'user_id'           => $this->id,
                'exception'         => $e::class,
                'stripe_request_id' => $e->getRequestId(),
            ]);

            return false;
        }
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
