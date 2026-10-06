<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
    }
}
