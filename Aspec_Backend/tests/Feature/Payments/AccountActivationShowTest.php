<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsActivationLinks;
use Tests\TestCase;

/**
 * GET /api/account-activations/{user}: o frontend valida o link do email antes de mostrar
 * o formulário. Sem login; a autorização é a assinatura do link.
 */
class AccountActivationShowTest extends TestCase
{
    use BuildsActivationLinks, RefreshDatabase;

    private const INVALID_LINK = 'O link é inválido ou expirou.';

    private const NOT_FOUND = 'Recurso não encontrado.';

    private const ALREADY_ACTIVE = 'A conta já está ativa.';

    private const NOT_VALID_FOR_ACCOUNT = 'Este link já não é válido para esta conta.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTestFrontendUrl();
        config([
            'subscription.trial_days' => 30,
            'subscription.price_amount' => 60.00,
            'subscription.currency' => 'eur',
            'subscription.interval' => 'month',
        ]);
    }

    #[Test]
    public function approved_account_gets_the_activation_details(): void
    {
        $user = User::factory()->approved()->create();
        MemberProfile::factory()->create(['user_id' => $user->id, 'name' => 'Ana Ferreira']);

        $response = $this->getJson($this->activationPath($user));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Link válido.')
            ->assertJsonPath('data.type', 'activation')
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.name', 'Ana Ferreira')
            ->assertJsonPath('data.trial_days', 30)
            ->assertJsonPath('data.price.currency', 'eur')
            ->assertJsonPath('data.price.interval', 'month');
        $this->assertEquals(60, $response->json('data.price.amount'));
    }

    #[Test]
    public function activation_details_do_not_expose_billing_or_stripe_data(): void
    {
        $user = User::factory()->approved()->withBilling()->create();
        $user->forceFill(['stripe_id' => 'cus_test_privado'])->save();

        $response = $this->getJson($this->activationPath($user))->assertOk();

        $response->assertJsonMissingPath('data.nif')
            ->assertJsonMissingPath('data.stripe_id')
            ->assertJsonMissingPath('data.billing_name')
            ->assertJsonMissingPath('data.billing_address');
        $this->assertStringNotContainsString('cus_test_privado', $response->getContent());
        $this->assertStringNotContainsString($user->nif, $response->getContent());
    }

    #[Test]
    public function unpaid_account_gets_reactivation_details_without_trial(): void
    {
        $user = User::factory()->inactive(InactiveReason::Unpaid)->withBilling()->create();

        $this->getJson($this->activationPath($user))
            ->assertOk()
            ->assertJsonPath('data.type', 'reactivation')
            ->assertJsonPath('data.trial_days', 0);
    }

    #[Test]
    public function tampered_signature_is_forbidden(): void
    {
        $user = User::factory()->approved()->create();
        $path = $this->activationPath($user);
        $query = $this->signatureQuery($path);
        $tampered = str_replace($query['signature'], strrev($query['signature']), $path);

        $this->getJson($tampered)
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => self::INVALID_LINK]);
    }

    #[Test]
    public function changed_expiry_is_forbidden(): void
    {
        $user = User::factory()->approved()->create();
        $path = $this->activationPath($user);
        $query = $this->signatureQuery($path);
        $extended = str_replace('expires='.$query['expires'], 'expires='.($query['expires'] + 86400 * 30), $path);

        $this->getJson($extended)
            ->assertForbidden()
            ->assertJsonPath('message', self::INVALID_LINK);
    }

    #[Test]
    public function link_without_signature_is_forbidden(): void
    {
        $user = User::factory()->approved()->create();

        $this->getJson("/api/account-activations/{$user->id}")
            ->assertForbidden()
            ->assertJsonPath('message', self::INVALID_LINK);
    }

    #[Test]
    public function link_signed_for_another_user_is_forbidden(): void
    {
        $user = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $path = str_replace($user->id, $other->id, $this->activationPath($user));

        $this->getJson($path)
            ->assertForbidden()
            ->assertJsonPath('message', self::INVALID_LINK);
    }

    #[Test]
    public function expired_link_is_forbidden(): void
    {
        $user = User::factory()->approved()->create();
        $path = $this->activationPath($user);

        $this->travel(8)->days();

        $this->getJson($path)
            ->assertForbidden()
            ->assertJsonPath('message', self::INVALID_LINK);
    }

    #[Test]
    public function forged_link_for_an_unknown_uuid_is_forbidden_not_not_found(): void
    {
        // O 403 vem antes do 404 para um link forjado não revelar que contas existem.
        $id = (string) Str::uuid();

        $this->getJson("/api/account-activations/{$id}?expires=".(time() + 3600).'&signature='.str_repeat('a', 64))
            ->assertForbidden()
            ->assertJsonPath('message', self::INVALID_LINK);
    }

    #[Test]
    public function valid_link_of_a_deleted_account_is_not_found(): void
    {
        $user = User::factory()->approved()->create();
        $path = $this->activationPath($user);
        $user->delete();

        $this->getJson($path)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => self::NOT_FOUND]);
    }

    #[Test]
    public function valid_link_of_an_unknown_uuid_is_not_found(): void
    {
        $this->getJson($this->signedPathFor((string) Str::uuid()))
            ->assertNotFound()
            ->assertJsonPath('message', self::NOT_FOUND);
    }

    #[Test]
    public function active_account_gets_already_active_conflict(): void
    {
        $user = User::factory()->create();

        $this->getJson($this->activationPath($user))
            ->assertStatus(409)
            ->assertExactJson(['success' => false, 'message' => self::ALREADY_ACTIVE]);
    }

    public static function notEligibleAccounts(): array
    {
        return [
            'pendente' => ['pending', null],
            'bloqueada' => ['inactive', InactiveReason::Blocked],
            'recusada' => ['inactive', InactiveReason::Rejected],
            'anonimizada' => ['inactive', InactiveReason::Deleted],
            'inativa sem motivo' => ['inactive', null],
        ];
    }

    #[Test]
    #[DataProvider('notEligibleAccounts')]
    public function account_that_cannot_pay_gets_link_no_longer_valid_conflict(string $state, ?InactiveReason $reason): void
    {
        $factory = User::factory();
        $user = ($state === 'pending' ? $factory->pending() : $factory->inactive($reason))->create();

        $this->getJson($this->activationPath($user))
            ->assertStatus(409)
            ->assertExactJson(['success' => false, 'message' => self::NOT_VALID_FOR_ACCOUNT]);
    }

    #[Test]
    public function signature_does_not_depend_on_the_api_host(): void
    {
        $user = User::factory()->approved()->create();

        $this->getJson('http://api.outro.test'.$this->activationPath($user))
            ->assertOk()
            ->assertJsonPath('data.type', 'activation');
    }

    #[Test]
    public function show_does_not_change_the_account(): void
    {
        $user = User::factory()->approved()->create();

        $this->getJson($this->activationPath($user))->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Approved', $fresh->accountStatus->name);
        $this->assertNull($fresh->stripe_checkout_session_id);
    }

    #[Test]
    public function twenty_first_request_in_a_minute_is_throttled(): void
    {
        $user = User::factory()->approved()->create();
        $path = $this->activationPath($user);

        for ($i = 0; $i < 20; $i++) {
            $this->getJson($path)->assertOk();
        }

        $this->getJson($path)
            ->assertStatus(429)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function forged_links_also_count_towards_the_limit(): void
    {
        $user = User::factory()->approved()->create();

        for ($i = 0; $i < 20; $i++) {
            $this->getJson("/api/account-activations/{$user->id}?expires=1&signature=x")->assertForbidden();
        }

        $this->getJson($this->activationPath($user))->assertStatus(429);
    }
}
