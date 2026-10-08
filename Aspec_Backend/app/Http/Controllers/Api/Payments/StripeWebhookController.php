<?php

namespace App\Http\Controllers\Api\Payments;

use App\Enums\InvoiceStatus;
use App\Jobs\IssueInvoiceJob;
use App\Models\ProcessedWebhookEvent;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Webhook do Stripe. A assinatura é verificada pelo middleware do Cashier (aplicado no
 * construtor dele quando há STRIPE_WEBHOOK_SECRET); os handlers do Cashier sincronizam as
 * subscrições locais e este controller acrescenta a idempotência e a fatura de cada pagamento.
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
