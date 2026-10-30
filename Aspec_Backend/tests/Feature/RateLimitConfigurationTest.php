<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Números e chaves de cada limitador com nome, lidos diretamente da configuração.
 *
 * Testar 2000 pedidos por hora com pedidos reais seria lento; aqui resolve-se o limitador para um
 * pedido construído à mão e verificam-se os Limit devolvidos. O comportamento HTTP (429, Retry-After,
 * contadores por utilizador ou IP) fica no RateLimitingTest.
 */
class RateLimitConfigurationTest extends TestCase
{
    private const IP = '203.0.113.7';

    /**
     * As 9 ações do membro com 120/min e 2000/hora, cada uma a contar à parte (cancelar/retomar tem o seu).
     *
     * @return array<string, array{string}>
     */
    public static function memberActions(): array
    {
        return [
            'profile-update' => ['profile-update'],
            'logo-upload' => ['logo-upload'],
            'portfolio-upload' => ['portfolio-upload'],
            'portfolio-delete' => ['portfolio-delete'],
            'password-update' => ['password-update'],
            'billing-portal' => ['billing-portal'],
            'invoice-download' => ['invoice-download'],
            'notification-preferences' => ['notification-preferences'],
            'review-submit' => ['review-submit'],
            'review-delete' => ['review-delete'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicReadLimiters(): array
    {
        return [
            'member-directory' => ['member-directory'],
            'public-events' => ['public-events'],
        ];
    }

    private function newUser(): User
    {
        return (new User)->forceFill(['id' => (string) Str::uuid()]);
    }

    /**
     * Pedido com o IP de teste e, se houver, o utilizador autenticado no guard sanctum (sessão da SPA
     * ou Bearer). O guard por omissão (web) fica sem utilizador, como numa rota pública com Bearer.
     */
    private function requestFrom(?User $user = null, array $input = []): Request
    {
        $request = Request::create('/api/rate-limit-probe', 'POST', $input, [], [], ['REMOTE_ADDR' => self::IP]);

        if ($user) {
            $this->app['auth']->guard('sanctum')->setUser($user);
        }

        $request->setUserResolver(fn (?string $guard = null) => $this->app['auth']->guard($guard)->user());

        return $request;
    }

    /**
     * @return list<Limit>
     */
    private function limitsFor(string $name, Request $request): array
    {
        $limiter = RateLimiter::limiter($name);
        $this->assertNotNull($limiter, "O limitador '{$name}' não está definido.");

        $result = $limiter($request);

        return is_array($result) ? array_values($result) : [$result];
    }

    /**
     * @param  list<Limit>  $limits
     */
    private function limitWithDecay(array $limits, int $decaySeconds): ?Limit
    {
        foreach ($limits as $limit) {
            if ($limit->decaySeconds === $decaySeconds) {
                return $limit;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function middlewareOf(Route $route): array
    {
        return array_values(array_filter(
            $this->app['router']->gatherRouteMiddleware($route),
            'is_string'
        ));
    }

    private function hasThrottle(array $middleware): bool
    {
        foreach ($middleware as $name) {
            if (str_starts_with($name, ThrottleRequests::class)) {
                return true;
            }
        }

        return false;
    }

    // C1 — ações do membro

    #[Test]
    #[DataProvider('memberActions')]
    public function member_action_has_a_minute_and_an_hour_limit_per_user(string $action): void
    {
        $user = $this->newUser();
        $limits = $this->limitsFor($action, $this->requestFrom($user));

        $this->assertCount(2, $limits);

        $minute = $this->limitWithDecay($limits, 60);
        $hour = $this->limitWithDecay($limits, 3600);
        $this->assertNotNull($minute, 'Falta o limite por minuto.');
        $this->assertNotNull($hour, 'Falta o limite por hora.');

        $this->assertSame(120, $minute->maxAttempts);
        $this->assertSame(2000, $hour->maxAttempts);
        $this->assertSame('minute:'.$user->id, $minute->key);
        $this->assertSame('hour:'.$user->id, $hour->key);
    }

    #[Test]
    #[DataProvider('memberActions')]
    public function member_action_without_a_user_is_keyed_by_ip(string $action): void
    {
        $limits = $this->limitsFor($action, $this->requestFrom());

        $this->assertSame('minute:'.self::IP, $this->limitWithDecay($limits, 60)?->key);
        $this->assertSame('hour:'.self::IP, $this->limitWithDecay($limits, 3600)?->key);
    }

    /**
     * Cancelar e retomar envia um email por pedido: o limite diário trava quem alterna as duas ações
     * para esgotar a quota de email da plataforma.
     */
    #[Test]
    public function subscription_change_has_a_minute_and_a_daily_limit_per_user(): void
    {
        $user = $this->newUser();
        $limits = $this->limitsFor('subscription-change', $this->requestFrom($user));

        $this->assertCount(2, $limits);

        $minute = $this->limitWithDecay($limits, 60);
        $day = $this->limitWithDecay($limits, 86400);
        $this->assertNotNull($minute, 'Falta o limite por minuto.');
        $this->assertNotNull($day, 'Falta o limite diário.');

        $this->assertSame(120, $minute->maxAttempts);
        $this->assertSame(10, $day->maxAttempts);
        $this->assertSame('minute:'.$user->id, $minute->key);
        $this->assertSame('day:'.$user->id, $day->key);
    }

    // C1 — limite geral da API

    #[Test]
    public function general_api_limit_is_three_hundred_per_minute_per_user_without_an_hourly_cap(): void
    {
        $user = $this->newUser();
        $limits = $this->limitsFor('api', $this->requestFrom($user));

        $this->assertCount(1, $limits);
        $this->assertSame(300, $limits[0]->maxAttempts);
        $this->assertSame(60, $limits[0]->decaySeconds);
        $this->assertSame('minute:'.$user->id, $limits[0]->key);
    }

    #[Test]
    public function general_api_limit_without_a_user_is_keyed_by_ip(): void
    {
        $limits = $this->limitsFor('api', $this->requestFrom());

        $this->assertCount(1, $limits);
        $this->assertSame('minute:'.self::IP, $limits[0]->key);
    }

    // C2 — diretório e agenda

    #[Test]
    #[DataProvider('publicReadLimiters')]
    public function public_read_limit_is_a_hundred_and_twenty_per_minute_per_user(string $name): void
    {
        $user = $this->newUser();
        $limits = $this->limitsFor($name, $this->requestFrom($user));

        $this->assertCount(1, $limits);
        $this->assertSame(120, $limits[0]->maxAttempts);
        $this->assertSame(60, $limits[0]->decaySeconds);
        $this->assertSame((string) $user->id, $limits[0]->key);
    }

    #[Test]
    #[DataProvider('publicReadLimiters')]
    public function public_read_limit_for_visitors_is_keyed_by_ip(string $name): void
    {
        $limits = $this->limitsFor($name, $this->requestFrom());

        $this->assertCount(1, $limits);
        $this->assertSame(120, $limits[0]->maxAttempts);
        $this->assertSame(self::IP, $limits[0]->key);
    }

    // C3 — registo

    #[Test]
    public function registration_is_limited_to_twenty_per_minute_per_ip(): void
    {
        $limits = $this->limitsFor('auth-register', $this->requestFrom($this->newUser()));

        $this->assertCount(1, $limits);
        $this->assertSame(20, $limits[0]->maxAttempts);
        $this->assertSame(60, $limits[0]->decaySeconds);
        $this->assertSame(self::IP, $limits[0]->key);
    }

    // C4 — limites que não mudam

    #[Test]
    public function login_limit_is_unchanged(): void
    {
        $limits = $this->limitsFor('auth-login', $this->requestFrom(null, ['email' => ' Membro@Exemplo.pt ']));

        $this->assertCount(1, $limits);
        $this->assertSame(5, $limits[0]->maxAttempts);
        $this->assertSame(60, $limits[0]->decaySeconds);
        $this->assertSame('membro@exemplo.pt|'.self::IP, $limits[0]->key);
    }

    #[Test]
    public function admin_anonymize_limit_is_unchanged(): void
    {
        $limits = $this->limitsFor('admin-anonymize', $this->requestFrom($this->newUser()));

        $this->assertCount(1, $limits);
        $this->assertSame(5, $limits[0]->maxAttempts);
        $this->assertSame(3600, $limits[0]->decaySeconds);
    }

    #[Test]
    public function admin_dashboard_limit_is_sixty_per_minute_per_admin(): void
    {
        $user = $this->newUser();
        $limits = $this->limitsFor('admin-dashboard', $this->requestFrom($user));

        $this->assertCount(1, $limits);
        $this->assertSame(60, $limits[0]->maxAttempts);
        $this->assertSame(60, $limits[0]->decaySeconds);
        $this->assertStringContainsString((string) $user->id, $limits[0]->key);
    }

    #[Test]
    public function admin_dashboard_limit_without_a_user_is_keyed_by_ip(): void
    {
        $limits = $this->limitsFor('admin-dashboard', $this->requestFrom());

        $this->assertCount(1, $limits);
        $this->assertStringContainsString(self::IP, $limits[0]->key);
    }

    // C5 — onde se aplica o limite geral

    #[Test]
    public function stripe_webhook_has_no_throttle(): void
    {
        $route = RouteFacade::getRoutes()->getByName('stripe.webhook');
        $this->assertNotNull($route);

        $this->assertFalse($this->hasThrottle($this->middlewareOf($route)));
    }

    #[Test]
    public function routes_without_their_own_limiter_get_the_general_api_limit(): void
    {
        $route = RouteFacade::getRoutes()->match(Request::create('/api/sectors', 'GET'));

        $this->assertContains(ThrottleRequests::class.':api', $this->middlewareOf($route));
    }
}
