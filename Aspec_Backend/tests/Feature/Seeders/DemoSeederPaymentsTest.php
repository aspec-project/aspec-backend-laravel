<?php

namespace Tests\Feature\Seeders;

use App\Contracts\InvoiceService;
use App\Enums\InactiveReason;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionState;
use App\Models\AccountStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Rules\PortugueseNif;
use App\Services\Invoicing\Data\InvoiceCustomerData;
use App\Services\Invoicing\Data\InvoiceData;
use App\Services\Invoicing\Data\IssuedInvoice;
use App\Services\Payments\StripeCustomerService;
use App\Services\Payments\StripeSubscriptionService;
use Closure;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Laravel\Cashier\Subscription;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stripe\Exception\InvalidRequestException;
use Tests\Concerns\SeedsDemoData;
use Tests\TestCase;
use Throwable;

/**
 * DemoSeeder: subscrições, faturas e dados de faturação ao longo de 18 meses, sem chamar o Stripe
 * nem a InvoiceExpress, e o que o painel e a lista de utilizadores do backoffice mostram.
 */
class DemoSeederPaymentsTest extends TestCase
{
    use RefreshDatabase, SeedsDemoData;

    /** NIFs de demonstração da versão anterior (dígito de controlo válido), que se mantêm. */
    private const FIXED_NIFS = [
        '509000010', '509000029', '509000037', '509000045', '509000053', '509000061', '509000070', '509000088',
        '509000096', '509000100', '509000118', '509000126', '509000134', '509000142', '509000150',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDemoSeeder();
    }

    /**
     * Início do UUID da conta sem hífenes, de onde derivam os ids falsos do Stripe da demonstração.
     */
    private function demoIdFragment(User $user): string
    {
        return str_replace('-', '', $user->id);
    }

    private function subscriptionOf(User|string $user): Subscription
    {
        $user = is_string($user) ? $this->demoUser($user) : $user;
        $subscriptions = Subscription::where('user_id', $user->id)->get();
        $this->assertCount(1, $subscriptions, "{$user->email}: subscrições");

        return $subscriptions->first();
    }

    /**
     * Faturas da conta, da mais antiga para a mais recente.
     *
     * @return Collection<int, Invoice>
     */
    private function invoicesOf(User|string $user): Collection
    {
        $user = is_string($user) ? $this->demoUser($user) : $user;

        return Invoice::where('user_id', $user->id)->orderBy('period_start')->get()->values();
    }

    /**
     * Contas demo num estado derivado da subscrição.
     *
     * @return EloquentCollection<int, User>
     */
    private function usersInState(SubscriptionState $state): EloquentCollection
    {
        return $this->demoUsers()->filter(fn (User $user) => $user->subscriptionState() === $state)->values();
    }

    /**
     * Número de períodos da quota anual já iniciados desde o fim do trial (o primeiro começa
     * quando o trial acaba e cada um dura um ano).
     */
    private function yearlyPeriodsStartedSince(Carbon $trialEndsAt): int
    {
        $periods = 0;
        $start = $trialEndsAt->copy();

        while ($start->lessThanOrEqualTo(now())) {
            $periods++;
            $start = $start->copy()->addYearNoOverflow();
        }

        return $periods;
    }

    /**
     * Mailer "espião" como mailer por omissão: o seeder tem de trocar para o array durante a
     * emissão das faturas demo, por isso nada pode chegar a este.
     */
    private function useSpyMailerAsDefault(): void
    {
        config([
            'mail.mailers.demo_spy' => ['transport' => 'array'],
            'mail.default' => 'demo_spy',
        ]);
    }

    private function spyMailerMessageCount(): int
    {
        return app('mail.manager')->mailer('demo_spy')->getSymfonyTransport()->messages()->count();
    }

    /**
     * Serviço de faturação que falha sempre e conta as chamadas (o job resolve o contrato pelo container).
     */
    private function failingInvoiceService(): InvoiceService
    {
        return new class implements InvoiceService
        {
            public int $calls = 0;

            public function issue(InvoiceCustomerData $customer, InvoiceData $invoice, ?string $draftId = null, ?Closure $onDraftCreated = null): IssuedInvoice
            {
                $this->calls++;

                throw new RuntimeException('Falha simulada na emissão.');
            }

            public function pdf(string $providerInvoiceId): ?string
            {
                return null;
            }
        };
    }

    /**
     * Cenários das contas usadas nos guiões de teste: estado derivado e estado da subscrição local.
     */
    #[Test]
    public function fixed_demo_accounts_keep_the_subscription_of_their_scenario(): void
    {
        $this->runDemoSeeder();

        $expected = [
            'demo.membro@aspec.test' => [SubscriptionState::Active, 'active'],
            'demo.admin@aspec.test' => [SubscriptionState::Active, 'active'],
            'demo.membro07@aspec.test' => [SubscriptionState::Grace, 'past_due'],
            'demo.membro02@aspec.test' => [SubscriptionState::Trial, 'trialing'],
            'demo.inativo.pagamento@aspec.test' => [SubscriptionState::Lapsed, 'canceled'],
            'demo.bloqueado@aspec.test' => [SubscriptionState::Blocked, 'canceled'],
            'demo.aprovado@aspec.test' => [SubscriptionState::AwaitingActivation, null],
            'demo.pendente01@aspec.test' => [SubscriptionState::Pending, null],
            'demo.pendente02@aspec.test' => [SubscriptionState::Pending, null],
            'demo.pendente03@aspec.test' => [SubscriptionState::Pending, null],
        ];

        foreach ($expected as $email => [$state, $stripeStatus]) {
            $user = $this->demoUser($email);

            $this->assertSame($state, $user->subscriptionState(), $email);

            if ($stripeStatus === null) {
                $this->assertSame(0, Subscription::where('user_id', $user->id)->count(), $email);

                continue;
            }

            $this->assertSame($stripeStatus, $this->subscriptionOf($user)->stripe_status, $email);
        }

        $scheduledCancel = $this->subscriptionOf('demo.membro02@aspec.test');
        $this->assertTrue($scheduledCancel->trial_ends_at->isFuture());
        $this->assertSame($scheduledCancel->trial_ends_at->getTimestamp(), $scheduledCancel->ends_at?->getTimestamp());
    }

    /**
     * Estados derivados com as quantidades do plano: ~40 ativas, ~12 em trial, 2 em carência,
     * 3 com a subscrição caída (2 por falta de pagamento e 1 cancelada), 1 bloqueada, 2 por ativar
     * e 7 pendentes; nenhuma isenta.
     */
    #[Test]
    public function derived_subscription_states_have_the_planned_volumes(): void
    {
        $this->runDemoSeeder();

        $counts = $this->demoUsers()->countBy(fn (User $user) => $user->subscriptionState()->value);

        $this->assertGreaterThanOrEqual(38, $counts['active'] ?? 0);
        $this->assertLessThanOrEqual(48, $counts['active'] ?? 0);
        $this->assertGreaterThanOrEqual(10, $counts['trial'] ?? 0);
        $this->assertLessThanOrEqual(16, $counts['trial'] ?? 0);
        $this->assertSame(2, $counts['grace'] ?? 0);
        $this->assertSame(3, $counts['lapsed'] ?? 0);
        $this->assertSame(1, $counts['blocked'] ?? 0);
        $this->assertSame(2, $counts['awaiting_activation'] ?? 0);
        $this->assertSame(7, $counts['pending'] ?? 0);
        $this->assertSame(0, $counts['exempt'] ?? 0);
        $this->assertSame(0, User::where('subscription_exempt', true)->count());
    }

    /**
     * Cada conta que já ativou tem uma subscrição como o Cashier a grava (tipo default, um item) e
     * as datas de trial iguais na conta e na subscrição.
     */
    #[Test]
    public function every_activated_account_has_one_cashier_like_subscription(): void
    {
        $this->runDemoSeeder();

        foreach ($this->demoUsers() as $user) {
            $email = $user->email;
            $subscriptions = Subscription::with('items')->where('user_id', $user->id)->get();

            if (in_array($user->accountStatus->name, ['Pending', 'Approved'], true)) {
                $this->assertCount(0, $subscriptions, $email);

                continue;
            }

            $this->assertCount(1, $subscriptions, $email);
            $subscription = $subscriptions->first();

            $this->assertSame('default', $subscription->type, $email);
            $this->assertSame(1, $subscription->quantity, $email);
            $this->assertCount(1, $subscription->items, $email);
            $this->assertSame(1, $subscription->items->first()->quantity, $email);
            $this->assertSame($subscription->trial_ends_at?->getTimestamp(), $user->trial_ends_at?->getTimestamp(), $email);
        }
    }

    /**
     * Contas criadas ao longo dos últimos 18 meses (algumas com mais de um ano), o trial a começar
     * 1 a 5 dias depois do registo e as candidaturas pendentes nos últimos 10 dias.
     */
    #[Test]
    public function accounts_are_created_over_the_last_eighteen_months(): void
    {
        $this->runDemoSeeder();

        $trialDays = (int) config('subscription.trial_days');
        $users = $this->demoUsers();

        foreach ($users as $user) {
            $this->assertTrue($user->created_at->lessThanOrEqualTo(now()), $user->email);
            $this->assertTrue($user->created_at->greaterThanOrEqualTo(now()->subMonths(18)->subDay()), $user->email);
        }

        $this->assertTrue($users->contains(fn (User $user) => $user->created_at->lessThan(now()->subYear())));

        foreach ($users->filter(fn (User $user) => $user->accountStatus->name === 'Pending') as $user) {
            $this->assertTrue($user->created_at->greaterThanOrEqualTo(now()->subDays(10)), $user->email);
        }

        foreach (Subscription::with('user')->get() as $subscription) {
            $email = $subscription->user->email;
            $daysAfterRegistration = $subscription->user->created_at->diffInDays($subscription->created_at);

            $this->assertGreaterThanOrEqual(1, $daysAfterRegistration, $email);
            $this->assertLessThanOrEqual(5, $daysAfterRegistration, $email);
            $this->assertAbout($subscription->trial_ends_at->copy()->subDays($trialDays), $subscription->created_at, $email);
        }
    }

    /**
     * Contas em trial: criadas há menos de 30 dias, trial a acabar entre 7 e 28 dias depois de
     * agora (sem webhooks, um trial no passado ficaria "trialing"), sem faturas nem carência.
     */
    #[Test]
    public function trial_accounts_end_their_trial_between_one_and_four_weeks_from_now(): void
    {
        $this->runDemoSeeder();

        $trials = $this->usersInState(SubscriptionState::Trial);
        $this->assertNotEmpty($trials);

        foreach ($trials as $user) {
            $email = $user->email;
            $subscription = $this->subscriptionOf($user);

            $this->assertSame('trialing', $subscription->stripe_status, $email);
            $this->assertTrue($user->created_at->greaterThan(now()->subDays(30)), $email);
            $this->assertGreaterThanOrEqual(now()->addDays(7)->subMinute()->getTimestamp(), $subscription->trial_ends_at->getTimestamp(), $email);
            $this->assertLessThanOrEqual(now()->addDays(29)->getTimestamp(), $subscription->trial_ends_at->getTimestamp(), $email);
            $this->assertNull($user->grace_ends_at, $email);
            $this->assertCount(0, $this->invoicesOf($user), $email);
        }
    }

    /**
     * Contas ativas: uma fatura por ano pago (2 nos membros com mais de um ano de quota), períodos
     * seguidos e o último a cobrir hoje; uma delas tem o cancelamento agendado para o fim do período.
     */
    #[Test]
    public function active_accounts_have_one_invoice_per_paid_year(): void
    {
        $this->assertSame('year', config('subscription.interval'));

        $this->runDemoSeeder();

        $active = $this->usersInState(SubscriptionState::Active);
        $this->assertNotEmpty($active);

        foreach ($active as $user) {
            $email = $user->email;
            $subscription = $this->subscriptionOf($user);
            $invoices = $this->invoicesOf($user);

            $this->assertSame('active', $subscription->stripe_status, $email);
            $this->assertTrue($subscription->trial_ends_at->isPast(), $email);
            $this->assertNull($user->grace_ends_at, $email);
            $this->assertCount($this->yearlyPeriodsStartedSince($subscription->trial_ends_at), $invoices, $email);
            $this->assertSame($subscription->trial_ends_at->getTimestamp(), $invoices->first()->period_start->getTimestamp(), $email);

            foreach ($invoices->slice(1) as $index => $invoice) {
                $this->assertSame($invoices[$index - 1]->period_end->getTimestamp(), $invoice->period_start->getTimestamp(), $email);
            }

            $this->assertTrue($invoices->last()->period_start->lessThanOrEqualTo(now()), $email);
            $this->assertTrue($invoices->last()->period_end->isFuture(), $email);
        }

        $this->assertTrue($active->contains(fn (User $user) => $this->invoicesOf($user)->count() === 2), 'Nenhum membro com mais de um ano de quota');
        $this->assertCount(2, $this->invoicesOf('demo.membro@aspec.test'));

        $scheduled = $active->filter(fn (User $user) => $this->subscriptionOf($user)->ends_at !== null);
        $this->assertCount(1, $scheduled);
        $this->assertTrue($this->subscriptionOf($scheduled->first())->ends_at->isFuture());
    }

    /**
     * Carência: a renovação falhou no fim do último período pago e a carência termina grace_days depois.
     */
    #[Test]
    public function grace_accounts_failed_their_last_renewal_and_keep_access_until_the_grace_ends(): void
    {
        $this->runDemoSeeder();

        $graceDays = (int) config('subscription.grace_days');
        $grace = $this->usersInState(SubscriptionState::Grace);

        $this->assertCount(2, $grace);
        $this->assertContains('demo.membro07@aspec.test', $grace->pluck('email'));

        foreach ($grace as $user) {
            $email = $user->email;
            $invoices = $this->invoicesOf($user);

            $this->assertSame('past_due', $this->subscriptionOf($user)->stripe_status, $email);
            $this->assertNull($this->subscriptionOf($user)->ends_at, $email);
            $this->assertGreaterThanOrEqual(1, $invoices->count(), $email);
            $this->assertTrue($invoices->last()->period_end->isPast(), $email);
            $this->assertAbout($invoices->last()->period_end->copy()->addDays($graceDays), $user->grace_ends_at, $email, 60);
            $this->assertTrue($user->grace_ends_at->isFuture(), $email);
        }
    }

    /**
     * Subscrições terminadas: por falta de pagamento (no máximo uma fatura), cancelada pelo membro
     * depois de pagar (uma fatura) e bloqueada ainda em trial (nenhuma).
     */
    #[Test]
    public function ended_subscriptions_match_the_inactive_reason(): void
    {
        $this->runDemoSeeder();

        $inactive = $this->demoUsers()->filter(fn (User $user) => $user->accountStatus->name === 'Inactive');
        $this->assertCount(4, $inactive);

        foreach ($inactive as $user) {
            $email = $user->email;
            $subscription = $this->subscriptionOf($user);
            $invoices = $this->invoicesOf($user)->count();

            $this->assertSame('canceled', $subscription->stripe_status, $email);
            $this->assertTrue($subscription->ends_at?->isPast() ?? false, $email);
            $this->assertNull($user->grace_ends_at, $email);

            match ($user->inactive_reason) {
                InactiveReason::Unpaid => $this->assertLessThanOrEqual(1, $invoices, $email),
                InactiveReason::Cancelled => $this->assertSame(1, $invoices, $email),
                InactiveReason::Blocked => $this->assertSame(0, $invoices, $email),
                default => $this->fail("{$email}: motivo inesperado"),
            };
        }
    }

    /**
     * Ids falsos com "_demo_" derivados da conta (nunca do Stripe), sem cliente Stripe nem cartão
     * guardado, e faturação preenchida só em quem já ativou, igual à empresa e morada do perfil.
     */
    #[Test]
    public function payment_rows_use_fake_demo_ids_and_billing_only_where_the_account_was_activated(): void
    {
        $this->runDemoSeeder();

        foreach ($this->demoUsers() as $user) {
            $email = $user->email;

            $this->assertNull($user->stripe_id, $email);
            $this->assertNull($user->pm_type, $email);
            $this->assertNull($user->pm_last_four, $email);
            $this->assertNull($user->stripe_checkout_session_id, $email);

            if (in_array($user->accountStatus->name, ['Pending', 'Approved'], true)) {
                foreach (['billing_name', 'nif', 'billing_address', 'billing_postal_code', 'billing_city'] as $field) {
                    $this->assertNull($user->{$field}, "{$email}: {$field}");
                }

                continue;
            }

            $fragment = $this->demoIdFragment($user);
            $subscription = Subscription::with('items')->where('user_id', $user->id)->sole();

            $this->assertSame("sub_demo_{$fragment}", $subscription->stripe_id, $email);
            $this->assertSame("si_demo_{$fragment}", $subscription->items->sole()->stripe_id, $email);
            $this->assertSame('prod_demo', $subscription->items->sole()->stripe_product, $email);
            $this->assertSame('price_demo', $subscription->items->sole()->stripe_price, $email);

            foreach (Invoice::where('user_id', $user->id)->get() as $invoice) {
                $this->assertMatchesRegularExpression("/^in_demo_{$fragment}_\\d+$/", $invoice->stripe_invoice_id, $email);
            }

            $this->assertSame($user->memberProfile->business_name, $user->billing_name, $email);
            $this->assertSame($user->memberProfile->address, $user->billing_address, $email);
            $this->assertSame($user->memberProfile->postal_code, $user->billing_postal_code, $email);
            $this->assertSame($user->memberProfile->city, $user->billing_city, $email);
        }
    }

    /**
     * NIFs: os 15 da versão anterior e os gerados, todos com dígito de controlo válido e diferentes.
     */
    #[Test]
    public function nifs_are_valid_unique_and_keep_the_fixed_ones(): void
    {
        $this->runDemoSeeder();

        $nifs = $this->demoUsers()->pluck('nif')->filter()->values();

        $this->assertGreaterThanOrEqual(60, $nifs->count());
        $this->assertSame($nifs->count(), $nifs->unique()->count(), 'NIFs repetidos: '.$nifs->duplicates()->implode(', '));

        foreach ($nifs as $nif) {
            $validator = Validator::make(['nif' => $nif], ['nif' => ['required', new PortugueseNif]]);
            $this->assertFalse($validator->fails(), "NIF {$nif}");
        }

        foreach (self::FIXED_NIFS as $nif) {
            $this->assertContains($nif, $nifs, "NIF fixo {$nif}");
        }
    }

    /**
     * Cerca de 55 faturas anuais, todas emitidas pelo driver demo.
     */
    #[Test]
    public function creates_about_fifty_five_yearly_invoices(): void
    {
        $this->runDemoSeeder();

        $invoices = Invoice::where('stripe_invoice_id', 'like', 'in_demo_%')->get();

        $this->assertGreaterThanOrEqual(45, $invoices->count());
        $this->assertLessThanOrEqual(65, $invoices->count());
        $this->assertSame(Invoice::count(), $invoices->count());

        foreach ($invoices as $invoice) {
            $this->assertSame('demo', $invoice->provider, $invoice->stripe_invoice_id);
            $this->assertSame(InvoiceStatus::Issued, $invoice->status, $invoice->stripe_invoice_id);
            $this->assertSame($invoice->period_start->copy()->addYearNoOverflow()->getTimestamp(), $invoice->period_end->getTimestamp(), $invoice->stripe_invoice_id);
            $this->assertTrue($invoice->period_start->isPast(), $invoice->stripe_invoice_id);
        }
    }

    /**
     * Com a quota mensal o seeder continua a funcionar: períodos de um mês seguidos e o último a cobrir hoje.
     */
    #[Test]
    public function monthly_fee_produces_consecutive_monthly_periods(): void
    {
        config(['subscription.interval' => 'month']);
        $this->travelTo(now('Europe/Lisbon')->startOfMonth()->addDays(14)->setTime(12, 0));

        $this->runDemoSeeder();

        $invoices = $this->invoicesOf('demo.membro@aspec.test');
        $this->assertGreaterThanOrEqual(2, $invoices->count());

        foreach ($invoices as $index => $invoice) {
            $this->assertSame($invoice->period_start->copy()->addMonthNoOverflow()->getTimestamp(), $invoice->period_end->getTimestamp());

            if ($index > 0) {
                $this->assertSame($invoices[$index - 1]->period_end->getTimestamp(), $invoice->period_start->getTimestamp());
            }
        }

        $this->assertTrue($invoices->last()->period_start->lessThanOrEqualTo(now()));
        $this->assertTrue($invoices->last()->period_end->isFuture());

        Sanctum::actingAs($this->demoUser('demo.membro@aspec.test'));
        $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('data.next_payment_at', $invoices->last()->period_end->toIso8601String())
            ->assertJsonPath('data.price.interval', 'month');
    }

    /**
     * O que o membro vê em /conta/subscricao: tudo vem da base de dados, sem chamar o Stripe.
     */
    #[Test]
    public function subscription_endpoint_shows_each_demo_scenario(): void
    {
        $this->mock(StripeSubscriptionService::class);

        $this->runDemoSeeder();

        $member = $this->demoUser('demo.membro@aspec.test');
        $latestInvoice = $this->invoicesOf($member)->last();
        $this->assertNotNull($latestInvoice);

        Sanctum::actingAs($member);
        $response = $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.next_payment_at', $latestInvoice->period_end->toIso8601String())
            ->assertJsonPath('data.grace_ends_at', null)
            ->assertJsonPath('data.cancel_at_period_end', false)
            ->assertJsonPath('data.price.currency', config('subscription.currency'))
            ->assertJsonPath('data.price.interval', config('subscription.interval'))
            ->assertJsonPath('data.billing.billing_name', $member->memberProfile->business_name)
            ->assertJsonPath('data.billing.billing_address', $member->memberProfile->address)
            ->assertJsonPath('data.billing.billing_postal_code', $member->memberProfile->postal_code)
            ->assertJsonPath('data.billing.billing_city', $member->memberProfile->city);

        $this->assertEquals(config('subscription.price_amount'), $response->json('data.price.amount'));

        Sanctum::actingAs($this->demoUser('demo.membro02@aspec.test'));
        $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.cancel_at_period_end', true);

        Sanctum::actingAs($this->usersInState(SubscriptionState::Trial)->firstWhere('email', '!=', 'demo.membro02@aspec.test'));
        $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.trial_days', config('subscription.trial_days'))
            ->assertJsonPath('data.cancel_at_period_end', false);

        Sanctum::actingAs($this->demoUser('demo.membro07@aspec.test'));
        $graceEndsAt = $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('data.status', 'past_due')
            ->json('data.grace_ends_at');

        $this->assertNotNull($graceEndsAt);
        $this->assertTrue(Carbon::parse($graceEndsAt)->isFuture());
    }

    /**
     * Lista de faturas como o membro a vê: as duas pagas do demo.membro, com PDF; nenhuma em trial.
     */
    #[Test]
    public function invoices_endpoint_lists_the_demo_invoices_of_each_account(): void
    {
        $this->useRealInvoiceService();
        $this->runDemoSeeder();

        Sanctum::actingAs($this->demoUser('demo.membro@aspec.test'));
        $items = $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->json('data.items');

        $this->assertCount(2, $items);
        $this->assertGreaterThan($items[1]['period_start'], $items[0]['period_start']);

        foreach ($items as $item) {
            $this->assertSame('issued', $item['status']);
            $this->assertEquals(config('subscription.price_amount'), $item['amount']);
            $this->assertSame(config('subscription.currency'), $item['currency']);
            $this->assertTrue($item['pdf_available']);
            $this->assertMatchesRegularExpression('/^DEMO-\d{4}-[0-9A-F]{8}$/', $item['number']);
        }

        Sanctum::actingAs($this->usersInState(SubscriptionState::Trial)->first());

        $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);
    }

    /**
     * As faturas passam pelo mesmo job do webhook com o driver demo, com a data do pagamento (não a
     * do dia em que o seeder corre) e o PDF no disco privado.
     */
    #[Test]
    public function demo_invoices_are_issued_by_the_demo_driver_with_the_payment_date(): void
    {
        $this->useRealInvoiceService();
        $this->runDemoSeeder();

        $invoices = Invoice::where('stripe_invoice_id', 'like', 'in_demo_%')->get();
        $this->assertNotEmpty($invoices);

        foreach ($invoices as $invoice) {
            $label = $invoice->stripe_invoice_id;
            $paidAt = $invoice->period_start;

            $this->assertSame('demo', $invoice->provider, $label);
            $this->assertSame(InvoiceStatus::Issued, $invoice->status, $label);
            $this->assertSame((int) round(config('subscription.price_amount') * 100), $invoice->amount, $label);
            $this->assertSame(config('subscription.currency'), $invoice->currency, $label);
            $this->assertMatchesRegularExpression('/^DEMO-'.$paidAt->format('Y').'-[0-9A-F]{8}$/', $invoice->number, $label);
            $this->assertAbout($paidAt, $invoice->issued_at, $label, 5);
            $this->assertSame(config('services.invoicing.vat_rate'), $invoice->vat_rate, $label);
            $this->assertSame(config('services.invoicing.vat_exemption_reason'), $invoice->vat_exemption_reason, $label);
            $this->assertStringStartsWith("invoices/{$invoice->user_id}/", $invoice->provider_invoice_id, $label);
            Storage::disk('local')->assertExists($invoice->provider_invoice_id);
        }
    }

    #[Test]
    public function demo_member_downloads_a_demo_invoice_pdf(): void
    {
        $this->useRealInvoiceService();
        $this->runDemoSeeder();

        $invoice = $this->invoicesOf('demo.membro@aspec.test')->last();
        $this->assertNotNull($invoice);

        Sanctum::actingAs($this->demoUser('demo.membro@aspec.test'));

        $this->get("/api/invoices/{$invoice->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    /**
     * O seeder troca o driver de faturação, o mailer e o relógio só durante a emissão: depois volta
     * tudo ao que era e nenhum email sai pelo mailer configurado (NIF e morada no PDF).
     */
    #[Test]
    public function issuing_restores_the_driver_mailer_and_clock_and_sends_no_email(): void
    {
        $this->useRealInvoiceService();
        $this->useSpyMailerAsDefault();
        $this->assertSame('log', config('services.invoicing.driver'));

        $this->runDemoSeeder();

        $this->assertGreaterThan(0, Invoice::where('provider', 'demo')->where('stripe_invoice_id', 'like', 'in_demo_%')->count());
        $this->assertSame('log', config('services.invoicing.driver'));
        $this->assertSame('demo_spy', config('mail.default'));
        $this->assertFalse(Carbon::hasTestNow());
        $this->assertSame(0, $this->spyMailerMessageCount());
    }

    #[Test]
    public function issuing_restores_the_driver_mailer_and_clock_when_it_fails(): void
    {
        $this->useSpyMailerAsDefault();
        $failing = $this->failingInvoiceService();
        $this->app->instance(InvoiceService::class, $failing);

        try {
            $this->app->make(DemoSeeder::class)->setContainer($this->app)->__invoke();
        } catch (Throwable) {
            // A falha pode ou não chegar aqui; o que conta é o estado reposto depois.
        }

        $this->assertGreaterThan(0, $failing->calls);
        $this->assertSame('log', config('services.invoicing.driver'));
        $this->assertSame('demo_spy', config('mail.default'));
        $this->assertFalse(Carbon::hasTestNow());
    }

    /**
     * Seeder sem Stripe: os serviços do Stripe são mocks sem expectativas (qualquer chamada falha) e
     * nenhum pedido HTTP sai.
     */
    #[Test]
    public function seeding_payments_never_calls_stripe_nor_external_services(): void
    {
        Http::preventStrayRequests();
        $this->mock(StripeSubscriptionService::class);
        $this->mock(StripeCustomerService::class);

        $this->runDemoSeeder();

        $activated = $this->demoUsers()->reject(fn (User $user) => in_array($user->accountStatus->name, ['Pending', 'Approved'], true));

        $this->assertSame($activated->count(), Subscription::count());
        $this->assertGreaterThan(0, Invoice::count());
    }

    /**
     * Painel do backoffice coerente com a demonstração: 7 candidaturas, 2 em carência e 2 por
     * falta de pagamento, e números desta semana e deste mês diferentes de zero. Corre numa
     * quarta-feira a meio do mês, para a semana e o mês terem dias para trás.
     */
    #[Test]
    public function admin_dashboard_shows_the_demo_numbers(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 12:00', $this->eventTimezone()));

        $this->runDemoSeeder();

        Sanctum::actingAs($this->demoUser('demo.admin@aspec.test'));

        $stats = $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.stats.pending_applications.total', 7)
            ->assertJsonPath('data.stats.overdue_payments.grace', 2)
            ->assertJsonPath('data.stats.overdue_payments.unpaid', 2)
            ->assertJsonPath('data.stats.overdue_payments.total', 4)
            ->json('data.stats');

        $this->assertGreaterThan(0, $stats['event_registrations']['this_week']);
        $this->assertGreaterThan(0, $stats['events']['this_week']);
        $this->assertGreaterThan(0, $stats['reviews']['new_this_week']);
        $this->assertGreaterThan(0, $stats['reviews']['flagged']);
        $this->assertGreaterThan(0, $stats['active_members']['new_this_month']);
    }

    /**
     * A lista de utilizadores do backoffice tem exemplos de todos os estados derivados exceto o
     * isento (só o administrador técnico do aspec:create-admin o tem) e o inativo sem motivo de
     * pagamento (uma rejeição apaga a conta, por isso nenhuma conta demo fica nesse estado).
     */
    #[Test]
    public function admin_user_list_shows_every_subscription_state_except_exempt(): void
    {
        $this->runDemoSeeder();

        Sanctum::actingAs($this->demoUser('demo.admin@aspec.test'));

        $counts = $this->getJson('/api/admin/users?include_admins=1')
            ->assertOk()
            ->json('data.subscription_state_counts');

        foreach (SubscriptionState::cases() as $state) {
            if ($state === SubscriptionState::Exempt || $state === SubscriptionState::Inactive) {
                $this->assertSame(0, $counts[$state->value], $state->value);

                continue;
            }

            $this->assertGreaterThan(0, $counts[$state->value], $state->value);
        }
    }

    /**
     * Bloquear o membro em carência pede ao Stripe o cancelamento da subscrição demo; o bloqueio
     * nunca falha por isso.
     */
    #[Test]
    public function blocking_the_member_in_grace_cancels_the_demo_subscription_without_error(): void
    {
        Notification::fake();
        $member = $this->runAndGet('demo.membro07@aspec.test');
        $expectedStripeId = 'sub_demo_'.$this->demoIdFragment($member);

        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) use ($expectedStripeId) {
            $mock->shouldReceive('cancelNow')
                ->once()
                ->withArgs(fn (Subscription $subscription) => $subscription->stripe_id === $expectedStripeId);
            $mock->shouldNotReceive('expireCheckout');
        });

        Sanctum::actingAs($this->demoUser('demo.admin@aspec.test'));

        $this->patchJson("/api/admin/users/{$member->id}/block")->assertOk();

        $this->assertSame(InactiveReason::Blocked, $member->fresh()->inactive_reason);
    }

    /**
     * As contas demo não têm cliente Stripe: o portal responde 404 sem chamar o Stripe.
     */
    #[Test]
    public function billing_portal_for_a_demo_subscription_is_not_found_without_calling_stripe(): void
    {
        $member = $this->runAndGet('demo.membro@aspec.test');
        $this->assertNotNull($member->currentSubscription());

        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('billingPortalUrl');
        });

        Sanctum::actingAs($member);

        $this->postJson('/api/subscription/billing-portal')->assertNotFound();
    }

    /**
     * Cancelar uma subscrição demo: o Stripe não a conhece, por isso o pedido falha de forma
     * controlada (502) sem gravar nada nem enviar o email de confirmação.
     */
    #[Test]
    public function cancelling_a_demo_subscription_fails_with_502_and_changes_nothing(): void
    {
        Notification::fake();
        $member = $this->runAndGet('demo.membro@aspec.test');
        $subscription = $member->currentSubscription();
        $this->assertNotNull($subscription);

        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) use ($subscription) {
            $mock->shouldReceive('cancelAtPeriodEnd')
                ->once()
                ->andThrow(InvalidRequestException::factory("No such subscription: '{$subscription->stripe_id}'", 404, null, null, null, 'resource_missing'));
        });

        Sanctum::actingAs($member);

        $this->postJson('/api/subscription/cancel')
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Não foi possível contactar o serviço de pagamentos. Tente novamente.');

        $subscription->refresh();
        $this->assertSame('active', $subscription->stripe_status);
        $this->assertNull($subscription->ends_at);
        $this->assertSame('Active', $this->demoUser('demo.membro@aspec.test')->accountStatus->name);
        Notification::assertNothingSent();
    }

    /**
     * O end-grace não toca no demo.membro07 antes do fim da carência; depois, com o Stripe a dizer
     * que a subscrição terminou, a conta passa a Inactive por falta de pagamento.
     */
    #[Test]
    public function end_grace_leaves_the_demo_member_alone_until_the_grace_period_ends(): void
    {
        Notification::fake();
        $member = $this->runAndGet('demo.membro07@aspec.test');
        $this->assertNotNull($member->grace_ends_at);

        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('retrieveStatus');
        });

        $this->artisan('subscriptions:end-grace')->assertSuccessful();

        $this->assertSame('Active', $this->demoUser('demo.membro07@aspec.test')->accountStatus->name);

        $this->travelTo($this->usersInState(SubscriptionState::Grace)->max('grace_ends_at')->copy()->addMinute());

        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('retrieveStatus')->andReturn('canceled');
            $mock->shouldReceive('cancelNow')->byDefault();
        });

        $this->artisan('subscriptions:end-grace')->assertSuccessful();

        $member = $this->demoUser('demo.membro07@aspec.test');
        $this->assertSame('Inactive', $member->accountStatus->name);
        $this->assertSame(InactiveReason::Unpaid, $member->inactive_reason);
    }

    /**
     * Anonimizar o demo.membro não chama o Stripe (sem cliente) e guarda as faturas (retenção
     * fiscal); a execução seguinte cria a conta de novo, com ids demo diferentes.
     */
    #[Test]
    public function anonymizing_a_demo_member_keeps_its_invoices_and_the_seeder_recreates_it(): void
    {
        Notification::fake();
        $old = $this->runAndGet('demo.membro@aspec.test');
        $oldSubscriptionId = $old->currentSubscription()?->stripe_id;
        $this->assertNotNull($oldSubscriptionId);

        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('delete');
        });
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelNow')->byDefault();
            $mock->shouldNotReceive('expireCheckout');
        });

        Sanctum::actingAs($this->demoUser('demo.admin@aspec.test'));

        $this->postJson("/api/admin/users/{$old->id}/anonymize")->assertOk();

        $this->assertSame(2, Invoice::where('user_id', $old->id)->count());

        $this->runDemoSeeder();

        $new = $this->demoUser('demo.membro@aspec.test');
        $newSubscription = $new->currentSubscription();

        $this->assertNotSame($old->id, $new->id);
        $this->assertNotNull($newSubscription);
        $this->assertNotSame($oldSubscriptionId, $newSubscription->stripe_id);
        $this->assertTrue(Subscription::where('stripe_id', $oldSubscriptionId)->exists());
        $this->assertSame(2, Invoice::where('user_id', $old->id)->count());
        $this->assertSame(2, Invoice::where('user_id', $new->id)->count());
    }

    /**
     * O reset só apaga linhas e PDFs com o prefixo "_demo_" das contas demo: as linhas reais de uma
     * conta demo, os dados de outras contas e ficheiros que não são da demo ficam intactos.
     */
    #[Test]
    public function running_again_never_touches_payments_that_are_not_demo(): void
    {
        $this->runDemoSeeder();

        $other = User::factory()->withBilling()->create();
        $otherSubscription = $other->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_other',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);
        $otherPdf = "invoices/{$other->id}/DEMO-2026-ABCDEF12.pdf";
        Storage::disk('local')->put($otherPdf, 'pdf');
        $otherInvoice = Invoice::factory()->issued()->create([
            'user_id' => $other->id,
            'stripe_invoice_id' => 'in_test_other',
            'provider' => 'demo',
            'provider_invoice_id' => $otherPdf,
        ]);
        Storage::disk('local')->put('invoices/manual/nota.pdf', 'pdf');

        $demoMember = $this->demoUser('demo.membro@aspec.test');
        $endedRealSubscription = $demoMember->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_old_real',
            'stripe_status' => 'canceled',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'ends_at' => now()->subYears(2),
        ]);
        $realInvoice = Invoice::factory()->issued()->create([
            'user_id' => $demoMember->id,
            'stripe_invoice_id' => 'in_test_real_demo_member',
            'created_at' => now()->subYears(2),
        ]);

        $this->runDemoSeeder();

        $this->assertModelExists($otherSubscription);
        $this->assertModelExists($otherInvoice);
        $this->assertModelExists($endedRealSubscription);
        $this->assertModelExists($realInvoice);
        Storage::disk('local')->assertExists($otherPdf);
        Storage::disk('local')->assertExists('invoices/manual/nota.pdf');
        $this->assertSame(2, Invoice::where('user_id', $demoMember->id)->where('stripe_invoice_id', 'like', 'in_demo_%')->count());
    }

    /**
     * Antes de recriar as faturas demo, o seeder apaga o PDF de cada uma (o caminho guardado na
     * linha), para não ficarem ficheiros órfãos no disco privado.
     */
    #[Test]
    public function running_again_deletes_the_pdf_of_each_previous_demo_invoice(): void
    {
        $this->runDemoSeeder();

        $invoice = $this->invoicesOf('demo.membro@aspec.test')->first();
        $this->assertNotNull($invoice);
        $stalePdf = "invoices/{$invoice->user_id}/DEMO-1999-0000000A.pdf";
        Storage::disk('local')->put($stalePdf, 'pdf');
        $invoice->forceFill(['provider_invoice_id' => $stalePdf])->save();

        $this->runDemoSeeder();

        Storage::disk('local')->assertMissing($stalePdf);
        $this->assertModelMissing($invoice);
    }

    /**
     * Uma conta demo reativada a sério num ensaio (subscrição real em curso) fica sem dados de
     * pagamento demo: duas subscrições em curso fariam o webhook tratar a real como duplicado.
     */
    #[Test]
    public function demo_account_with_a_real_running_subscription_gets_no_demo_payments(): void
    {
        $member = $this->runAndGet('demo.inativo.pagamento@aspec.test');
        $demoSubscriptions = Subscription::where('stripe_id', 'like', 'sub_demo_%')->count();

        $real = $member->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_real_123',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);
        $member->forceFill([
            'account_status_id' => AccountStatus::where('name', 'Active')->value('id'),
            'inactive_reason' => null,
            'grace_ends_at' => now()->addDays(3),
            'stripe_checkout_session_id' => 'cs_test_real',
        ])->save();

        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])
            ->expectsOutputToContain('A conta demo.inativo.pagamento@aspec.test tem uma subscrição real do Stripe em curso')
            ->expectsOutputToContain('cancele-a no dashboard de teste do Stripe e volte a correr o seeder.')
            ->assertSuccessful();

        $real->refresh();
        $this->assertSame('active', $real->stripe_status);
        $this->assertNull($real->ends_at);
        $this->assertSame(0, Subscription::where('user_id', $member->id)->where('stripe_id', 'like', 'sub_demo_%')->count());
        $this->assertSame(1, Subscription::where('user_id', $member->id)->count());
        $this->assertSame($demoSubscriptions - 1, Subscription::where('stripe_id', 'like', 'sub_demo_%')->count());

        $member->refresh();
        $this->assertSame('Active', $member->accountStatus->name);
        $this->assertNull($member->inactive_reason);
        $this->assertNotNull($member->grace_ends_at);
        $this->assertSame('cs_test_real', $member->stripe_checkout_session_id);
    }
}
