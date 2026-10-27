<?php

namespace App\Providers;

use App\Contracts\Geocoder;
use App\Contracts\InvoiceService;
use App\Models\User;
use App\Services\Geocoding\NominatimGeocoder;
use App\Services\Invoicing\InvoiceServiceFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerInvoiceService();
        $this->registerGeocoder();
        $this->configureCashier();
    }

    /**
     * Sem as rotas do Cashier (webhook e página de pagamento): nenhum endpoint de pagamentos
     * fica exposto antes de termos o nosso webhook com assinatura e idempotência.
     * Subscrição past_due continua ativa: na semana de carência o membro mantém o acesso.
     * Tem de correr no register(), antes de o CashierServiceProvider registar as rotas no boot().
     */
    private function configureCashier(): void
    {
        Cashier::ignoreRoutes();
        Cashier::keepPastDueSubscriptionsActive();
    }

    /**
     * Liga o contrato InvoiceService ao driver de `services.invoicing.driver` (log, demo ou invoiceexpress).
     *
     * `bind` e não `singleton`: a config é lida sempre que o contrato é resolvido, por isso
     * mudar a config (ex. nos testes) muda o driver. Driver desconhecido ou InvoiceExpress sem
     * conta/chave falham logo, com mensagens sem os valores configurados (ver InvoiceServiceFactory).
     *
     * @throws InvalidArgumentException
     */
    private function registerInvoiceService(): void
    {
        $this->app->bind(InvoiceService::class, fn ($app) => $app->make(InvoiceServiceFactory::class)
            ->make((string) config('services.invoicing.driver')));
    }

    /**
     * Liga o contrato Geocoder à implementação com o Nominatim.
     *
     * `bind` e não `singleton`: a implementação lê a config em cada chamada, por isso mudar
     * a config (ex. nos testes) tem efeito imediato.
     */
    private function registerGeocoder(): void
    {
        $this->app->bind(Geocoder::class, NominatimGeocoder::class);
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
            'billing-portal',
            'subscription-change',
            'invoice-download',
            'notification-preferences',
        ];

        foreach ($actions as $action) {
            RateLimiter::for($action, fn (Request $request) => Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip()));
        }

        $authActions = [
            'auth-login',
            'auth-token',
            'auth-forgot-password',
        ];

        foreach ($authActions as $action) {
            RateLimiter::for(
                $action,
                fn (Request $request) => Limit::perMinute(5)
                    ->by(
                        strtolower(trim((string) $request->input('email')))
                        .'|'.$request->ip()
                    )
            );
        }

        // Limite por IP para pedidos de "forgot password".
        RateLimiter::for(
            'auth-forgot-password-ip',
            fn (Request $request) => Limit::perHour(20)
                ->by($request->ip())
        );

        // Registo: 5/min por IP.
        RateLimiter::for(
            'auth-register',
            fn (Request $request) => Limit::perMinute(5)
                ->by($request->ip())
        );

        // Links de ativação: 20/min por IP. Corre antes da verificação da assinatura, por isso
        // links forjados também contam; protege sobretudo as chamadas ao Stripe de cada POST.
        RateLimiter::for(
            'account-activation',
            fn (Request $request) => Limit::perMinute(20)->by($request->ip())
        );

        // O limite diário por email está no controller: aqui apareceria nos cabeçalhos X-RateLimit.
        RateLimiter::for('activation-resend', fn (Request $request) => [
            Limit::perMinutes(15, 3)->by('email:'.strtolower(trim((string) $request->input('email'))).'|'.$request->ip()),
            Limit::perHour(10)->by('ip:'.$request->ip()),
        ]);

        // Reset password: 5/min por IP.
        RateLimiter::for(
            'auth-reset-password',
            fn (Request $request) => Limit::perMinute(5)->by($request->ip())
        );

        // Diretório público: 60/min por IP, partilhado por lista e perfil; trava a recolha automática de perfis.
        RateLimiter::for(
            'member-directory',
            fn (Request $request) => Limit::perMinute(60)->by($request->ip())
        );

        // Eventos públicos: 60/min por IP.
        RateLimiter::for(
            'public-events',
            fn (Request $request) => Limit::perMinute(60)->by($request->ip())
        );

        // Localizar moradas no backoffice: 30/min por admin, para um só separador não esgotar o limite global de 1/s do Nominatim.
        RateLimiter::for(
            'geocode',
            fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip())
        );

        // Inscritos de um evento (JSON e CSV, limite partilhado): 30/min por admin, por entregarem contactos pessoais em lote.
        RateLimiter::for(
            'event-attendees',
            fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip())
        );

        // Anonimizar contas: 5/hora por admin. Ação irreversível e rara; trava uma sessão de admin roubada ou um script repetido.
        RateLimiter::for(
            'admin-anonymize',
            fn (Request $request) => Limit::perHour(5)->by($request->user()?->id ?: $request->ip())
        );

        // Emails de pagamento pelo backoffice: 30/hora por admin (sessão roubada ou script) e 3/hora por membro alvo
        // (duplo clique ou dois admins ao mesmo tempo). O throttle corre antes do route model binding, por isso o alvo
        // pode ainda ser a string do UUID (em minúsculas, porque o MySQL encontra o mesmo id em qualquer caixa).
        RateLimiter::for('admin-payment-link', function (Request $request) {
            $target = $request->route('user');

            return [
                Limit::perHour(30)->by('admin:'.($request->user()?->id ?: $request->ip())),
                Limit::perHour(3)->by('target:'.strtolower($target instanceof User ? $target->id : (string) $target)),
            ];
        });
    }
}
