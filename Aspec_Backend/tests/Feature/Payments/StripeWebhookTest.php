<?php

namespace Tests\Feature\Payments;

use App\Contracts\InvoiceService;
use App\Enums\InvoiceStatus;
use App\Jobs\IssueInvoiceJob;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Payments\StripeCustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stripe\Exception\ApiConnectionException;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * Webhook do Stripe: assinatura obrigatória (fail closed), idempotência pelo id do evento,
 * fatura por pagamento e sincronização das subscrições feita pelo Cashier.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase, SignsStripeWebhooks;

    private const CUSTOMER = 'cus_test_1';

    private const FORBIDDEN = 'Não tem permissão para realizar esta ação.';

    private const SERVER_ERROR = 'Ocorreu um erro interno. Tente novamente mais tarde.';

    private function customer(?User $user = null): User
    {
        $user ??= User::factory()->withBilling()->create();
        $user->forceFill(['stripe_id' => self::CUSTOMER])->save();

        return $user;
    }

    private function unhandledEvent(?string $id = null): array
    {
        return $this->stripeEvent('charge.succeeded', ['id' => 'ch_test_1', 'object' => 'charge'], $id);
    }

    private function paymentEvent(int $amountPaid = 6000, ?string $invoiceId = null, ?string $eventId = null): array
    {
        return $this->stripeEvent(
            'invoice.payment_succeeded',
            $this->stripeInvoice(self::CUSTOMER, $amountPaid, $invoiceId),
            $eventId,
        );
    }

    private function subscribe(User $user, string $status = 'active'): void
    {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_1',
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);
    }

    #[Test]
    public function signed_event_without_handler_is_accepted_and_recorded(): void
    {
        $event = $this->unhandledEvent();

        $this->postStripeWebhook($event)->assertOk();

        $this->assertDatabaseCount('processed_webhook_events', 1);
        $this->assertDatabaseHas('processed_webhook_events', [
            'stripe_event_id' => $event['id'],
            'type' => 'charge.succeeded',
        ]);
    }

    #[Test]
    public function request_without_signature_is_forbidden(): void
    {
        $json = json_encode($this->unhandledEvent());

        $response = $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $json);

        $response->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', self::FORBIDDEN);
        $this->assertDatabaseCount('processed_webhook_events', 0);
    }

    #[Test]
    public function event_signed_with_another_secret_is_forbidden(): void
    {
        $this->postStripeWebhook($this->unhandledEvent(), 'whsec_outro')
            ->assertForbidden()
            ->assertJsonPath('message', self::FORBIDDEN);

        $this->assertDatabaseCount('processed_webhook_events', 0);
    }

    #[Test]
    public function event_signed_ten_minutes_ago_is_forbidden_to_prevent_replay(): void
    {
        $this->postStripeWebhook($this->unhandledEvent(), timestamp: time() - 600)
            ->assertForbidden()
            ->assertJsonPath('message', self::FORBIDDEN);

        $this->assertDatabaseCount('processed_webhook_events', 0);
    }

    #[Test]
    public function webhook_fails_closed_when_the_secret_is_not_configured(): void
    {
        // Sem segredo o Cashier não aplica a verificação; aceitar seria aceitar qualquer POST anónimo.
        config(['cashier.webhook.secret' => null]);
        Log::spy();

        $this->postStripeWebhook($this->unhandledEvent())
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', self::SERVER_ERROR);

        $this->assertDatabaseCount('processed_webhook_events', 0);
        Log::shouldHaveReceived('critical')
            ->withArgs(fn (string $message) => $message === 'STRIPE_WEBHOOK_SECRET não configurado; webhook recusado.')
            ->once();
    }

    #[Test]
    public function missing_secret_is_logged_as_critical_at_most_once_per_hour(): void
    {
        // Cada entrega recusada do Stripe repete o pedido: sem limite, o log crítico encheria os alertas.
        config(['cashier.webhook.secret' => null]);
        Cache::forget('stripe-webhook-secret-missing');
        Log::spy();

        $this->postStripeWebhook($this->unhandledEvent())
            ->assertStatus(500)
            ->assertJsonPath('message', self::SERVER_ERROR);
        $this->postStripeWebhook($this->unhandledEvent())
            ->assertStatus(500)
            ->assertJsonPath('message', self::SERVER_ERROR);

        Log::shouldHaveReceived('critical')->once();

        $this->travel(61)->minutes();

        $this->postStripeWebhook($this->unhandledEvent())->assertStatus(500);

        Log::shouldHaveReceived('critical')->twice();
    }

    #[Test]
    public function same_event_delivered_twice_is_recorded_once(): void
    {
        $event = $this->unhandledEvent();

        $this->postStripeWebhook($event)->assertOk();
        $this->postStripeWebhook($event)->assertOk();

        $this->assertDatabaseCount('processed_webhook_events', 1);
    }

    #[Test]
    public function repeated_handled_event_answers_webhook_handled(): void
    {
        $this->customer();
        Queue::fake();
        $event = $this->paymentEvent();

        $this->postStripeWebhook($event)->assertOk();
        $response = $this->postStripeWebhook($event)->assertOk();

        $this->assertSame('Webhook Handled', $response->getContent());
    }

    #[Test]
    public function processing_failure_rolls_back_the_event_so_the_stripe_retry_succeeds(): void
    {
        $event = $this->unhandledEvent();
        Event::listen(WebhookReceived::class, fn () => throw new RuntimeException('falha no processamento'));

        $this->postStripeWebhook($event)
            ->assertStatus(500)
            ->assertJsonPath('message', self::SERVER_ERROR);
        $this->assertDatabaseCount('processed_webhook_events', 0);

        Event::forget(WebhookReceived::class);

        $this->postStripeWebhook($event)->assertOk();
        $this->assertDatabaseCount('processed_webhook_events', 1);
    }

    #[Test]
    public function webhook_route_is_under_api_and_cashier_route_stays_unregistered(): void
    {
        $this->assertTrue(Route::has('stripe.webhook'));
        $this->assertSame('/api/stripe/webhook', route('stripe.webhook', absolute: false));

        $this->postJson('/stripe/webhook')->assertNotFound();
    }

    #[Test]
    public function payment_creates_a_pending_invoice_and_queues_its_issuing(): void
    {
        Queue::fake();
        $user = $this->customer();
        $event = $this->paymentEvent(6000, 'in_test_pagamento');

        $this->postStripeWebhook($event)->assertOk();

        $this->assertDatabaseCount('invoices', 1);
        $invoice = Invoice::firstOrFail();
        $this->assertSame($user->id, $invoice->user_id);
        $this->assertSame('in_test_pagamento', $invoice->stripe_invoice_id);
        $this->assertSame(InvoiceStatus::Pending, $invoice->status);
        $this->assertSame(6000, $invoice->amount);
        $this->assertSame('eur', $invoice->currency);
        $this->assertSame('log', $invoice->provider);
        Queue::assertPushed(IssueInvoiceJob::class, fn (IssueInvoiceJob $job) => $job->invoiceId === $invoice->id);
    }

    #[Test]
    public function same_payment_event_twice_creates_one_invoice_and_one_job(): void
    {
        Queue::fake();
        $this->customer();
        $event = $this->paymentEvent();

        $this->postStripeWebhook($event)->assertOk();
        $this->postStripeWebhook($event)->assertOk();

        $this->assertDatabaseCount('invoices', 1);
        Queue::assertPushed(IssueInvoiceJob::class, 1);
    }

    #[Test]
    public function two_events_for_the_same_stripe_invoice_create_one_invoice_and_one_job(): void
    {
        // O Stripe pode enviar eventos diferentes (ids diferentes) sobre o mesmo pagamento.
        Queue::fake();
        $this->customer();

        $this->postStripeWebhook($this->paymentEvent(6000, 'in_test_mesmo'))->assertOk();
        $this->postStripeWebhook($this->paymentEvent(6000, 'in_test_mesmo'))->assertOk();

        $this->assertDatabaseCount('processed_webhook_events', 2);
        $this->assertDatabaseCount('invoices', 1);
        Queue::assertPushed(IssueInvoiceJob::class, 1);
    }

    #[Test]
    public function zero_amount_payment_at_trial_start_creates_no_invoice(): void
    {
        Queue::fake();
        $this->customer();

        $this->postStripeWebhook($this->paymentEvent(0))->assertOk();

        $this->assertDatabaseCount('invoices', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function payment_from_an_unknown_customer_is_only_logged(): void
    {
        Queue::fake();
        Mail::fake();
        Log::spy();
        $event = $this->stripeEvent('invoice.payment_succeeded', $this->stripeInvoice('cus_desconhecido', 6000, 'in_test_alheio'));

        $this->postStripeWebhook($event)->assertOk();

        $this->assertDatabaseCount('invoices', 0);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => $message === 'Pagamento Stripe sem utilizador correspondente; fatura não emitida.'
                && ($context['stripe_invoice_id'] ?? null) === 'in_test_alheio'
                && ($context['stripe_customer_id'] ?? null) === 'cus_desconhecido')
            ->once();
    }

    #[Test]
    public function payment_is_invoiced_end_to_end_with_the_log_driver(): void
    {
        $this->customer();

        $this->postStripeWebhook($this->paymentEvent())->assertOk();

        $invoice = Invoice::firstOrFail();
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertMatchesRegularExpression('/^LOG-\d{4}-[A-F0-9]{8}$/', $invoice->number);
        $this->assertNotNull($invoice->issued_at);
    }

    #[Test]
    public function rollback_leaves_no_invoice_and_never_issues_it(): void
    {
        // A fila sync corre o job logo; só não corre se o dispatch esperar pelo commit que nunca acontece.
        $this->customer();
        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('issue');
        });
        Event::listen(WebhookHandled::class, fn () => throw new RuntimeException('falha depois do handler'));

        $this->postStripeWebhook($this->paymentEvent())
            ->assertStatus(500)
            ->assertJsonPath('message', self::SERVER_ERROR);

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('processed_webhook_events', 0);
    }

    #[Test]
    public function soft_deleted_user_who_paid_is_still_invoiced(): void
    {
        Queue::fake();
        $user = $this->customer();
        $user->delete();

        $this->postStripeWebhook($this->paymentEvent())->assertOk();

        $this->assertDatabaseHas('invoices', ['user_id' => $user->id, 'status' => 'pending']);
        Queue::assertPushed(IssueInvoiceJob::class, 1);
    }

    #[Test]
    public function subscription_created_is_stored_locally_without_changing_the_account_state(): void
    {
        $user = $this->customer(User::factory()->approved()->withBilling()->create());
        $user->forceFill(['trial_ends_at' => now()->addDays(30)])->save();

        $this->postStripeWebhook($this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscription(self::CUSTOMER, 'trialing'),
        ))->assertOk();

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'stripe_id' => 'sub_test_1',
            'stripe_status' => 'trialing',
        ]);
        $fresh = $user->fresh();
        $this->assertSame('Approved', $fresh->accountStatus->name);
        // O Cashier termina o "trial genérico" do utilizador ao criar a subscrição.
        $this->assertNull($fresh->trial_ends_at);
    }

    #[Test]
    public function subscription_deleted_cancels_the_local_subscription(): void
    {
        $user = $this->customer();
        $this->subscribe($user);

        $this->postStripeWebhook($this->stripeEvent(
            'customer.subscription.deleted',
            $this->stripeSubscription(self::CUSTOMER, 'canceled'),
        ))->assertOk();

        $subscription = DB::table('subscriptions')->where('stripe_id', 'sub_test_1')->first();
        $this->assertSame('canceled', $subscription->stripe_status);
        $this->assertNotNull($subscription->ends_at);
    }

    #[Test]
    public function customer_deleted_clears_stripe_columns_and_cancels_subscriptions(): void
    {
        $user = $this->customer();
        $user->forceFill(['pm_type' => 'visa', 'pm_last_four' => '4242'])->save();
        $this->subscribe($user);

        $this->postStripeWebhook($this->stripeEvent(
            'customer.deleted',
            ['id' => self::CUSTOMER, 'object' => 'customer'],
        ))->assertOk();

        $fresh = $user->fresh();
        $this->assertNull($fresh->stripe_id);
        $this->assertNull($fresh->pm_type);
        $this->assertNull($fresh->pm_last_four);
        $this->assertDatabaseHas('subscriptions', ['stripe_id' => 'sub_test_1', 'stripe_status' => 'canceled']);
    }

    #[Test]
    public function customer_deleted_clears_stripe_id_of_an_anonymized_user(): void
    {
        // O Stripe falhou na anonimização: o stripe_id ficou para o job de recurso; o webhook limpa-o.
        Queue::fake();
        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('delete')->andThrow(ApiConnectionException::factory('falha'));
        });
        $user = $this->customer();
        $this->subscribe($user);
        $user->anonymizeAndDelete();
        $this->assertSame(self::CUSTOMER, User::withTrashed()->find($user->id)->stripe_id);

        $this->postStripeWebhook($this->stripeEvent(
            'customer.deleted',
            ['id' => self::CUSTOMER, 'object' => 'customer'],
        ))->assertOk();

        $this->assertNull(User::withTrashed()->find($user->id)->stripe_id);
        $this->assertDatabaseHas('subscriptions', ['stripe_id' => 'sub_test_1', 'stripe_status' => 'canceled']);
    }
}
