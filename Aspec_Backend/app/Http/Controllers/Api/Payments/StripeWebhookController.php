<?php

namespace App\Http\Controllers\Api\Payments;

use App\Enums\InactiveReason;
use App\Enums\InvoiceStatus;
use App\Jobs\IssueInvoiceJob;
use App\Models\AccountStatus;
use App\Models\Invoice;
use App\Models\ProcessedWebhookEvent;
use App\Models\User;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\SubscriptionActivatedNotification;
use App\Services\Payments\StripeSubscriptionService;
use App\Services\Payments\SubscriptionService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Laravel\Cashier\Subscription;
use Stripe\Subscription as StripeSubscription;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Webhook do Stripe. A assinatura é verificada pelo middleware do Cashier (aplicado no
 * construtor dele quando há STRIPE_WEBHOOK_SECRET); os handlers do Cashier sincronizam as
 * subscrições locais e este controller acrescenta a idempotência, a ativação da conta quando a
 * subscrição é criada ou passa a ativa, a fatura de cada pagamento, o período de carência quando
 * a cobrança falha e a desativação quando a subscrição termina.
 */
class StripeWebhookController extends WebhookController
{
    use ApiResponse;

    private const RUNNING_STATUSES = [StripeSubscription::STATUS_TRIALING, StripeSubscription::STATUS_ACTIVE];

    private const ENDED_STATUSES = [StripeSubscription::STATUS_CANCELED, StripeSubscription::STATUS_INCOMPLETE_EXPIRED];

    private const OVERDUE_STATUSES = [StripeSubscription::STATUS_PAST_DUE, StripeSubscription::STATUS_UNPAID];

    /**
     * Processa um evento do Stripe uma só vez, numa transação.
     *
     * - Sem segredo configurado recusa tudo com 500 (fail closed): o Cashier só verifica a assinatura
     *   quando há segredo, e aceitar seria aceitar qualquer POST anónimo. O log crítico sai no máximo
     *   uma vez por hora, porque o Stripe repete cada entrega recusada.
     * - O evento é registado antes de ser processado: o unique de stripe_event_id decide qual das
     *   entregas o processa (uma entrega simultânea espera pelo commit e encontra a linha → 200).
     * - Uma falha em qualquer handler desfaz tudo, incluindo o registo do evento, e responde 500
     *   genérico (a resposta vai para o Stripe; o detalhe fica só no log, mesmo com APP_DEBUG=true),
     *   para o Stripe repetir.
     */
    public function handleWebhook(Request $request)
    {
        if (blank(config('cashier.webhook.secret'))) {
            if (Cache::add('stripe-webhook-secret-missing', true, 3600)) {
                Log::critical('STRIPE_WEBHOOK_SECRET não configurado; webhook recusado.');
            }

            return $this->serverError();
        }

        $payload = json_decode($request->getContent(), true);

        try {
            return DB::transaction(function () use ($request, $payload) {
                $event = ProcessedWebhookEvent::createOrFirst(
                    ['stripe_event_id' => $payload['id']],
                    ['type' => $payload['type']],
                );

                if (! $event->wasRecentlyCreated) {
                    return $this->successMethod();
                }

                return parent::handleWebhook($request);
            });
        } catch (Throwable $e) {
            report($e);

            return $this->serverError();
        }
    }

    /**
     * Subscrição criada (normalmente pelo Checkout): ativa a conta. Só o webhook ativa, porque é
     * assinado pelo Stripe e o regresso do browser pode ser forjado.
     * Depois do handler do Cashier (grava a subscrição local), por esta ordem:
     * 1. cliente desconhecido ou admin → nada;
     * 2. estado que não é trialing/active (ex.: incomplete) → só fica sincronizada;
     * 3. já existe outra subscrição em curso → cancela esta (duplicada);
     * 4. conta que não pode pagar (bloqueada, recusada, apagada, pendente) → cancela esta;
     * 5. conta já Active sem outra subscrição (ex.: criada no dashboard) → nada;
     * 6. senão → Active, sem motivo de inatividade nem carência, com o fim do trial do Stripe,
     *    e email de boas-vindas (depois do commit).
     * O trial_ends_at é gravado depois do handler do Cashier, que o põe a null.
     *
     * O utilizador é bloqueado (lockForUpdate) antes do handler do Cashier, para serializar com um
     * bloqueio feito pelo admin ao mesmo tempo: ou a ativação faz commit primeiro (e o cancel() do
     * bloqueio já vê esta subscrição), ou o bloqueio faz commit primeiro (e aqui lê-se a conta
     * bloqueada e cancela-se). Tem de ser antes: o insert da subscrição prende a linha do utilizador
     * pela chave estrangeira, e pedir o lock exclusivo depois disso podia dar deadlock com o bloqueio.
     * A decisão usa o utilizador relido depois do Cashier, também com lockForUpdate: uma leitura com
     * lock devolve sempre a última versão commitada, enquanto uma leitura simples (ex.: fresh()) em
     * REPEATABLE READ podia devolver um retrato antigo e ignorar um bloqueio já feito. O lock já é
     * desta transação, por isso não espera. withTrashed() para decidir também sobre contas apagadas.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionCreated(array $payload)
    {
        $data = $payload['data']['object'];

        $locked = $this->lockUserByCustomer($data['customer'] ?? null);

        $response = parent::handleCustomerSubscriptionCreated($payload);

        $user = $this->relockUser($locked);

        if (! $user || $user->role?->name === 'Admin') {
            return $response;
        }

        if (in_array($data['status'], self::RUNNING_STATUSES, true)) {
            $this->applySubscriptionToAccount($user, $data);
        }

        return $response;
    }

    /**
     * Regra única de ativação, usada pelo created e pelo updated (subscrição incomplete que passa
     * a ativa, ex.: 3-D Secure confirmado depois do Checkout). Recebe uma subscrição trialing/
     * active, já sincronizada pelo Cashier, e o utilizador relido com lock (passos 3–6 do created).
     *
     * @param  array<string, mixed>  $data  Objeto subscrição do evento.
     */
    private function applySubscriptionToAccount(User $user, array $data): void
    {
        if ($user->hasOngoingSubscription(exceptStripeId: $data['id'])) {
            $this->cancelSubscription($user, $data['id'], 'Subscrição duplicada cancelada.');

            return;
        }

        if ($user->activationType() === null) {
            if ($user->accountStatus?->name !== 'Active') {
                $this->cancelSubscription($user, $data['id'], 'Subscrição de conta não elegível cancelada.');
            }

            return;
        }

        $trialEndsAt = isset($data['trial_end']) ? Carbon::createFromTimestamp($data['trial_end']) : null;

        $user->forceFill([
            'account_status_id' => AccountStatus::where('name', 'Active')->value('id'),
            'inactive_reason' => null,
            'grace_ends_at' => null,
            'stripe_checkout_session_id' => null,
            'trial_ends_at' => $trialEndsAt,
        ])->save();

        $user->notify(new SubscriptionActivatedNotification($trialEndsAt));
    }

    /**
     * Subscrição alterada no Stripe. Quando fica trialing/active: numa conta Active termina o
     * período de carência, mas só numa passagem real de past_due/unpaid (previous_attributes),
     * a única que prova que a dívida foi paga (um updated antigo, ex. só metadata, entregue depois
     * da falha não pode limpar a carência); noutra conta aplica a mesma regra de ativação do
     * created (subscrição incomplete que passa a ativa, ex.: 3-D Secure). Os outros estados só
     * ficam sincronizados pelo handler do Cashier.
     *
     * Uma subscrição local já terminada (canceled/incomplete_expired) é ignorada sem chamar o
     * Cashier: "canceled" é terminal no Stripe, e um evento antigo que chegasse depois do
     * cancelamento faria o Cashier ressuscitar a linha (stripe_status active, ends_at null).
     * O utilizador é bloqueado antes do Cashier e relido depois, como no created.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionUpdated(array $payload)
    {
        $data = $payload['data']['object'];

        $locked = $this->lockUserByCustomer($data['customer'] ?? null);

        if (Subscription::where('stripe_id', $data['id'])->whereIn('stripe_status', self::ENDED_STATUSES)->exists()) {
            return $this->successMethod();
        }

        // O Cashier devolve null quando apaga uma subscrição incomplete_expired.
        $response = parent::handleCustomerSubscriptionUpdated($payload) ?? $this->successMethod();

        $user = $this->relockUser($locked);

        if (! $user || $user->role?->name === 'Admin') {
            return $response;
        }

        if (! in_array($data['status'] ?? null, self::RUNNING_STATUSES, true)) {
            return $response;
        }

        if ($user->accountStatus?->name === 'Active') {
            if (in_array($payload['data']['previous_attributes']['status'] ?? null, self::OVERDUE_STATUSES, true)) {
                $this->endGracePeriod($user);
            }
        } else {
            $this->applySubscriptionToAccount($user, $data);
        }

        return $response;
    }

    /**
     * Subscrição terminada no Stripe: fim de um cancelamento pedido pelo membro (ou feito no
     * dashboard), ou rede de segurança do comando subscriptions:end-grace (ex.: scheduler parado).
     * Depois do handler do Cashier (marca a linha local cancelada), uma conta Active sem outra
     * subscrição em curso fica Inactive e recebe o link de reativação. Motivo: unpaid se estava em
     * carência (o nosso estado, fiável) ou se o Stripe cancelou por falta de pagamento (cobre um
     * payment_failed perdido); senão cancelled, sem acusar o membro de não pagar.
     * Nada muda para contas que não estão Active (bloqueadas, já desativadas pelo comando,
     * apagadas), admins, nem quando há outra subscrição em curso (era um duplicado cancelado pelo
     * webhook created; sem esta guarda o membro perdia o acesso).
     * O utilizador é bloqueado antes do Cashier e relido depois, como no created.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionDeleted(array $payload)
    {
        $data = $payload['data']['object'];

        $locked = $this->lockUserByCustomer($data['customer'] ?? null);

        $response = parent::handleCustomerSubscriptionDeleted($payload);

        $user = $this->relockUser($locked);

        if (! $user
            || $user->role?->name === 'Admin'
            || $user->accountStatus?->name !== 'Active'
            || $user->hasOngoingSubscription()) {
            return $response;
        }

        // A carência é lida antes do deactivate(), que a limpa.
        $unpaid = $user->grace_ends_at !== null
            || ($data['cancellation_details']['reason'] ?? null) === 'payment_failed';

        $user->deactivate($unpaid ? InactiveReason::Unpaid->value : InactiveReason::Cancelled->value);
        app(SubscriptionService::class)->sendReactivationLink($user);

        return $response;
    }

    /**
     * Cobrança da quota falhada: a 1.ª falha abre o período de carência (dias configuráveis) e
     * avisa o membro por email, depois do commit. O Cashier não trata este evento.
     * Não faz nada se: a fatura já está paga; não é de uma subscrição (fatura avulsa); o cliente
     * não é nosso, é admin ou a conta não está Active; a subscrição não é uma subscrição local
     * deste utilizador ainda não terminada (ex.: falha atrasada da subscrição antiga depois de uma
     * reativação, ou id de outro membro); a carência já está a contar (os retries do Stripe não a
     * prolongam, senão nunca terminava); o pagamento desta fatura já foi registado (evento
     * atrasado: o payload de uma falha diz sempre "não paga", a linha em invoices é que prova o
     * pagamento).
     * Uma fatura com a subscrição no topo (formato anterior à API basil) só deixa um aviso no log,
     * sem dados pessoais: é um erro de configuração do endpoint no Stripe e, sem o aviso, as falhas
     * eram ignoradas em silêncio.
     * O utilizador é lido com lockForUpdate para serializar com um pagamento do mesmo membro
     * entregue em paralelo.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleInvoicePaymentFailed(array $payload)
    {
        $invoice = $payload['data']['object'];
        $subscriptionId = $invoice['parent']['subscription_details']['subscription'] ?? null;

        if (($invoice['status'] ?? null) === 'paid') {
            return $this->successMethod();
        }

        if (blank($subscriptionId)) {
            if (filled($invoice['subscription'] ?? null)) {
                Log::warning('Falha de pagamento num formato antigo da API do Stripe; carência não aberta.', [
                    'stripe_invoice_id' => $invoice['id'],
                    'api_version' => $payload['api_version'] ?? null,
                ]);
            }

            return $this->successMethod();
        }

        $user = $this->lockUserByCustomer($invoice['customer'] ?? null);

        if (! $user
            || $user->role?->name === 'Admin'
            || $user->accountStatus?->name !== 'Active'
            || $user->grace_ends_at !== null
            || ! $this->ownsOngoingSubscription($user, $subscriptionId)
            || Invoice::where('stripe_invoice_id', $invoice['id'])->exists()) {
            return $this->successMethod();
        }

        $graceEndsAt = now()->addDays(config('subscription.grace_days'));

        $user->forceFill(['grace_ends_at' => $graceEndsAt])->save();

        $user->notify(new PaymentFailedNotification($graceEndsAt));

        return $this->successMethod();
    }

    /**
     * Cancela já no Stripe uma subscrição que não devia existir e esquece a sessão de Checkout
     * que a criou (senão, sempre "complete", impedia um pagamento futuro, ex. depois de um
     * desbloqueio). O log leva só o id da subscrição (sem dados pessoais).
     */
    private function cancelSubscription(User $user, string $stripeSubscriptionId, string $reason): void
    {
        $subscription = $user->subscriptions()->where('stripe_id', $stripeSubscriptionId)->firstOrFail();

        app(StripeSubscriptionService::class)->cancelNow($subscription);

        $user->forceFill(['stripe_checkout_session_id' => null])->save();

        Log::warning($reason, ['stripe_subscription_id' => $stripeSubscriptionId]);
    }

    /**
     * Pagamento recebido: termina o período de carência, grava a fatura pendente e põe a emissão
     * em fila depois do commit
     * (um rollback do webhook nunca emite fatura).
     * Corre primeiro o handler do Cashier, que no Checkout limpa o metadata da sessão no Stripe.
     * Pagamentos de 0 (início do trial) não têm fatura; um cliente que não é nosso só fica no log.
     * O mesmo pagamento (stripe_invoice_id) nunca gera duas faturas, mesmo vindo em eventos diferentes.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleInvoicePaymentSucceeded(array $payload)
    {
        $response = parent::handleInvoicePaymentSucceeded($payload);

        $object = $payload['data']['object'];

        if (($object['amount_paid'] ?? 0) <= 0) {
            return $response;
        }

        $user = $this->getUserByStripeId($object['customer'] ?? null);

        if (! $user) {
            Log::warning('Pagamento Stripe sem utilizador correspondente; fatura não emitida.', [
                'stripe_invoice_id' => $object['id'],
                'stripe_customer_id' => $object['customer'] ?? null,
            ]);

            return $response;
        }

        $this->endGracePeriod($user);

        $invoice = $user->issuedInvoices()->createOrFirst(
            ['stripe_invoice_id' => $object['id']],
            [
                'provider' => config('services.invoicing.driver'),
                'amount' => $object['amount_paid'],
                'currency' => strtolower($object['currency']),
                'status' => InvoiceStatus::Pending,
            ],
        );

        if ($invoice->wasRecentlyCreated) {
            IssueInvoiceJob::dispatch($invoice->id)->afterCommit();
        }

        return $response;
    }

    /**
     * Indica se a subscrição do evento é uma subscrição local deste utilizador ainda não terminada.
     */
    private function ownsOngoingSubscription(User $user, string $stripeSubscriptionId): bool
    {
        return Subscription::where('user_id', $user->id)
            ->where('stripe_id', $stripeSubscriptionId)
            ->whereNotIn('stripe_status', self::ENDED_STATUSES)
            ->exists();
    }

    /**
     * Termina o período de carência (pagamento recebido). Update direto e condicional: não toca
     * em contas sem carência nem reescreve o resto da linha.
     */
    private function endGracePeriod(User $user): void
    {
        User::withTrashed()->whereKey($user->id)->whereNotNull('grace_ends_at')->update(['grace_ends_at' => null]);
    }

    /**
     * Lê com lockForUpdate o utilizador do cliente Stripe (também contas apagadas), com o estado
     * e o papel. Nos handlers de subscrição corre antes do handler do Cashier, para serializar com
     * um bloqueio feito pelo admin sem risco de deadlock (ver handleCustomerSubscriptionCreated).
     */
    private function lockUserByCustomer(?string $customer): ?User
    {
        return filled($customer)
            ? User::withTrashed()->with(['accountStatus', 'role'])->where('stripe_id', $customer)->lockForUpdate()->first()
            : null;
    }

    /**
     * Relê com lockForUpdate o utilizador já bloqueado, depois do handler do Cashier: uma leitura
     * com lock devolve sempre a última versão commitada (o lock já é desta transação, não espera).
     */
    private function relockUser(?User $locked): ?User
    {
        return $locked
            ? User::withTrashed()->with(['accountStatus', 'role'])->whereKey($locked->id)->lockForUpdate()->first()
            : null;
    }

    /**
     * 500 genérico, sem detalhes internos.
     */
    private function serverError(): Response
    {
        return $this->errorResponse(
            'Ocorreu um erro interno. Tente novamente mais tarde.',
            Response::HTTP_INTERNAL_SERVER_ERROR
        );
    }
}
