<?php

namespace Tests\Feature\Deploy;

use App\Models\User;
use App\Services\Payments\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Link de ativação no deploy: o email aponta para o frontend no Vercel, o SPA chama a API na mesma
 * origem e o rewrite do Vercel encaminha para o Render. O pedido chega ao Laravel com o host do Render
 * e cabeçalhos X-Forwarded-* do proxy. A assinatura é relativa (só caminho e query), por isso o link
 * tem de continuar válido com ou sem proxies de confiança, e uma assinatura alterada continua recusada.
 */
class ActivationLinkBehindProxyTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_URL = 'https://aspec-plataforma.vercel.app';

    private const API_URL = 'http://aspec-api.onrender.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => self::FRONTEND_URL]);
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function trustedProxies(): array
    {
        return [
            'sem proxies de confiança' => [null],
            'com todos os proxies de confiança' => ['*'],
        ];
    }

    private function apiPathFromEmailLink(User $user): string
    {
        $link = app(SubscriptionService::class)->activationUrl($user);

        $this->assertStringStartsWith(self::FRONTEND_URL.'/ativacao/', $link);

        return str_replace(self::FRONTEND_URL.'/ativacao/', '/api/account-activations/', $link);
    }

    private function throughVercelRewrite(): static
    {
        return $this->withHeaders([
            'Origin' => self::FRONTEND_URL,
            'X-Forwarded-For' => '203.0.113.5, 76.76.21.21',
            'X-Forwarded-Host' => 'aspec-plataforma.vercel.app',
            'X-Forwarded-Proto' => 'https',
        ]);
    }

    #[Test]
    #[DataProvider('trustedProxies')]
    public function activation_link_from_the_email_is_valid_through_the_proxy(?string $proxies): void
    {
        config(['trustedproxy.proxies' => $proxies]);
        $user = User::factory()->approved()->create();

        $this->throughVercelRewrite()
            ->getJson(self::API_URL.$this->apiPathFromEmailLink($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', $user->email);
    }

    #[Test]
    #[DataProvider('trustedProxies')]
    public function tampered_signature_is_rejected_through_the_proxy(?string $proxies): void
    {
        config(['trustedproxy.proxies' => $proxies]);
        $user = User::factory()->approved()->create();
        $path = $this->apiPathFromEmailLink($user);

        $tampered = preg_replace_callback(
            '/signature=([0-9a-f])/',
            fn (array $m) => 'signature='.($m[1] === 'a' ? 'b' : 'a'),
            $path
        );

        $this->assertNotSame($path, $tampered);

        $this->throughVercelRewrite()
            ->getJson(self::API_URL.$tampered)
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }
}
