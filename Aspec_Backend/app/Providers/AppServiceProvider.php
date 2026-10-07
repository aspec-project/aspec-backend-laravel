<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configurePasswordRules();
    }

    /**
     * Regra de password única para registo e alteração de password, igual à do frontend:
     * mín. 8 caracteres, maiúsculas e minúsculas, um número e um símbolo.
     *
     * Só se aplica onde a regra é Password::default(). Sem uncompromised(): faria um pedido
     * à API do Have I Been Pwned em cada registo (melhoria futura, ver relatório).
     */
    private function configurePasswordRules(): void
    {
        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers()->symbols());
    }

    /**
     * Um limitador por ação, com 10 pedidos por minuto por utilizador.
     *
     * Com throttle:10,1 sem nome, o Laravel usa só o utilizador como chave e todas as rotas
     * partilham o mesmo contador: guardar a montra (PUT + logótipo + até 10 imagens) dava 429
     * a meio. Com limitadores com nome, a chave inclui o nome e cada ação conta à parte.
     */
    private function configureRateLimiting(): void
    {
        $actions = [
            'profile-update',
            'logo-upload',
            'portfolio-upload',
            'portfolio-delete',
            'password-update',
        ];

        foreach ($actions as $action) {
            RateLimiter::for($action, fn (Request $request) => Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip()));
        }


        $authActions = [
        'auth-login',
        'auth-token',
        ];

        foreach ($authActions as $action) {
            RateLimiter::for(
                $action,
                fn (Request $request) => Limit::perMinute(5)
                    ->by(
                        strtolower(trim((string) $request->input('email')))
                        . '|' . $request->ip()
                    )
            );
        }

        RateLimiter::for(
            'auth-register',
            fn (Request $request) => Limit::perMinute(5)
                ->by($request->ip())
        );
    }
}
