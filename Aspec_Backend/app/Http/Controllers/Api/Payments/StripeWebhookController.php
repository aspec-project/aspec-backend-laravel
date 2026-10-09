<?php

namespace App\Http\Controllers\Api\Payments;

use App\Enums\InvoiceStatus;
use App\Jobs\IssueInvoiceJob;
use App\Models\AccountStatus;
use App\Models\ProcessedWebhookEvent;
use App\Models\User;
use App\Notifications\SubscriptionActivatedNotification;
use App\Services\Payments\StripeSubscriptionService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Stripe\Subscription as StripeSubscription;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Webhook do Stripe. A assinatura é verificada pelo middleware do Cashier (aplicado no
 * construtor dele quando há STRIPE_WEBHOOK_SECRET); os handlers do Cashier sincronizam as
 * subscrições locais e este controller acrescenta a idempotência, a ativação da conta quando a
 * subscrição é criada e a fatura de cada pagamento.
 */
class StripeWebhookController extends WebhookController
{
    use ApiResponse;

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

        $locked = filled($data['customer'] ?? null)
            ? User::withTrashed()->where('stripe_id', $data['customer'])->lockForUpdate()->first()
            : null;

        $response = parent::handleCustomerSubscriptionCreated($payload);

        $user = $locked
            ? User::withTrashed()->with(['accountStatus', 'role'])->whereKey($locked->id)->lockForUpdate()->first()
            : null;

        if (! $user || $user->role?->name === 'Admin') {
            return $response;
        }

        if (! in_array($data['status'], [StripeSubscription::STATUS_TRIALING, StripeSubscription::STATUS_ACTIVE], true)) {
            return $response;
        }

        if ($user->hasOngoingSubscription(exceptStripeId: $data['id'])) {
            $this->cancelSubscription($user, $data['id'], 'Subscrição duplicada cancelada.');

            return $response;
        }

        if ($user->activationType() === null) {
            if ($user->accountStatus?->name !== 'Active') {
                $this->cancelSubscription($user, $data['id'], 'Subscrição de conta não elegível cancelada.');
            }

            return $response;
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

        return $response;
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
     * Pagamento recebido: grava a fatura pendente e põe a emissão em fila depois do commit
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
