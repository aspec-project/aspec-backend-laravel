<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsActivationLinks;
use Tests\TestCase;

/**
 * Os endpoints públicos de ativação não usam sessão: o frontend chama-os sem cookies e a autorização
 * é só a assinatura do link (ou os limites de pedidos, no reenvio). Se ficassem no modo SPA do Sanctum,
 * qualquer pedido vindo do domínio do frontend exigiria CSRF e o browser recebia 419 (ASPEC-372).
 *
 * Os testes não validam CSRF, por isso verifica-se o sintoma do modo SPA: a resposta trazer o cookie
 * XSRF-TOKEN ou o cookie de sessão.
 */
class AccountActivationStatelessTest extends TestCase
{
    use BuildsActivationLinks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTestFrontendUrl();
    }

    private function fromFrontend(): static
    {
        return $this->withHeaders(['Origin' => self::FRONTEND_URL, 'Referer' => self::FRONTEND_URL.'/']);
    }

    private function assertNoSessionCookies(TestResponse $response): void
    {
        $response->assertCookieMissing('XSRF-TOKEN');
        $response->assertCookieMissing(config('session.cookie'));
    }

    #[Test]
    public function the_frontend_domain_still_gets_a_session_on_spa_routes(): void
    {
        $response = $this->fromFrontend()->getJson('/api/sectors')->assertOk();

        $response->assertCookie('XSRF-TOKEN');
    }

    #[Test]
    public function showing_the_activation_page_does_not_start_a_session(): void
    {
        $user = User::factory()->approved()->create();

        $response = $this->fromFrontend()->getJson($this->activationPath($user))->assertOk();

        $this->assertNoSessionCookies($response);
    }

    #[Test]
    public function submitting_the_activation_form_does_not_start_a_session(): void
    {
        $user = User::factory()->approved()->create();

        $response = $this->fromFrontend()
            ->postJson($this->activationPath($user), ['nif' => 'invalido'])
            ->assertUnprocessable();

        $this->assertNoSessionCookies($response);
    }

    #[Test]
    public function resending_the_activation_link_does_not_start_a_session(): void
    {
        $response = $this->fromFrontend()
            ->postJson('/api/account-activations/resend', ['email' => 'alguem@exemplo.pt'])
            ->assertOk();

        $this->assertNoSessionCookies($response);
    }
}
