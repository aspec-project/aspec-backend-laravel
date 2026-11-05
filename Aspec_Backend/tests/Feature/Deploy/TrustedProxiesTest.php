<?php

namespace Tests\Feature\Deploy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FillsRateLimits;
use Tests\TestCase;

/**
 * Proxies de confiança (TRUSTED_PROXIES). No Render, cada pedido chega pelo proxy da plataforma (e antes
 * pelo rewrite do Vercel): sem confiar nos cabeçalhos X-Forwarded-*, todos os visitantes partilhavam o IP
 * do proxy e um só esgotava os limites por IP de todos, e o Laravel não via que o pedido veio por HTTPS.
 *
 * Sem configuração (local, testes, colegas) os cabeçalhos são ignorados, para ninguém forjar o IP.
 * O X-Forwarded-Host nunca é aceite: nada depende do host e confiar nele abriria host header injection.
 *
 * Os pedidos passam pelo middleware global TrustProxies do Laravel, que lê a configuração em cada pedido.
 */
class TrustedProxiesTest extends TestCase
{
    use FillsRateLimits;
    use RefreshDatabase;

    private const PROBE = '/_deploy-test/request-info';

    private const CLIENT_A = '203.0.113.5';

    private const CLIENT_B = '198.51.100.7';

    private const VERCEL_EGRESS = '76.76.21.21';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get(self::PROBE, fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->secure(),
            'host' => $request->getHost(),
        ]));
    }

    #[Test]
    public function trusted_proxies_are_off_by_default(): void
    {
        $this->assertTrue(config()->has('trustedproxy.proxies'));
        $this->assertEmpty(config('trustedproxy.proxies'));
    }

    #[Test]
    public function forwarded_headers_are_ignored_without_trusted_proxies(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->withHeaders([
            'X-Forwarded-For' => self::CLIENT_A,
            'X-Forwarded-Proto' => 'https',
        ])->getJson(self::PROBE)
            ->assertOk()
            ->assertJsonPath('ip', '127.0.0.1')
            ->assertJsonPath('secure', false);
    }

    #[Test]
    public function client_ip_is_the_first_address_of_the_forwarded_chain_behind_trusted_proxies(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->withHeaders(['X-Forwarded-For' => self::CLIENT_A.', '.self::VERCEL_EGRESS])
            ->getJson(self::PROBE)
            ->assertOk()
            ->assertJsonPath('ip', self::CLIENT_A);
    }

    #[Test]
    public function forwarded_https_is_recognised_behind_trusted_proxies(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->withHeaders([
            'X-Forwarded-For' => self::CLIENT_A,
            'X-Forwarded-Proto' => 'https',
        ])->getJson(self::PROBE)
            ->assertOk()
            ->assertJsonPath('secure', true);
    }

    #[Test]
    public function forwarded_host_is_never_trusted(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->withHeaders([
            'X-Forwarded-For' => self::CLIENT_A,
            'X-Forwarded-Host' => 'evil.example',
        ])->getJson('http://aspec-api.onrender.com'.self::PROBE)
            ->assertOk()
            ->assertJsonPath('host', 'aspec-api.onrender.com');
    }

    #[Test]
    public function visitors_behind_the_same_proxy_do_not_share_the_directory_limit(): void
    {
        config(['trustedproxy.proxies' => '*']);
        $this->fillRateLimit('member-directory', self::CLIENT_A, 120);

        $this->withHeaders(['X-Forwarded-For' => self::CLIENT_A.', '.self::VERCEL_EGRESS])
            ->getJson('/api/members')
            ->assertTooManyRequests();

        $this->withHeaders(['X-Forwarded-For' => self::CLIENT_B.', '.self::VERCEL_EGRESS])
            ->getJson('/api/members')
            ->assertOk();
    }
}
