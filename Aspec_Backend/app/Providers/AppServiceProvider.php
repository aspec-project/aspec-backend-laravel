<?php

namespace App\Providers;

use App\Contracts\InvoiceService;
use App\Services\Invoicing\InvoiceExpressInvoiceService;
use App\Services\Invoicing\LogInvoiceService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerInvoiceService();
    }

    /**
     * Liga o contrato InvoiceService ao driver de `services.invoicing.driver` (log ou invoiceexpress).
     *
     * `bind` e não `singleton`: a config é lida sempre que o contrato é resolvido, por isso
     * mudar a config (ex. nos testes) muda o driver. Driver desconhecido ou InvoiceExpress sem
     * conta/chave falham logo, com mensagens sem os valores configurados.
     *
     * @throws InvalidArgumentException
     */
    private function registerInvoiceService(): void
    {
        $this->app->bind(InvoiceService::class, fn () => match (config('services.invoicing.driver')) {
            'log' => new LogInvoiceService,
            'invoiceexpress' => $this->makeInvoiceExpressService(config('services.invoiceexpress')),
            default => throw new InvalidArgumentException('Driver de faturação desconhecido.'),
        });
    }

    /**
     * Cria o driver InvoiceExpress com os valores da config.
     *
     * @throws InvalidArgumentException Se faltar o nome da conta ou a chave da API, ou se o nome da
     *                                  conta ou o tipo de documento tiverem caracteres que mudem o URL.
     */
    private function makeInvoiceExpressService(#[\SensitiveParameter] array $config): InvoiceExpressInvoiceService
    {
        if (blank($config['account_name'] ?? null) || blank($config['api_key'] ?? null)) {
            throw new InvalidArgumentException('Faltam o nome da conta ou a chave da API da InvoiceExpress.');
        }

        // Os dois valores entram no URL: um nome de conta como "evil.example/x?" mudava o host e
        // mandava a api_key para outro servidor; um tipo com "/" ou "?" mudava o caminho.
        if (! preg_match('/^[a-z0-9-]+$/i', $config['account_name'])) {
            throw new InvalidArgumentException('Nome da conta InvoiceExpress inválido.');
        }

        if (! preg_match('/^[a-z_]+$/', (string) ($config['document_type'] ?? ''))) {
            throw new InvalidArgumentException('Tipo de documento InvoiceExpress inválido.');
        }

        return new InvoiceExpressInvoiceService(
            accountName: $config['account_name'],
            apiKey: $config['api_key'],
            documentType: $config['document_type'],
            sequenceId: filled($config['sequence_id'] ?? null) ? (string) $config['sequence_id'] : null,
            itemName: $config['item_name'],
            taxName: $config['tax_name'],
            vatRate: (int) $config['vat_rate'],
            taxExemption: filled($config['tax_exemption'] ?? null) ? $config['tax_exemption'] : null,
            timeout: (int) $config['timeout'],
            retryTimes: (int) $config['retry_times'],
            retrySleepMs: (int) $config['retry_sleep_ms'],
        );
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
