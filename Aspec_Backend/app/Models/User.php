<?php

namespace App\Models;

use App\Enums\ActivationType;
use App\Enums\InactiveReason;
use App\Enums\SubscriptionState;
use App\Jobs\CancelStripeSubscriptionJob;
use App\Jobs\DeleteStripeCustomerJob;
use App\Notifications\ResetPasswordNotification;
use App\Services\Payments\StripeCustomerService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Subscription;
use Laravel\Sanctum\HasApiTokens;
use Stripe\Exception\ApiErrorException;
use Stripe\Subscription as StripeSubscription;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

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
            'terms_accepted_at' => 'datetime',
            'subscription_exempt' => 'boolean',
            'notify_new_events' => 'boolean',
            'notify_weekly_agenda' => 'boolean',
            'weekly_agenda_sent_at' => 'datetime',
        ];
    }

    /**
     * Valores por omissão iguais aos da base de dados: um utilizador acabado de criar já tem as
     * preferências dos emails de eventos ligadas, sem ter de ser relido.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'notify_new_events' => true,
        'notify_weekly_agenda' => true,
    ];

    /**
     * Os dados de faturação só são gravados a partir de um Form Request validado.
     * A marca subscription_exempt fica de fora: só o aspec:create-admin a escreve (forceFill).
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
        'terms_accepted_at',
        'notify_new_events',
        'notify_weekly_agenda',
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
        'subscription_exempt',
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
     * Inscrições do utilizador em eventos.
     */
    public function eventRegistrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    /**
     * Participantes dos cartões de oração: conta Active com perfil de membro (Member ou Admin).
     * As contas anonimizadas ficam de fora (soft delete do utilizador e do perfil).
     */
    public function scopeActiveWithProfile(Builder $query): void
    {
        $this->whereAccountStatus($query, 'Active')->whereHas('memberProfile');
    }

    /**
     * Destinatários dos emails de eventos: conta Active, com perfil de membro (Member ou Admin) e
     * com a preferência indicada ligada. É a regra do diretório; o admin técnico (sem perfil) fica
     * de fora e as contas anonimizadas também (soft delete).
     *
     * @param  string  $preference  notify_new_events ou notify_weekly_agenda.
     */
    public function scopeReceivesEventEmails(Builder $query, string $preference): void
    {
        $this->whereAccountStatus($query, 'Active')
            ->whereHas('memberProfile')
            ->where($preference, true);
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
     * Inactive por falta de pagamento ou por cancelamento do membro reativa (sem trial; reativar é
     * igual nos dois casos). Bloqueadas, recusadas, apagadas, pendentes, ativas e Inactive sem
     * motivo devolvem null.
     */
    public function activationType(): ?ActivationType
    {
        $status = $this->accountStatus?->name;
        $reactivatable = in_array($this->inactive_reason, [InactiveReason::Unpaid, InactiveReason::Cancelled], true);

        return match (true) {
            $status === 'Approved' => ActivationType::Activation,
            $status === 'Inactive' && $reactivatable => ActivationType::Reactivation,
            default => null,
        };
    }

    /**
     * Estado da subscrição derivado das colunas da conta (para o backoffice). O estado da conta
     * decide primeiro; numa conta Active, isento > carência > trial > ativo. Espelha o scope
     * inSubscriptionState(): qualquer mudança aqui tem de ser feita lá também.
     * Espera accountStatus carregado (senão faz 1 query por utilizador); a isenção vem da coluna.
     *
     * @throws \UnhandledMatchError Estado de conta ou motivo de inatividade desconhecido.
     */
    public function subscriptionState(): SubscriptionState
    {
        return match ($this->accountStatus->name) {
            'Pending' => SubscriptionState::Pending,
            'Approved' => SubscriptionState::AwaitingActivation,
            'Inactive' => match ($this->inactive_reason) {
                InactiveReason::Blocked => SubscriptionState::Blocked,
                InactiveReason::Unpaid, InactiveReason::Cancelled => SubscriptionState::Lapsed,
                InactiveReason::Rejected, InactiveReason::Deleted, null => SubscriptionState::Inactive,
            },
            'Active' => match (true) {
                $this->isSubscriptionExempt() => SubscriptionState::Exempt,
                $this->grace_ends_at !== null => SubscriptionState::Grace,
                $this->trial_ends_at?->isFuture() === true => SubscriptionState::Trial,
                default => SubscriptionState::Active,
            },
        };
    }

    /**
     * Indica se o utilizador não paga subscrição: só o administrador técnico criado pelo
     * aspec:create-admin (marca users.subscription_exempt). Os outros admins pagam como qualquer
     * membro. Mesma regra que o scope subscriptionExempt(); não faz queries.
     */
    public function isSubscriptionExempt(): bool
    {
        return (bool) $this->subscription_exempt;
    }

    /**
     * Filtra os utilizadores isentos de subscrição (marca users.subscription_exempt).
     * Mesma regra que isSubscriptionExempt().
     */
    public function scopeSubscriptionExempt(Builder $query): void
    {
        $query->where('subscription_exempt', true);
    }

    /**
     * Filtra os utilizadores num estado da subscrição (versão SQL de subscriptionState()).
     * Cada estado repete as negações dos que lhe ganham, para que cada utilizador caia em exatamente um.
     */
    public function scopeInSubscriptionState(Builder $query, SubscriptionState $state): void
    {
        $exempt = fn (Builder $user) => $user->subscriptionExempt();

        match ($state) {
            SubscriptionState::Pending => $this->whereAccountStatus($query, 'Pending'),
            SubscriptionState::AwaitingActivation => $this->whereAccountStatus($query, 'Approved'),
            SubscriptionState::Blocked => $this->whereAccountStatus($query, 'Inactive')
                ->where('inactive_reason', InactiveReason::Blocked->value),
            SubscriptionState::Lapsed => $this->whereAccountStatus($query, 'Inactive')
                ->whereIn('inactive_reason', [InactiveReason::Unpaid->value, InactiveReason::Cancelled->value]),
            SubscriptionState::Inactive => $this->whereAccountStatus($query, 'Inactive')
                ->where(fn (Builder $user) => $user->whereNull('inactive_reason')
                    ->orWhereIn('inactive_reason', [InactiveReason::Rejected->value, InactiveReason::Deleted->value])),
            SubscriptionState::Exempt => $this->whereAccountStatus($query, 'Active')->subscriptionExempt(),
            SubscriptionState::Grace => $this->whereAccountStatus($query, 'Active')
                ->whereNot($exempt)
                ->whereNotNull('grace_ends_at'),
            SubscriptionState::Trial => $this->whereAccountStatus($query, 'Active')
                ->whereNot($exempt)
                ->whereNull('grace_ends_at')
                ->where('trial_ends_at', '>', now()),
            SubscriptionState::Active => $this->whereAccountStatus($query, 'Active')
                ->whereNot($exempt)
                ->whereNull('grace_ends_at')
                ->where(fn (Builder $user) => $user->whereNull('trial_ends_at')
                    ->orWhere('trial_ends_at', '<=', now())),
        };
    }

    /**
     * Número de utilizadores em cada estado da subscrição, dentro da consulta base (ex.: a pesquisa
     * da lista do admin). Usa o mesmo scope do filtro, por isso as contagens nunca divergem dele.
     *
     * @return array<string, int> Valor do estado => contagem, para todos os estados (zeros incluídos).
     */
    public static function subscriptionStateCounts(Builder $query): array
    {
        $counts = [];

        foreach (SubscriptionState::cases() as $state) {
            $counts[$state->value] = (clone $query)->inSubscriptionState($state)->count();
        }

        return $counts;
    }

    private function whereAccountStatus(Builder $query, string $status): Builder
    {
        return $query->whereHas('accountStatus', fn (Builder $accountStatus) => $accountStatus->where('name', $status));
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
     * Subscrição a mostrar ao membro: a mais recente em curso; se não houver, a mais recente de
     * todas. Não usa o subscription('default') do Cashier, que devolve só a mais recente: um
     * duplicado cancelado pelo webhook, criado depois, escondia a subscrição verdadeira. Desempata
     * pelo id porque o created_at pode coincidir no mesmo segundo.
     */
    public function currentSubscription(): ?Subscription
    {
        $query = fn () => $this->subscriptions()->where('type', 'default')->orderByDesc('id');

        return $query()->active()->first() ?? $query()->first();
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
            'line1' => $this->billing_address,
            'postal_code' => $this->billing_postal_code,
            'city' => $this->billing_city,
            'country' => 'PT',
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
     * Apaga os tokens de reposição de password do email original (dado pessoal).
     * Limpa os dados de faturação e o estado da subscrição; o motivo passa a Deleted (substitui
     * qualquer outro, mesmo Blocked, porque a conta deixa de existir).
     * Apaga o cliente no Stripe antes da transação (sem a prender à espera da rede; se a transação
     * falhar, uma nova tentativa recebe "cliente já apagado", que conta como sucesso). Se o Stripe
     * falhar, agenda DeleteStripeCustomerJob depois do commit e mantém o stripe_id até o job ter sucesso.
     * As subscrições locais ficam canceladas logo (senão subscribed() continuava true até ao webhook).
     * As sessões são apagadas na ligação por omissão, dentro da transação (SESSION_CONNECTION tem de
     * ficar vazio); os ficheiros só depois do commit, para não se perderem num rollback.
     * Funciona também sem perfil (ex.: admin) e com perfil já apagado (soft delete).
     * Mantém as faturas e os respetivos PDFs (obrigação fiscal de 10 anos).
     * Cartões de oração escritos pela conta: conteúdo apagado e soft delete (dados religiosos de quem
     * pediu o apagamento); os recebidos são dados dos autores e expiram normalmente.
     * Não chamar dentro de outra transação: a chamada ao Stripe não pode ser desfeita por um rollback exterior.
     * Grava a prova da anonimização (conta, admin, data) na mesma transação: nunca uma sem a outra.
     * A conta é relida com lockForUpdate: dois pedidos em simultâneo anonimizam e registam uma só vez.
     * Tudo o resto parte da linha bloqueada (não da cópia em memória), para limpar também o que mudou
     * entretanto (ex.: faturação gravada por uma ativação em simultâneo). Se o stripe_id da linha
     * bloqueada não for o que já foi apagado, o DeleteStripeCustomerJob trata dele depois do commit.
     * Uma sessão de Checkout ainda guardada é expirada depois do commit (CancelStripeSubscriptionJob),
     * para ninguém conseguir pagar por uma conta apagada.
     *
     * @param  User|null  $anonymizedBy  Admin que pediu a anonimização; null fora da API (tinker, comandos).
     * @return bool false se a conta já estava apagada (nada é alterado); true se foi anonimizada.
     */
    public function anonymizeAndDelete(?User $anonymizedBy = null): bool
    {
        $handledStripeId = $this->stripe_id;
        $stripeCustomerDeleted = $this->deleteStripeCustomer();
        $checkoutSessionId = null;

        $anonymized = DB::transaction(function () use ($handledStripeId, $stripeCustomerDeleted, $anonymizedBy, &$checkoutSessionId) {
            $current = static::withTrashed()->whereKey($this->id)->lockForUpdate()->first();

            if ($current === null || $current->trashed()) {
                return false;
            }

            $this->setRawAttributes($current->getAttributes(), true);
            $stripeCustomerDeleted = $stripeCustomerDeleted && $this->stripe_id === $handledStripeId;
            $checkoutSessionId = $this->stripe_checkout_session_id;

            $profile = MemberProfile::withTrashed()
                ->where('user_id', $this->id)
                ->lockForUpdate()
                ->first();

            if ($profile) {
                $profile->reviewsReceived()->delete();

                $profile->update([
                    'name' => 'Utilizador Anónimo',
                    'business_name' => 'Empresa Removida',
                    'congregation' => 'N/A',
                    'role_in_congregation' => 'N/A',
                    'logo_path' => null,
                    'description' => null,
                    'website_url' => null,
                    'commercial_email' => null,
                    'commercial_phone' => null,
                    'address' => null,
                    'postal_code' => null,
                    'city' => null,
                ]);

                $profile->socialPlatforms()->detach();
                $profile->weekDays()->detach();
                $profile->portfolios()->delete();

                $profile->delete();
            }

            $timezone = config('app.event_timezone', 'Europe/Lisbon');
            $now = now($timezone);
            $today = $now->toDateString();
            $currentTime = $now->format('H:i:s');

            $futureEventIds = DB::table('event_instances')
                ->where(function (QueryBuilder $query) use ($today, $currentTime) {
                    $query
                        ->where('event_date', '>', $today)
                        ->orWhere(function (QueryBuilder $query) use ($today, $currentTime) {
                            $query
                                ->where('event_date', $today)
                                ->where('event_start_time', '>=', $currentTime);
                        });
                })
                ->select('id');

            DB::table('event_registrations')
                ->where('user_id', $this->id)
                ->whereIn('event_instance_id', $futureEventIds)
                ->delete();

            PrayerCard::query()
                ->where('author_user_id', $this->id)
                ->get()
                ->each(fn (PrayerCard $card) => $card->erase());

            // Antes do forceFill: depois dele, $this->email já é o email anonimizado.
            $this->deletePasswordResetTokens();

            $this->forceFill([
                'email' => 'deleted_'.Str::uuid().'@aspec.local',
                'phone' => '000000000',
                'password' => Hash::make(Str::random(32)),
                'remember_token' => null,
                'email_verified_at' => null,
                'trial_ends_at' => null,
                'grace_ends_at' => null,
                'stripe_checkout_session_id' => null,
                'billing_name' => null,
                'nif' => null,
                'billing_address' => null,
                'billing_postal_code' => null,
                'billing_city' => null,
                'pm_type' => null,
                'pm_last_four' => null,
                'account_status_id' => AccountStatus::where('name', 'Inactive')->value('id'),
                'inactive_reason' => InactiveReason::Deleted,
            ]);

            if ($stripeCustomerDeleted) {
                $this->stripe_id = null;
            }

            $this->save();

            $this->subscriptions()
                ->where('stripe_status', '!=', StripeSubscription::STATUS_CANCELED)
                ->update([
                    'stripe_status' => StripeSubscription::STATUS_CANCELED,
                    'ends_at' => now(),
                ]);

            $this->tokens()->delete();

            DB::table(config('session.table'))->where('user_id', $this->id)->delete();

            AccountAnonymization::create([
                'user_id' => $this->id,
                'anonymized_by_user_id' => $anonymizedBy?->id,
            ]);

            $this->delete();

            if (! $stripeCustomerDeleted) {
                DeleteStripeCustomerJob::dispatch($this->id)->afterCommit();
            }

            return true;
        });

        if (! $anonymized) {
            return false;
        }

        DB::afterCommit(function () {
            Storage::disk('public')->deleteDirectory("logos/{$this->id}");
            Storage::disk('public')->deleteDirectory("portfolios/{$this->id}");
        });

        if ($checkoutSessionId !== null) {
            // Com a fila sync, um job falhado lança: a anonimização já está gravada e não pode falhar por isso.
            rescue(function () use ($checkoutSessionId) {
                CancelStripeSubscriptionJob::dispatch($this->id, [], $checkoutSessionId)->afterCommit();
            }, report: true);
        }

        return true;
    }

    /**
     * Apaga os tokens de reposição de password do email indicado (por omissão, o atual).
     * Recebe o email porque, numa mudança de email, os tokens a apagar são os do email antigo.
     */
    public function deletePasswordResetTokens(?string $email = null): void
    {
        DB::table(config('auth.passwords.users.table'))
            ->where('email', $email ?? $this->email)
            ->delete();
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
                'user_id' => $this->id,
                'exception' => $e::class,
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

    /**
     * Envia a notificação de redefinição de password.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
