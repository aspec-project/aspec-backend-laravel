<?php

namespace Tests\Concerns;

use App\Contracts\InvoiceService;
use App\Models\EventInstance;
use App\Models\MemberProfile;
use App\Models\User;
use App\Services\Invoicing\Data\InvoiceCustomerData;
use App\Services\Invoicing\Data\InvoiceData;
use App\Services\Invoicing\Data\IssuedInvoice;
use Carbon\CarbonInterface;
use Closure;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Ajudas comuns aos testes do DemoSeeder: correr o seeder como na linha de comandos, encontrar as
 * contas e os perfis de demonstração, datas em Lisboa e um serviço de faturação rápido.
 *
 * Os testes verificam regras (contagens, intervalos, unicidade, domínios) e não o texto exato dos
 * dados de demonstração, que pode ser reescrito sem partir os testes.
 */
trait SeedsDemoData
{
    /**
     * Discos falsos e serviço de faturação rápido; chamar no setUp() de cada classe.
     */
    protected function setUpDemoSeeder(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $this->app->instance(InvoiceService::class, $this->fastInvoiceService());
    }

    /**
     * Cada fatura demo gera um PDF com o dompdf (cerca de 0,25 s); com dezenas de faturas por execução,
     * os testes que não olham para os PDFs usam este serviço, que devolve o mesmo que o driver demo
     * sem gerar o PDF nem enviar o email.
     */
    protected function fastInvoiceService(): InvoiceService
    {
        return new class implements InvoiceService
        {
            public function issue(InvoiceCustomerData $customer, InvoiceData $invoice, ?string $draftId = null, ?Closure $onDraftCreated = null): IssuedInvoice
            {
                $number = 'DEMO-'.$invoice->date->format('Y').'-'.strtoupper(substr(sha1($invoice->stripeInvoiceId), 0, 8));

                return new IssuedInvoice('demo', "invoices/{$customer->code}/{$number}.pdf", $number, null, IssuedInvoice::ISSUED);
            }

            public function pdf(string $providerInvoiceId): ?string
            {
                return null;
            }
        };
    }

    /**
     * Repõe o serviço de faturação configurado, para o seeder emitir as faturas pelo driver demo real (PDF e email).
     */
    protected function useRealInvoiceService(): void
    {
        $this->app->forgetInstance(InvoiceService::class);
    }

    /**
     * Corre o seeder como na linha de comandos; o --force evita a confirmação do Laravel em
     * produção, para ser a proteção do próprio seeder a decidir.
     */
    protected function runDemoSeeder(): void
    {
        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->assertSuccessful();
    }

    protected function demoUser(string $email): User
    {
        return User::with(['role', 'accountStatus', 'memberProfile'])->where('email', $email)->firstOrFail();
    }

    /**
     * Corre o seeder e devolve a conta demo indicada.
     */
    protected function runAndGet(string $email): User
    {
        $this->runDemoSeeder();

        return $this->demoUser($email);
    }

    /**
     * Todas as contas de demonstração (emails demo.*@aspec.test).
     *
     * @return EloquentCollection<int, User>
     */
    protected function demoUsers(): EloquentCollection
    {
        return User::with(['role', 'accountStatus', 'memberProfile'])
            ->where('email', 'like', 'demo.%@aspec.test')
            ->get();
    }

    /**
     * Perfis das contas de demonstração, de qualquer estado.
     *
     * @return EloquentCollection<int, MemberProfile>
     */
    protected function demoProfiles(): EloquentCollection
    {
        return MemberProfile::with(['user', 'sector', 'location'])
            ->whereHas('user', fn ($query) => $query->where('email', 'like', 'demo.%@aspec.test'))
            ->get();
    }

    /**
     * Perfis que aparecem no diretório (conta Active).
     *
     * @return EloquentCollection<int, MemberProfile>
     */
    protected function directoryProfiles(): EloquentCollection
    {
        return MemberProfile::listedInDirectory()->with(['user', 'sector', 'location'])->get();
    }

    /**
     * Todos os itens do diretório público, página a página (50 por página, o máximo da API).
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function directoryItems(array $query = []): Collection
    {
        $items = collect();
        $page = 1;

        do {
            $response = $this->getJson('/api/members?'.http_build_query([...$query, 'per_page' => 50, 'page' => $page]))->assertOk();
            $items = $items->concat($response->json('data.items'));
            $lastPage = $response->json('data.pagination.last_page');
            $page++;
        } while ($page <= $lastPage);

        return $items;
    }

    /**
     * Fuso horário dos eventos (o da agenda pública e do backoffice).
     */
    protected function eventTimezone(): string
    {
        return config('app.event_timezone', 'Europe/Lisbon');
    }

    /**
     * Dia de hoje em Lisboa, como no seeder e na agenda pública (a aplicação corre em UTC).
     */
    protected function lisbonToday(): Carbon
    {
        return now($this->eventTimezone())->startOfDay();
    }

    /**
     * Segunda-feira da semana atual em Lisboa, como no painel do backoffice.
     */
    protected function lisbonWeekStart(): Carbon
    {
        return $this->lisbonToday()->startOfWeek(CarbonInterface::MONDAY);
    }

    /**
     * Prazo de inscrição do evento como instante: está guardado na hora de Lisboa, por isso é lido
     * a partir do valor em bruto (o cast datetime interpretá-lo-ia em UTC).
     */
    protected function registrationDeadlineAt(EventInstance $event): Carbon
    {
        return Carbon::parse($event->getRawOriginal('registration_deadline'), $this->eventTimezone());
    }

    /**
     * Eventos únicos (fora das séries).
     *
     * @return EloquentCollection<int, EventInstance>
     */
    protected function oneOffEvents(): EloquentCollection
    {
        return EventInstance::with(['location', 'category'])->withCount('registrations')->whereNull('series_id')->get();
    }

    /**
     * As datas do seeder são relativas a "agora"; a tolerância (por omissão um dia) aceita que o
     * seeder use o início do dia ou a hora exata.
     */
    protected function assertAbout(CarbonInterface $expected, ?CarbonInterface $actual, string $message = '', int $toleranceSeconds = 86400): void
    {
        $this->assertNotNull($actual, $message);
        $this->assertEqualsWithDelta($expected->getTimestamp(), $actual->getTimestamp(), $toleranceSeconds, $message);
    }

    /**
     * Marcas reais que não podem aparecer nos dados de demonstração (empresas e locais são
     * fictícios). Só nomes sem outro significado em português, para não dar falsos positivos.
     *
     * @return list<string>
     */
    protected function forbiddenBrands(): array
    {
        return [
            'Tivoli', 'Pestana', 'Vila Galé', 'Sheraton', 'Marriott', 'Hilton', 'Novotel', 'Meliá', 'Ibis', 'Holiday Inn', 'Accor',
            'Pingo Doce', 'Lidl', 'Auchan', 'Worten', 'Sonae', 'Mercadona', 'IKEA', 'Leroy Merlin', 'Fnac', 'Zara', 'Primark',
            'Galp', 'EDP', 'MEO', 'Vodafone', 'Altice', 'Millennium', 'BCP', 'Caixa Geral', 'Santander', 'Novo Banco', 'Montepio',
            'Starbucks', "McDonald's", 'Coca-Cola', 'Nespresso', 'Sagres', 'Super Bock', 'Delta Cafés', 'Vista Alegre',
            'Deloitte', 'PwC', 'KPMG', 'Accenture', 'Uber', 'Glovo', 'Airbnb', 'Booking.com',
        ];
    }

    /**
     * Falha se o texto contiver uma marca da lista proibida (palavra inteira, sem distinguir maiúsculas).
     */
    protected function assertNoForbiddenBrand(?string $text, string $message): void
    {
        if ($text === null) {
            return;
        }

        foreach ($this->forbiddenBrands() as $brand) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($brand, '/').'(?![\p{L}\p{N}])/iu';

            $this->assertDoesNotMatchRegularExpression($pattern, $text, "{$message}: marca \"{$brand}\"");
        }
    }
}
