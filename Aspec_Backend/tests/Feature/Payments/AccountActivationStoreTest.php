<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Http\Requests\StartActivationRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Services\Payments\Data\CheckoutSessionData;
use App\Services\Payments\StripeSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Stripe\Exception\ApiConnectionException;
use Tests\Concerns\BuildsActivationLinks;
use Tests\TestCase;

/**
 * POST /api/account-activations/{user}: grava a faturação (só na ativação) e devolve o URL do
 * Stripe Checkout. Nunca muda o estado da conta (só o webhook ativa) e nunca abre uma segunda
 * subscrição. O Stripe está sempre mockado.
 */
class AccountActivationStoreTest extends TestCase
{
    use BuildsActivationLinks, RefreshDatabase;

    private const CHECKOUT_URL = 'https://checkout.stripe.com/c/pay/cs_test_1';

    private const REDIRECTING = 'A redirecionar para o pagamento.';

    private const IN_PROGRESS = 'Já existe uma subscrição em curso para esta conta.';

    private const PAYMENTS_DOWN = 'Não foi possível contactar o serviço de pagamentos. Tente novamente.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTestFrontendUrl();
    }

    private function billing(array $overrides = []): array
    {
        return array_merge([
            'billing_name' => 'Exemplo Soluções, Lda.',
            'nif' => '509123457',
            'billing_address' => 'Rua das Flores, 10',
            'billing_postal_code' => '4050-262',
            'billing_city' => 'Porto',
        ], $overrides);
    }

    private function openSession(string $id = 'cs_test_1'): CheckoutSessionData
    {
        return new CheckoutSessionData($id, "https://checkout.stripe.com/c/pay/{$id}", 'open');
    }

    private function stripe(): MockInterface
    {
        return $this->mock(StripeSubscriptionService::class);
    }

    private function expectCheckoutCreated(?callable $argsCheck = null): MockInterface
    {
        $mock = $this->stripe();
        $expectation = $mock->shouldReceive('createCheckout')->once();
        if ($argsCheck) {
            $expectation->withArgs($argsCheck);
        }
        $expectation->andReturn($this->openSession());

        return $mock;
    }

    private function expectNoStripeCalls(): void
    {
        $mock = $this->stripe();
        $mock->shouldNotReceive('createCheckout');
        $mock->shouldNotReceive('retrieveCheckout');
    }

    private function subscribe(User $user, string $status, string $stripeId = 'sub_test_1'): void
    {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => $stripeId,
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'ends_at' => $status === 'canceled' ? now()->subMonth() : null,
        ]);
    }

    #[Test]
    public function approved_account_saves_billing_and_gets_the_checkout_url(): void
    {
        $user = User::factory()->approved()->create();
        $path = $this->activationPath($user);
        $query = $this->signatureQuery($path);
        $this->expectCheckoutCreated(function (User $customer, bool $withTrial, string $cancelUrl) use ($user, $query) {
            return $customer->is($user)
                && $withTrial === true
                && str_starts_with($cancelUrl, self::FRONTEND_URL."/ativacao/{$user->id}?")
                && str_contains($cancelUrl, 'expires='.$query['expires'])
                && str_contains($cancelUrl, 'signature='.$query['signature']);
        });

        $this->postJson($path, $this->billing())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', self::REDIRECTING)
            ->assertJsonPath('data.checkout_url', self::CHECKOUT_URL);

        $this->assertDatabaseHas('users', array_merge(['id' => $user->id, 'stripe_checkout_session_id' => 'cs_test_1'], $this->billing()));
    }

    #[Test]
    public function starting_the_checkout_does_not_activate_the_account(): void
    {
        $user = User::factory()->approved()->create();
        $this->expectCheckoutCreated();

        $this->postJson($this->activationPath($user), $this->billing())->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Approved', $fresh->accountStatus->name);
        $this->assertNull($fresh->trial_ends_at);
    }

    #[Test]
    public function nif_with_spaces_is_stored_without_them(): void
    {
        $user = User::factory()->approved()->create();
        $this->expectCheckoutCreated();

        $this->postJson($this->activationPath($user), $this->billing(['nif' => '509 123 457']))->assertOk();

        $this->assertSame('509123457', $user->fresh()->nif);
    }

    #[Test]
    public function invalid_nif_is_rejected(): void
    {
        $user = User::factory()->approved()->create();
        $this->expectNoStripeCalls();

        $this->postJson($this->activationPath($user), $this->billing(['nif' => '509123450']))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['nif'])
            ->assertJsonPath('errors.nif.0', 'O campo NIF tem de ser um NIF português válido.');

        $this->assertNull($user->fresh()->nif);
    }

    #[Test]
    public function postal_code_without_hyphen_is_rejected(): void
    {
        $user = User::factory()->approved()->create();
        $this->expectNoStripeCalls();

        $this->postJson($this->activationPath($user), $this->billing(['billing_postal_code' => '1000001']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['billing_postal_code']);
    }

    public static function requiredBillingFields(): array
    {
        return [
            'nome de faturação' => ['billing_name'],
            'número de contribuinte' => ['nif'],
            'morada' => ['billing_address'],
            'código postal' => ['billing_postal_code'],
            'localidade' => ['billing_city'],
        ];
    }

    #[Test]
    #[DataProvider('requiredBillingFields')]
    public function each_billing_field_is_required_on_activation(string $field): void
    {
        $user = User::factory()->approved()->create();
        $this->expectNoStripeCalls();
        $body = $this->billing();
        unset($body[$field]);

        $this->postJson($this->activationPath($user), $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
    }

    public static function tooLongBillingFields(): array
    {
        return [
            'nome de faturação' => ['billing_name', 256],
            'morada' => ['billing_address', 256],
            'localidade' => ['billing_city', 101],
        ];
    }

    #[Test]
    #[DataProvider('tooLongBillingFields')]
    public function billing_fields_over_the_maximum_length_are_rejected(string $field, int $length): void
    {
        $user = User::factory()->approved()->create();
        $this->expectNoStripeCalls();

        $this->postJson($this->activationPath($user), $this->billing([$field => str_repeat('a', $length)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
    }

    #[Test]
    public function reactivation_ignores_the_body_and_keeps_the_stored_billing(): void
    {
        // Um link de reativação que escape não pode trocar os dados em que as faturas são emitidas.
        $user = User::factory()->inactive(InactiveReason::Unpaid)->withBilling()->create();
        $this->subscribe($user, 'canceled');
        $stored = $user->only(['billing_name', 'nif', 'billing_address', 'billing_postal_code', 'billing_city']);
        $this->expectCheckoutCreated(fn (User $customer, bool $withTrial) => $withTrial === false);

        $this->postJson($this->activationPath($user), $this->billing(['nif' => '123456780', 'billing_postal_code' => 'x']))
            ->assertOk()
            ->assertJsonPath('data.checkout_url', self::CHECKOUT_URL);

        $this->assertDatabaseHas('users', array_merge(['id' => $user->id], $stored));
    }

    #[Test]
    public function reactivation_with_an_empty_body_is_accepted(): void
    {
        $user = User::factory()->inactive(InactiveReason::Unpaid)->withBilling()->create();
        $this->subscribe($user, 'canceled');
        $this->expectCheckoutCreated();

        $this->postJson($this->activationPath($user))->assertOk();
    }

    #[Test]
    public function account_that_already_had_a_subscription_gets_no_new_trial(): void
    {
        $user = User::factory()->approved()->create();
        $this->subscribe($user, 'canceled');
        $this->expectCheckoutCreated(fn (User $customer, bool $withTrial) => $withTrial === false);

        $this->postJson($this->activationPath($user), $this->billing())->assertOk();
    }

    #[Test]
    public function second_post_reuses_the_open_checkout_session(): void
    {
        $user = User::factory()->approved()->create();
        $path = $this->activationPath($user);
        $mock = $this->stripe();
        $mock->shouldReceive('createCheckout')->once()->andReturn($this->openSession());
        $mock->shouldReceive('retrieveCheckout')->once()->with('cs_test_1')->andReturn($this->openSession());

        $first = $this->postJson($path, $this->billing())->assertOk();
        $second = $this->postJson($path, $this->billing())->assertOk();

        $this->assertSame(self::CHECKOUT_URL, $first->json('data.checkout_url'));
        $this->assertSame(self::CHECKOUT_URL, $second->json('data.checkout_url'));
        $this->assertSame('cs_test_1', $user->fresh()->stripe_checkout_session_id);
    }

    #[Test]
    public function corrected_billing_on_an_open_session_is_synced_to_the_stripe_customer(): void
    {
        $user = User::factory()->approved()->create();
        $path = $this->activationPath($user);
        $mock = $this->stripe();
        $mock->shouldReceive('createCheckout')->once()->andReturn($this->openSession());
        $mock->shouldReceive('retrieveCheckout')->once()->with('cs_test_1')->andReturn($this->openSession());
        $mock->shouldReceive('syncCustomer')->once()
            ->withArgs(fn (User $customer) => $customer->billing_name === 'Outra Empresa, Lda.');

        $this->postJson($path, $this->billing())->assertOk();
        $this->postJson($path, $this->billing(['billing_name' => 'Outra Empresa, Lda.']))
            ->assertOk()
            ->assertJsonPath('data.checkout_url', self::CHECKOUT_URL);

        $this->assertSame('Outra Empresa, Lda.', $user->fresh()->billing_name);
    }

    #[Test]
    public function same_billing_on_an_open_session_is_not_synced_again(): void
    {
        $user = User::factory()->approved()->create();
        $path = $this->activationPath($user);
        $mock = $this->stripe();
        $mock->shouldReceive('createCheckout')->once()->andReturn($this->openSession());
        $mock->shouldReceive('retrieveCheckout')->once()->andReturn($this->openSession());
        $mock->shouldNotReceive('syncCustomer');

        $this->postJson($path, $this->billing())->assertOk();
        $this->postJson($path, $this->billing())->assertOk();
    }

    #[Test]
    public function postal_code_with_a_trailing_newline_is_rejected(): void
    {
        // Por HTTP o TrimStrings já tira o "\n"; aqui prova-se a própria regra (o $ do PHP aceitava-o).
        $user = User::factory()->approved()->create();
        $request = StartActivationRequest::create("/api/account-activations/{$user->id}", 'POST');
        $request->setRouteResolver(fn () => (new Route('POST', '/api/account-activations/{user}', []))->bind($request));

        $validator = Validator::make($this->billing(['billing_postal_code' => "1000-001\n"]), $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('billing_postal_code', $validator->errors()->toArray());
        $this->assertFalse(Validator::make($this->billing(), $request->rules())->fails());
    }

    #[Test]
    public function expired_stored_session_is_replaced_by_a_new_one(): void
    {
        $user = User::factory()->approved()->create();
        $user->forceFill(['stripe_checkout_session_id' => 'cs_test_old'])->save();
        $mock = $this->stripe();
        $mock->shouldReceive('retrieveCheckout')->once()->with('cs_test_old')
            ->andReturn(new CheckoutSessionData('cs_test_old', null, 'expired'));
        $mock->shouldReceive('createCheckout')->once()->andReturn($this->openSession());

        $this->postJson($this->activationPath($user), $this->billing())
            ->assertOk()
            ->assertJsonPath('data.checkout_url', self::CHECKOUT_URL);

        $this->assertSame('cs_test_1', $user->fresh()->stripe_checkout_session_id);
    }

    #[Test]
    public function completed_stored_session_is_a_subscription_in_progress(): void
    {
        // Pagamento feito e webhook a caminho: outra sessão abriria a porta a uma segunda subscrição.
        $user = User::factory()->approved()->create();
        $user->forceFill(['stripe_checkout_session_id' => 'cs_test_paid'])->save();
        $mock = $this->stripe();
        $mock->shouldReceive('retrieveCheckout')->once()->with('cs_test_paid')
            ->andReturn(new CheckoutSessionData('cs_test_paid', null, 'complete'));
        $mock->shouldNotReceive('createCheckout');

        $this->postJson($this->activationPath($user), $this->billing())
            ->assertStatus(409)
            ->assertExactJson(['success' => false, 'message' => self::IN_PROGRESS]);

        $this->assertSame('cs_test_paid', $user->fresh()->stripe_checkout_session_id);
    }

    #[Test]
    public function ongoing_local_subscription_is_a_conflict_without_calling_stripe(): void
    {
        $user = User::factory()->approved()->create();
        $this->subscribe($user, 'trialing');
        $this->expectNoStripeCalls();

        $this->postJson($this->activationPath($user), $this->billing())
            ->assertStatus(409)
            ->assertExactJson(['success' => false, 'message' => self::IN_PROGRESS]);
    }

    #[Test]
    public function active_account_is_a_conflict(): void
    {
        $user = User::factory()->create();
        $this->expectNoStripeCalls();

        $this->postJson($this->activationPath($user), $this->billing())
            ->assertStatus(409)
            ->assertExactJson(['success' => false, 'message' => 'A conta já está ativa.']);
    }

    #[Test]
    public function blocked_account_is_a_conflict_and_billing_is_not_saved(): void
    {
        $user = User::factory()->inactive(InactiveReason::Blocked)->create();
        $this->expectNoStripeCalls();

        $this->postJson($this->activationPath($user), $this->billing())
            ->assertStatus(409)
            ->assertExactJson(['success' => false, 'message' => 'Este link já não é válido para esta conta.']);

        $this->assertNull($user->fresh()->nif);
    }

    #[Test]
    public function invalid_signature_is_checked_before_the_body(): void
    {
        $user = User::factory()->approved()->create();
        $this->expectNoStripeCalls();

        $this->postJson("/api/account-activations/{$user->id}?expires=".(time() + 3600).'&signature=forjada', [])
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'O link é inválido ou expirou.']);
    }

    #[Test]
    public function unknown_account_with_a_valid_signature_is_not_found_before_validation(): void
    {
        $this->expectNoStripeCalls();

        $this->postJson($this->signedPathFor((string) Str::uuid()), [])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Recurso não encontrado.']);
    }

    #[Test]
    public function stripe_failure_returns_502_logs_no_personal_data_and_saves_nothing(): void
    {
        Log::spy();
        $user = User::factory()->approved()->create();
        $this->stripe()->shouldReceive('createCheckout')->andThrow(ApiConnectionException::factory('falha de rede interna'));

        $response = $this->postJson($this->activationPath($user), $this->billing());

        $response->assertStatus(502)
            ->assertExactJson(['success' => false, 'message' => self::PAYMENTS_DOWN]);
        $this->assertStringNotContainsString('falha de rede interna', $response->getContent());

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context = []) use ($user) {
                $logged = json_encode($context);

                return array_key_exists('exception', $context)
                    && ! str_contains($logged, $user->email)
                    && ! str_contains($logged, '509123457');
            })
            ->once();

        $fresh = $user->fresh();
        $this->assertNull($fresh->nif);
        $this->assertNull($fresh->billing_name);
        $this->assertNull($fresh->stripe_checkout_session_id);
        $this->assertSame('Approved', $fresh->accountStatus->name);
    }
}
