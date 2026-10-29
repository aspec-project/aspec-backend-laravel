<?php

namespace App\Providers;

use App\Contracts\Geocoder;
use App\Contracts\InvoiceService;
use App\Models\User;
use App\Services\Geocoding\GeocoderFactory;
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
     * Limite geral da API por minuto, acima dos específicos para que estes continuem a ser o limite efetivo.
     */
    private const API_PER_MINUTE = 300;

    /**
     * Ações do membro, diretório e eventos públicos: 120 por minuto (trava scripts, não o uso humano).
     */
    private const PER_MINUTE = 120;

    /**
     * Ações do membro: 2000 por hora (valor sugerido pelos professores).
     */
    private const PER_HOUR = 2000;

    /**
     * Cancelar e retomar a subscrição: 10 por dia. Ação rara que chama o Stripe e envia um email; com
     * 2000/hora, alternar as duas esgotava a quota diária do serviço de email de toda a plataforma.
     */
    private const SUBSCRIPTION_CHANGES_PER_DAY = 10;

    private const REGISTER_PER_MINUTE = 20;

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
     * Liga o contrato Geocoder ao fornecedor de `services.geocoding.driver` (nominatim ou google).
     *
     * `bind` e não `singleton`: a config é lida sempre que o contrato é resolvido, por isso
     * mudar a config (ex. nos testes) muda o fornecedor. Fornecedor desconhecido ou Google sem
     * chave falham ao resolver, com mensagens sem os valores configurados (ver GeocoderFactory).
     *
     * @throws InvalidArgumentException
     */
    private function registerGeocoder(): void
    {
        $this->app->bind(Geocoder::class, fn ($app) => $app->make(GeocoderFactory::class)
            ->make((string) config('services.geocoding.driver')));
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
     * Limites de pedidos: um geral para toda a API e um por ação nas rotas de risco.
     *
     * O limite geral (`api`, 300/min por utilizador ou IP) cobre as rotas sem limitador próprio,
     * incluindo as futuras. Cada ação do membro tem o seu (120/min e 2000/hora): com throttle:10,1
     * sem nome, o Laravel usava só o utilizador como chave e todas as rotas partilhavam o mesmo
     * contador (guardar a montra dava 429 a meio); com limitadores com nome, cada ação conta à parte.
     * Num pedido com os dois, ganha o primeiro que se esgotar.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(self::API_PER_MINUTE)
            ->by('minute:'.$this->requesterKey($request)));

        $actions = [
            'profile-update',
            'logo-upload',
            'portfolio-upload',
            'portfolio-delete',
            'password-update',
            'billing-portal',
            'invoice-download',
            'notification-preferences',
            'review-submit',
        ];

        foreach ($actions as $action) {
            RateLimiter::for($action, fn (Request $request) => [
                Limit::perMinute(self::PER_MINUTE)->by('minute:'.$this->requesterKey($request)),
                Limit::perHour(self::PER_HOUR)->by('hour:'.$this->requesterKey($request)),
            ]);
        }

        RateLimiter::for('subscription-change', fn (Request $request) => [
            Limit::perMinute(self::PER_MINUTE)->by('minute:'.$this->requesterKey($request)),
            Limit::perDay(self::SUBSCRIPTION_CHANGES_PER_DAY)->by('day:'.$this->requesterKey($request)),
        ]);

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

        // Registo: 20/min por IP, para um grupo se poder inscrever da mesma rede num evento.
        RateLimiter::for(
            'auth-register',
            fn (Request $request) => Limit::perMinute(self::REGISTER_PER_MINUTE)
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

        // Diretório público: 120/min, partilhado por lista e perfil. Visitantes por IP (trava a recolha automática
        // de perfis); membros com login pelo seu id, para não se bloquearem uns aos outros atrás do mesmo IP.
        RateLimiter::for(
            'member-directory',
            fn (Request $request) => Limit::perMinute(self::PER_MINUTE)->by($this->requesterKey($request))
        );

        // Eventos públicos: 120/min, por utilizador com login ou por IP, como o diretório.
        RateLimiter::for(
            'public-events',
            fn (Request $request) => Limit::perMinute(self::PER_MINUTE)->by($this->requesterKey($request))
        );

        // Localizar moradas no backoffice: 30/min por admin, para um separador ou script não repetir pedidos ao fornecedor de geocodificação.
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

        // Criação de eventos pelo admin: 20/hora por admin.
        RateLimiter::for(
            'admin-event-create',
            fn (Request $request) => Limit::perHour(20)
                ->by($request->user()?->id ?: $request->ip())
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

    /**
     * Id do utilizador autenticado (sessão ou Bearer, guard sanctum) ou, sem login válido, o IP.
     *
     * Usa o guard sanctum e não o por omissão (web), que ignora o Bearer nas rotas públicas.
     * Token inválido ou expirado não dá utilizador, por isso a chave cai no IP e não se falsifica.
     */
    private function requesterKey(Request $request): string
    {
        return (string) ($request->user('sanctum')?->getAuthIdentifier() ?: $request->ip());
    }
}
