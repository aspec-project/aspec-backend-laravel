<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Models\User;
use App\Services\Payments\StripeSubscriptionService;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stripe\Exception\ApiConnectionException;
use Tests\TestCase;

/**
 * POST /api/subscription/billing-portal: abre uma sessão do portal de faturação do Stripe para
 * o membro atualizar o cartão. O cartão nunca passa pelo nosso servidor; o Stripe é mockado.
 */
class BillingPortalTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/subscription/billing-portal';

    private const PORTAL_URL = 'https://billing.stripe.com/p/session/test_1';

    private const FRONTEND_URL = 'http://localhost:5173';

    private const NOT_FOUND = 'Não existe subscrição.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => self::FRONTEND_URL]);
    }

    private function stripe(): MockInterface
    {
        return $this->mock(StripeSubscriptionService::class);
    }

    private function memberWithSubscription(string $status = 'active', ?UserFactory $factory = null): User
    {
        $user = ($factory ?? User::factory())->withBilling()->create(['stripe_id' => 'cus_'.fake()->unique()->lexify('??????????')]);
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.fake()->unique()->lexify('??????????'),
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        return $user;
    }

    #[Test]
    public function active_member_gets_the_portal_url_with_return_to_the_subscription_page(): void
    {
        config(['app.frontend_url' => self::FRONTEND_URL.'/']);
        $member = $this->memberWithSubscription();
        $this->stripe()->shouldReceive('billingPortalUrl')
            ->once()
            ->withArgs(fn (User $user, string $returnUrl) => $user->is($member)
                && $returnUrl === self::FRONTEND_URL.'/conta/subscricao')
            ->andReturn(self::PORTAL_URL);
        Sanctum::actingAs($member);

        $this->postJson(self::URL)
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'A redirecionar para o portal de pagamentos.',
                'data' => ['url' => self::PORTAL_URL],
            ]);
    }

    #[Test]
    public function member_in_grace_period_can_open_the_portal_to_update_the_card(): void
    {
        $member = $this->memberWithSubscription('past_due');
        $this->stripe()->shouldReceive('billingPortalUrl')->once()->andReturn(self::PORTAL_URL);
        Sanctum::actingAs($member);

        $this->postJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.url', self::PORTAL_URL);
    }

    #[Test]
    public function admin_without_subscription_gets_not_found_and_stripe_is_not_called(): void
    {
        $this->stripe()->shouldNotReceive('billingPortalUrl');
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson(self::URL)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => self::NOT_FOUND]);
    }

    /**
     * Sem cliente Stripe o Cashier lançaria InvalidCustomer (500): a pré-condição dá 404 antes.
     */
    #[Test]
    public function member_without_stripe_customer_gets_not_found_and_stripe_is_not_called(): void
    {
        $member = $this->memberWithSubscription();
        $member->forceFill(['stripe_id' => null])->save();
        $this->stripe()->shouldNotReceive('billingPortalUrl');
        Sanctum::actingAs($member);

        $this->postJson(self::URL)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => self::NOT_FOUND]);
    }

    #[Test]
    public function member_with_stripe_customer_but_no_subscription_gets_not_found(): void
    {
        $member = User::factory()->withBilling()->create(['stripe_id' => 'cus_sem_subscricao']);
        $this->stripe()->shouldNotReceive('billingPortalUrl');
        Sanctum::actingAs($member);

        $this->postJson(self::URL)
            ->assertNotFound()
            ->assertJsonPath('message', self::NOT_FOUND);
    }

    #[Test]
    public function guest_is_unauthenticated_and_stripe_is_not_called(): void
    {
        $this->stripe()->shouldNotReceive('billingPortalUrl');

        $this->postJson(self::URL)
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Não autenticado.']);
    }

    public static function accountsWithoutAccess(): array
    {
        return [
            'approved' => ['approved'],
            'inactive unpaid' => ['unpaid'],
        ];
    }

    #[Test]
    #[DataProvider('accountsWithoutAccess')]
    public function accounts_that_are_not_active_are_forbidden_and_stripe_is_not_called(string $state): void
    {
        $user = $this->memberWithSubscription('canceled', match ($state) {
            'approved' => User::factory()->approved(),
            'unpaid' => User::factory()->inactive(InactiveReason::Unpaid),
        });
        $this->stripe()->shouldNotReceive('billingPortalUrl');
        Sanctum::actingAs($user);

        $this->postJson(self::URL)
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'A conta não está ativa e não pode editar dados.']);
    }

    #[Test]
    public function stripe_failure_returns_502_and_logs_neither_the_email_nor_the_portal_url(): void
    {
        Log::spy();
        $member = $this->memberWithSubscription();
        $this->stripe()->shouldReceive('billingPortalUrl')->andThrow(ApiConnectionException::factory('falha'));
        Sanctum::actingAs($member);

        $response = $this->postJson(self::URL);

        $response->assertStatus(502)
            ->assertExactJson([
                'success' => false,
                'message' => 'Não foi possível contactar o serviço de pagamentos. Tente novamente.',
            ]);

        $leaks = function (...$args) use ($member) {
            $logged = json_encode($args);

            return str_contains($logged, $member->email) || str_contains($logged, 'billing.stripe.com');
        };
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level, $leaks);
        }
        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function eleventh_request_in_a_minute_is_throttled_per_member(): void
    {
        $member = $this->memberWithSubscription();
        $other = $this->memberWithSubscription();
        $this->stripe()->shouldReceive('billingPortalUrl')->andReturn(self::PORTAL_URL);

        Sanctum::actingAs($member);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(self::URL)->assertOk();
        }

        $this->postJson(self::URL)
            ->assertTooManyRequests()
            ->assertExactJson(['success' => false, 'message' => 'Demasiados pedidos. Tente novamente mais tarde.']);

        Sanctum::actingAs($other);
        $this->postJson(self::URL)->assertOk();
    }

    /**
     * Fora de desenvolvimento, sem configuração própria do portal, o pedido é recusado antes de
     * chamar o Stripe: o erro é da nossa configuração (500, não 502) e fica no log para o deploy.
     * Usa o service real; o Cashier aponta para uma porta local fechada caso algo chegue à rede.
     */
    #[Test]
    public function portal_without_configuration_outside_development_fails_closed_with_500(): void
    {
        Exceptions::fake();
        $this->app->detectEnvironment(fn () => 'production');
        config(['subscription.billing_portal_configuration' => null, 'app.debug' => false]);
        Sanctum::actingAs($this->memberWithSubscription());

        $this->postJson(self::URL)
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Ocorreu um erro interno. Tente novamente mais tarde.']);

        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'STRIPE_BILLING_PORTAL_CONFIGURATION'));
    }

    /**
     * POST e não GET: abrir o portal cria uma sessão no Stripe (efeito lateral) e o POST
     * exige o token CSRF em modo SPA.
     */
    #[Test]
    public function get_is_not_allowed(): void
    {
        $this->stripe()->shouldNotReceive('billingPortalUrl');
        Sanctum::actingAs($this->memberWithSubscription());

        $this->getJson(self::URL)->assertMethodNotAllowed();
    }
}
