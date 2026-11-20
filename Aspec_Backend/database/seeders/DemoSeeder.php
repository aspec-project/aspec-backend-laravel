<?php

namespace Database\Seeders;

use App\Enums\InactiveReason;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionInterval;
use App\Jobs\IssueInvoiceJob;
use App\Models\AccountStatus;
use App\Models\BusinessHour;
use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\EventSeries;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\MemberProfile;
use App\Models\Portfolio;
use App\Models\PrayerCard;
use App\Models\Review;
use App\Models\ReviewReport;
use App\Models\ReviewStatus;
use App\Models\Role;
use App\Models\Sector;
use App\Models\SocialLink;
use App\Models\SocialPlatform;
use App\Models\User;
use App\Models\WeekDay;
use App\Services\Events\EventSeriesSchedule;
use App\Support\MediaStorage;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;
use League\Flysystem\FilesystemException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Dados de demonstração para a validação e a defesa: 60 perfis no diretório, 13 contas noutros
 * estados, avaliações e moderação, eventos únicos (futuros, online e passados), duas séries,
 * inscrições, cartões de oração, subscrições e faturas ao longo de 18 meses. Só para
 * desenvolvimento e apresentações; corre à parte do DatabaseSeeder:
 * php artisan db:seed --class=DemoSeeder
 *
 * Os textos estão em database/seeders/demo/data (ficheiros PHP que só devolvem arrays); este
 * ficheiro tem a mecânica. Telefones, NIFs, datas, inscritos e portefólio são gerados de forma
 * determinística a partir da chave de cada conta, por isso correr de novo dá o mesmo resultado.
 *
 * Pode correr várias vezes: procura as contas pelo email, os perfis pelo utilizador, os eventos
 * pelo nome e distrito e as séries pelas suas sessões, e repõe os dados originais (avaliações entre
 * perfis de demonstração, inscrições e cartões de oração desses eventos). Nunca apaga contas nem
 * eventos que não sejam da demonstração; só apaga os eventos antigos listados em "retired".
 * Os cartões de oração ficam encriptados com a APP_KEY de quem corre o seeder.
 * Cria subscrições e faturas de demonstração sem chamar o Stripe nem o InvoiceExpress: ids falsos
 * com "_demo_" e PDFs do driver demo, apagados e criados de novo em cada execução.
 */
class DemoSeeder extends Seeder
{
    /** Password de todas as contas de demonstração (cumpre as regras de password da aplicação). */
    public const PASSWORD = 'Demo#2026';

    /** Linhas por insert em lote (inscrições, avaliações, horários, redes sociais). */
    private const BATCH = 200;

    /** Perfis do diretório com portefólio. */
    private const PORTFOLIO_PROFILES = 35;

    /** Posições do diretório tratadas como "primeira página" (12 por página, com margem para a ordenação do MySQL). */
    private const FIRST_PAGE_MARGIN = 14;

    /** Conjunto de fotografias (database/seeders/demo/portfolio/{conjunto}-N.jpg) de cada setor. */
    private const PORTFOLIO_SETS = [
        'Agricultura e Pescas' => 'agricultura',
        'Arquitetura e Design' => 'arquitetura',
        'Comércio Retalhista' => 'comercio',
        'Construção Civil e Obras Públicas' => 'construcao',
        'Consultoria de Gestão e Negócios' => 'consultoria',
        'Contabilidade e Finanças' => 'contabilidade',
        'Educação e Formação' => 'educacao',
        'Engenharia e Indústria' => 'engenharia',
        'Estética e Bem-Estar' => 'estetica',
        'Imobiliário' => 'imobiliario',
        'Marketing, Comunicação e Publicidade' => 'marketing',
        'Restauração e Hotelaria' => 'restauracao',
        'Saúde e Medicina' => 'saude',
        'Serviços Jurídicos e Advocacia' => 'juridico',
        'Tecnologias de Informação (TI)' => 'ti',
        'Transportes e Logística' => 'transportes',
        'Turismo e Lazer' => 'turismo',
    ];

    /** Indicativo da rede fixa de cada distrito, para os telefones comerciais. */
    private const AREA_CODES = [
        'Aveiro' => '234', 'Beja' => '284', 'Braga' => '253', 'Bragança' => '273', 'Castelo Branco' => '272',
        'Coimbra' => '239', 'Évora' => '266', 'Faro' => '289', 'Guarda' => '271', 'Leiria' => '244',
        'Lisboa' => '21', 'Portalegre' => '245', 'Porto' => '22', 'Santarém' => '243', 'Setúbal' => '265',
        'Viana do Castelo' => '258', 'Vila Real' => '259', 'Viseu' => '232', 'Açores' => '296', 'Madeira' => '291',
    ];

    /**
     * Contas com telefone pessoal estrangeiro (os números internacionais também têm de funcionar), de
     * gamas reservadas para ficção: Austrália (ACMA, 0491 570 156) e EUA (NANPA, 555-0100 a 555-0199).
     * A gama de ficção do Reino Unido (07700 900xxx) não serve: a validação de telefones recusa-a.
     */
    private const FOREIGN_PHONES = [
        'membro04' => '+61491570156',
        'membro07' => '+12025550104',
    ];

    /** NIFs da versão anterior da demonstração, mantidos nas mesmas contas. */
    private const FIXED_NIFS = [
        'membro' => '509000010', 'admin' => '509000029', 'membro07' => '509000037', 'membro02' => '509000045',
        'membro01' => '509000053', 'membro03' => '509000061', 'membro04' => '509000070', 'membro05' => '509000088',
        'membro06' => '509000096', 'membro08' => '509000100', 'membro09' => '509000118', 'membro10' => '509000126',
        'membro11' => '509000134', 'inativo' => '509000142', 'bloqueado' => '509000150',
    ];

    /**
     * Descrição dos eventos da versão anterior da demonstração, por nome. Um evento da lista "retired"
     * só é apagado se ainda tiver esta descrição: com outra, foi criado ou editado à mão e fica.
     */
    private const PREVIOUS_DESCRIPTIONS = [
        'Pequeno-almoço de Negócios ASPEC' => 'Um pequeno-almoço para conhecer outros empresários da região, apresentar o seu negócio em dois minutos e criar parcerias.',
        'Jantar de Networking ASPEC' => 'Jantar informal entre membros e convidados, com um breve testemunho empresarial e tempo livre para conversar.',
    ];

    /** Emails que não seguem a regra demo.{chave}@aspec.test (login da versão anterior). */
    private const LEGACY_EMAILS = [
        'inativo' => 'demo.inativo.pagamento@aspec.test',
    ];

    /** Marcas reais que não podem aparecer nos textos (empresas e locais são fictícios). */
    private const FORBIDDEN_BRANDS = [
        'Tivoli', 'Pestana', 'Vila Galé', 'Sheraton', 'Marriott', 'Hilton', 'Novotel', 'Meliá', 'Ibis', 'Holiday Inn', 'Accor',
        'Pingo Doce', 'Lidl', 'Auchan', 'Worten', 'Sonae', 'Mercadona', 'IKEA', 'Leroy Merlin', 'Fnac', 'Zara', 'Primark',
        'Galp', 'EDP', 'MEO', 'Vodafone', 'Altice', 'Millennium', 'BCP', 'Caixa Geral', 'Santander', 'Novo Banco', 'Montepio',
        'Starbucks', "McDonald's", 'Coca-Cola', 'Nespresso', 'Sagres', 'Super Bock', 'Delta Cafés', 'Vista Alegre',
        'Deloitte', 'PwC', 'KPMG', 'Accenture', 'Uber', 'Glovo', 'Airbnb', 'Booking.com',
    ];

    /** Ids dos lookups já procurados, por modelo e nome. */
    private array $lookupIds = [];

    /** Hash da password das contas (calculado uma vez: o bcrypt é lento e seriam 73 contas). */
    private string $passwordHash = '';

    /** Instante de referência da execução (ao segundo), para todas as datas relativas baterem certo. */
    private Carbon $now;

    /** Plano de cada conta (email, papel, estado, datas, subscrição), por chave. */
    private array $plans = [];

    /** Id do perfil de cada conta, por chave. */
    private array $profileIds = [];

    /** Contas Active que podem inscrever-se em eventos, ordenadas pela chave: [key, user_id, created_at]. */
    private array $activePool = [];

    /** Ids de todas as contas de demonstração (qualquer estado). Inscrições e cartões de outras contas nunca são apagados. */
    private array $demoUserIds = [];

    /** Pedidos dos cartões de oração [Pessoal, Emprego, Salvação]. */
    private array $prayerRequests = [];

    public function __construct(private readonly DemoLogoGenerator $logos) {}

    /**
     * Password das contas de demonstração: a de DEMO_PASSWORD ou, em local/testing, a pública
     * Demo#2026 se não houver nenhuma. Fora de local/testing devolve null (recusa) se estiver vazia
     * ou for a pública, porque o repositório é público e qualquer pessoa a conhece.
     */
    private function demoPassword(): ?string
    {
        $password = (string) config('aspec.demo_password');

        if (app()->environment('local', 'testing')) {
            return $password !== '' ? $password : self::PASSWORD;
        }

        return $password !== '' && $password !== self::PASSWORD ? $password : null;
    }

    /**
     * Cria ou repõe os dados de demonstração. Recusa correr em produção e, se os lookups
     * ainda não existirem, corre primeiro o DatabaseSeeder.
     *
     * @throws RuntimeException Se os ficheiros de dados violarem alguma regra (ver validateData()).
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('O DemoSeeder não pode correr em produção.');

            return;
        }

        $password = $this->demoPassword();

        if ($password === null) {
            $this->command?->error('Defina DEMO_PASSWORD (diferente de '.self::PASSWORD.') para criar as contas de demonstração fora de local/testing.');

            return;
        }

        $this->passwordHash = Hash::make($password);
        $this->now = now()->startOfSecond();

        if ($this->lookupsMissing()) {
            $this->call(DatabaseSeeder::class);
        }

        $members = $this->data('members');
        $applicants = $this->data('applicants');
        $events = $this->data('events');
        $reviews = $this->data('reviews');
        $this->prayerRequests = $this->data('prayer_requests');

        $this->validateData($members, $applicants, $events, $reviews);

        foreach ([...$members, ...$applicants] as $key => $account) {
            $this->plans[$key] = $this->accountPlan($key, $account);
            $this->profileIds[$key] = $this->seedAccount($this->plans[$key], $account)->id;
        }

        DB::transaction(fn () => $this->syncHoursAndSocialLinks([...$members, ...$applicants]));

        $this->seedMedia($members, $applicants);
        $this->seedPayments();
        $this->loadActivePool();

        DB::transaction(fn () => $this->seedReviews($reviews));
        DB::transaction(fn () => $this->seedEvents($events));

        $this->printSummary();
    }

    /**
     * Indica se falta algum dos lookups de que os dados de demonstração dependem.
     */
    private function lookupsMissing(): bool
    {
        $lookups = [Role::class, AccountStatus::class, ReviewStatus::class, Sector::class, Location::class, WeekDay::class, SocialPlatform::class, EventCategory::class];

        return collect($lookups)->contains(fn (string $model) => $model::query()->doesntExist());
    }

    /**
     * Lê um ficheiro de dados (database/seeders/demo/data/{nome}.php). Usa require e não
     * require_once: o seeder pode correr várias vezes no mesmo processo (testes).
     */
    private function data(string $name): array
    {
        return require database_path("seeders/demo/data/{$name}.php");
    }

    /**
     * Valida os ficheiros de dados antes de escrever na base de dados, para uma gralha nos textos
     * falhar logo com uma mensagem clara em vez de deixar a demonstração a meio.
     *
     * @throws RuntimeException Com a lista de problemas encontrados.
     */
    private function validateData(array $members, array $applicants, array $events, array $reviews): void
    {
        $errors = [];
        $accounts = [...$members, ...$applicants];
        $texts = [];

        foreach (array_count_values(array_column($accounts, 'business_name')) as $name => $count) {
            if ($count > 1) {
                $errors[] = "empresa repetida: {$name}";
            }
        }

        foreach ($accounts as $key => $account) {
            if (! preg_match('/^(admin|membro(\d{2})?|pendente\d{2}|aprovado(\d{2})?|inativo(\d{2})?|cancelado|bloqueado)$/', $key)) {
                $errors[] = "chave de conta inválida: {$key}";
            }

            if (! str_ends_with($this->emailFor($key), '@aspec.test')) {
                $errors[] = "email fora de @aspec.test: {$key}";
            }

            if (! isset(self::PORTFOLIO_SETS[$account['sector']])) {
                $errors[] = "setor sem fotografias: {$account['sector']}";
            }

            if (! isset(self::AREA_CODES[$account['district']])) {
                $errors[] = "distrito sem indicativo: {$account['district']}";
            }

            array_push($texts, $account['name'], $account['business_name'], $account['description'], $account['address']);
        }

        $nifs = array_map(fn (string $key) => $this->nifFor($key), array_keys($accounts));

        if (count($nifs) !== count(array_unique($nifs))) {
            $errors[] = 'NIFs repetidos';
        }

        $eventKeys = [];

        foreach ($events['events'] as $event) {
            $label = "{$event['name']} ({$event['district']})";
            $eventKeys[] = "{$event['name']}|{$event['district']}";
            $include = $event['include'] ?? [];

            if (isset($event['place']) === isset($event['online_url'])) {
                $errors[] = "evento sem local nem link (ou com os dois): {$label}";
            }

            if (isset($event['place']) && ! isset($events['places'][$event['place']])) {
                $errors[] = "local desconhecido em {$label}";
            }

            if ($event['registrations'] > $event['max_attendees'] || count($include) > $event['registrations']) {
                $errors[] = "inscrições acima da lotação em {$label}";
            }

            if (array_diff([...$include, ...($event['exclude'] ?? [])], array_keys($members)) !== []) {
                $errors[] = "conta desconhecida nas inscrições de {$label}";
            }

            if (isset($event['prayer_cards']) && ($event['prayer_cards']['authors'] > $event['registrations'] || ! isset($event['prayer_verse'])
                || array_diff($event['prayer_cards']['include'] ?? [], $include) !== [])) {
                $errors[] = "cartões de oração inválidos em {$label}";
            }

            array_push($texts, $event['name'], $event['description'], $event['venue'] ?? null, $event['notes'] ?? null);
        }

        if (count($eventKeys) !== count(array_unique($eventKeys))) {
            $errors[] = 'eventos repetidos (nome e distrito)';
        }

        foreach ($events['series'] as $series) {
            if (isset($series['place']) && ! isset($events['places'][$series['place']])) {
                $errors[] = "local desconhecido na série {$series['name']}";
            }

            if (isset($series['registrants']) && array_diff(array_keys($series['registrants']), array_keys($members)) !== []) {
                $errors[] = "conta desconhecida na série {$series['name']}";
            }

            array_push($texts, $series['name'], $series['description'], $series['notes'] ?? null);
        }

        $pairs = [];

        foreach ([...$reviews['visible'], ...$reviews['moderated']] as $review) {
            [$by, $to, $comment] = isset($review['by']) ? [$review['by'], $review['to'], $review['comment']] : [$review[0], $review[1], $review[4]];
            $pairs[] = "{$by}|{$to}";
            $texts[] = $comment;

            if ($by === $to || ! isset($members[$by], $members[$to])) {
                $errors[] = "avaliação inválida: {$by} → {$to}";
            }
        }

        if (count($pairs) !== count(array_unique($pairs))) {
            $errors[] = 'avaliações repetidas entre o mesmo par de perfis';
        }

        array_push($texts, ...array_merge(...$this->prayerRequests), ...array_column($events['places'], 'address'));

        foreach (array_filter($texts) as $text) {
            foreach (self::FORBIDDEN_BRANDS as $brand) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($brand, '/').'(?![\p{L}\p{N}])/iu', $text)) {
                    $errors[] = "marca \"{$brand}\" em: ".Str::limit($text, 60);
                }
            }
        }

        if ($errors !== []) {
            throw new RuntimeException("Dados de demonstração inválidos:\n- ".implode("\n- ", array_unique($errors)));
        }
    }

    /**
     * Email da conta a partir da chave dos dados: admin → demo.admin@aspec.test, membro12 →
     * demo.membro12@aspec.test (e os logins antigos que não seguem a regra).
     */
    private function emailFor(string $key): string
    {
        return self::LEGACY_EMAILS[$key] ?? "demo.{$key}@aspec.test";
    }

    /**
     * Número único da conta, de onde saem o telefone e o NIF gerados: admin 1, membroNN 100+NN,
     * pendenteNN 200+NN, aprovado 301, inativo 401, cancelado 501, bloqueado 601.
     */
    private function accountNumber(string $key): int
    {
        preg_match('/^([a-z]+?)(\d*)$/', $key, $match);

        $base = ['admin' => 0, 'membro' => 100, 'pendente' => 200, 'aprovado' => 300, 'inativo' => 400, 'cancelado' => 500, 'bloqueado' => 600][$match[1]];

        return $base + ((int) $match[2] ?: ($key === 'membro' ? 99 : 1));
    }

    /**
     * Telemóvel pessoal fictício em E.164 (+3519X000NNNN), ou o número estrangeiro das contas que o têm.
     */
    private function phoneFor(string $key): string
    {
        $number = $this->accountNumber($key);

        return self::FOREIGN_PHONES[$key] ?? '+351'.['91', '92', '93', '96'][$number % 4].'000'.sprintf('%04d', $number);
    }

    /**
     * Telefone comercial fictício da rede fixa do distrito ({indicativo}000NNN), em E.164.
     */
    private function commercialPhoneFor(string $key, string $district): string
    {
        $areaCode = self::AREA_CODES[$district];
        $number = $this->accountNumber($key);
        $suffix = strlen($areaCode) === 2 ? sprintf('%04d', $number) : sprintf('%03d', $number % 1000);

        return "+351{$areaCode}000{$suffix}";
    }

    /**
     * NIF de demonstração: o da versão anterior ou 5091NNNN com o dígito de controlo calculado
     * (pesos 9 a 2, módulo 11, como a regra PortugueseNif).
     */
    private function nifFor(string $key): string
    {
        if (isset(self::FIXED_NIFS[$key])) {
            return self::FIXED_NIFS[$key];
        }

        $digits = '5091'.sprintf('%04d', $this->accountNumber($key));
        $sum = 0;

        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $digits[$i] * (9 - $i);
        }

        $remainder = $sum % 11;

        return $digits.($remainder < 2 ? 0 : 11 - $remainder);
    }

    /**
     * Plano da conta: email, papel, estado, data de registo e, em quem já ativou, a subscrição.
     *
     * @param  array<string, mixed>  $account  Uma entrada de members.php ou applicants.php.
     * @return array<string, mixed>
     *
     * @throws RuntimeException Se o estado não existir.
     */
    private function accountPlan(string $key, array $account): array
    {
        [$status, $reason] = match ($account['state']) {
            'active', 'active_cancel', 'trial', 'trial_cancel', 'grace' => ['Active', null],
            'pending' => ['Pending', null],
            'approved' => ['Approved', null],
            'unpaid' => ['Inactive', InactiveReason::Unpaid],
            'cancelled' => ['Inactive', InactiveReason::Cancelled],
            'blocked' => ['Inactive', InactiveReason::Blocked],
            default => throw new RuntimeException("Estado de demonstração desconhecido: {$account['state']} ({$key})."),
        };

        $dates = $this->accountDates($key, $account['state'], (int) $account['joined_months_ago']);

        return [
            'key' => $key,
            'email' => $this->emailFor($key),
            'role' => $key === 'admin' ? 'Admin' : 'Member',
            'status' => $status,
            'reason' => $reason,
            'created_at' => $dates['created_at'],
            'subscription' => $dates['subscription'],
            'nif' => $dates['subscription'] === null ? null : $this->nifFor($key),
        ];
    }

    /**
     * Data de registo e subscrição de cada estado, relativas a agora e à configuração (trial,
     * carência e periodicidade da quota). A ativação é 1 a 5 dias depois do registo e o trial
     * começa na ativação; o primeiro período pago começa quando o trial acaba.
     * - trial: termina entre 8 e 27 dias depois de agora (sem webhooks, um trial no passado ficava
     *   "trialing"; voltar a correr o seeder antes de cada demonstração);
     * - grace: a renovação falhou há 1 a 3 dias, depois de um período pago;
     * - unpaid: nunca pagou ou pagou um período e a renovação falhou (terminou depois da carência);
     * - cancelled: o membro cancelou e a subscrição terminou no fim do período pago;
     * - blocked: bloqueado ainda em trial, 10 dias depois da ativação.
     *
     * @return array{created_at: Carbon, subscription: ?array<string, mixed>}
     */
    private function accountDates(string $key, string $state, int $monthsAgo): array
    {
        $trialDays = (int) config('subscription.trial_days');
        $graceDays = (int) config('subscription.grace_days');
        $interval = SubscriptionInterval::configured();
        $hash = crc32($key);
        $activationDelay = 1 + $hash % 5;

        if ($state === 'pending') {
            return ['created_at' => $this->now->copy()->subDays($hash % 9)->subHours(1 + $hash % 10), 'subscription' => null];
        }

        if ($state === 'approved') {
            return ['created_at' => $this->now->copy()->subDays(3 + $hash % 8), 'subscription' => null];
        }

        if ($state === 'trial' || $state === 'trial_cancel') {
            $trialEndsAt = $this->now->copy()->addDays(8 + $hash % 20);
            $activatedAt = $trialEndsAt->copy()->subDays($trialDays);

            return [
                'created_at' => $activatedAt->copy()->subDays($activationDelay),
                'subscription' => $this->subscriptionDates('trialing', $activatedAt, $trialEndsAt, $state === 'trial_cancel' ? $trialEndsAt->copy() : null),
            ];
        }

        if ($state === 'grace') {
            $trialEndsAt = $this->previousPeriodStart($this->now->copy()->subDays(1 + $hash % 3));
            $renewalAt = Carbon::instance($interval->addTo($trialEndsAt));
            $activatedAt = $trialEndsAt->copy()->subDays($trialDays);

            return [
                'created_at' => $activatedAt->copy()->subDays($activationDelay),
                'subscription' => $this->subscriptionDates('past_due', $activatedAt, $trialEndsAt, null, [[$trialEndsAt, $renewalAt]], $renewalAt->copy()->addDays($graceDays)),
            ];
        }

        $createdAt = $this->registeredMonthsAgo($monthsAgo, $hash);
        $latest = $this->now->copy()->subDays($trialDays + 14);

        if ($createdAt->greaterThan($latest)) {
            $createdAt = $latest;
        }

        $activatedAt = $createdAt->copy()->addDays($activationDelay);
        $trialEndsAt = $activatedAt->copy()->addDays($trialDays);
        $firstPeriod = [$trialEndsAt->copy(), Carbon::instance($interval->addTo($trialEndsAt))];

        $subscription = match ($state) {
            'active', 'active_cancel' => $this->activeSubscription($activatedAt, $trialEndsAt, $state === 'active_cancel'),
            'cancelled' => $this->subscriptionDates('canceled', $activatedAt, $trialEndsAt, $firstPeriod[1]->copy(), [$firstPeriod]),
            'unpaid' => $firstPeriod[1]->copy()->addDays($graceDays)->lessThanOrEqualTo($this->now->copy()->subDay())
                ? $this->subscriptionDates('canceled', $activatedAt, $trialEndsAt, $firstPeriod[1]->copy()->addDays($graceDays), [$firstPeriod])
                : $this->subscriptionDates('canceled', $activatedAt, $trialEndsAt, $trialEndsAt->copy()->addDays($graceDays)),
            'blocked' => $this->subscriptionDates('canceled', $activatedAt, $trialEndsAt, $activatedAt->copy()->addDays(10)),
        };

        return ['created_at' => $createdAt, 'subscription' => $subscription];
    }

    /**
     * Subscrição ativa com todos os períodos pagos desde o fim do trial, seguidos e o último a
     * cobrir hoje (um por fatura); com cancelamento agendado termina no fim do período atual.
     *
     * @return array<string, mixed>
     */
    private function activeSubscription(Carbon $activatedAt, Carbon $trialEndsAt, bool $cancelScheduled): array
    {
        $interval = SubscriptionInterval::configured();
        $periods = [];
        $start = $trialEndsAt->copy();

        while ($start->lessThanOrEqualTo($this->now)) {
            $end = Carbon::instance($interval->addTo($start));
            $periods[] = [$start, $end];
            $start = $end->copy();
        }

        return $this->subscriptionDates('active', $activatedAt, $trialEndsAt, $cancelScheduled ? end($periods)[1]->copy() : null, $periods);
    }

    /**
     * @param  list<array{Carbon, Carbon}>  $periods  Períodos pagos (início, fim), um por fatura.
     * @return array{status: string, created_at: Carbon, trial_ends_at: Carbon, ends_at: ?Carbon, grace_ends_at: ?Carbon, periods: list<array{Carbon, Carbon}>}
     */
    private function subscriptionDates(string $status, Carbon $createdAt, Carbon $trialEndsAt, ?Carbon $endsAt, array $periods = [], ?Carbon $graceEndsAt = null): array
    {
        return [
            'status' => $status,
            'created_at' => $createdAt,
            'trial_ends_at' => $trialEndsAt,
            'ends_at' => $endsAt,
            'grace_ends_at' => $graceEndsAt,
            'periods' => $periods,
        ];
    }

    /**
     * Data de registo "há N meses", espalhada uns dias e horas pela chave e nunca antes de há 18 meses.
     */
    private function registeredMonthsAgo(int $months, int $hash): Carbon
    {
        $date = $this->now->copy()->subMonthsNoOverflow($months)->addDays($hash % 10)->subHours(($hash >> 4) % 9);
        $oldest = $this->now->copy()->subMonthsNoOverflow(18)->addHour();

        return $date->lessThan($oldest) ? $oldest : $date;
    }

    /**
     * Recua um período da quota configurada, sem transbordar (31/03 − 1 mês = 28/02).
     */
    private function previousPeriodStart(Carbon $date): Carbon
    {
        return match (SubscriptionInterval::configured()) {
            SubscriptionInterval::Month => $date->copy()->subMonthNoOverflow(),
            SubscriptionInterval::Year => $date->copy()->subYearNoOverflow(),
        };
    }

    /**
     * Cria ou repõe a conta (papel, estado, motivo, telefone, password e data de registo) e o
     * respetivo perfil. Limpa a marca da agenda semanal, para a demonstração a poder enviar outra
     * vez na mesma semana, e as datas de trial e carência e a sessão de Checkout (o seedPayments()
     * repõe as do cenário). Nunca toca no stripe_id (um cliente real de um ensaio fica) nem na
     * isenção de subscrição. Numa conta com uma subscrição real em curso (ex.: reativada pelo
     * Checkout num ensaio) também não toca no estado, no motivo, no trial, na carência nem na
     * sessão de Checkout: são do Stripe.
     * Desliga os emails de eventos (anúncios e agenda semanal), como o LoadSeeder: os emails demo são
     * .test e um evento criado no deploy mandaria dezenas de emails que voltam para trás, gastando a
     * quota diária do serviço de email.
     *
     * @param  array<string, mixed>  $plan  Resultado de accountPlan().
     * @param  array<string, mixed>  $account  Uma entrada de members.php ou applicants.php.
     */
    private function seedAccount(array $plan, array $account): MemberProfile
    {
        $user = User::firstOrNew(['email' => $plan['email']]);

        $paymentState = [
            'account_status_id' => $this->lookupId(AccountStatus::class, $plan['status']),
            'inactive_reason' => $plan['reason'],
            'trial_ends_at' => null,
            'grace_ends_at' => null,
            'stripe_checkout_session_id' => null,
        ];

        // Papel, estado e motivo não são fillable (só o admin e os pagamentos os mudam), daí o forceFill.
        $user->forceFill([
            ...($this->hasRealRunningSubscription($user) ? [] : $paymentState),
            'password' => $this->passwordHash,
            'phone' => $this->phoneFor($plan['key']),
            'role_id' => $this->lookupId(Role::class, $plan['role']),
            'email_verified_at' => $plan['created_at'],
            'weekly_agenda_sent_at' => null,
            'notify_new_events' => false,
            'notify_weekly_agenda' => false,
            'created_at' => $plan['created_at'],
        ])->save();

        return MemberProfile::updateOrCreate(['user_id' => $user->id], [
            'sector_id' => $this->lookupId(Sector::class, $account['sector']),
            'location_id' => $this->lookupId(Location::class, $account['district']),
            'name' => $account['name'],
            'business_name' => $account['business_name'],
            'congregation' => $account['congregation'],
            'role_in_congregation' => $account['role_in_congregation'],
            'description' => $account['description'],
            'website_url' => $account['website'] ?? null,
            'commercial_email' => $account['commercial_email'],
            'commercial_phone' => $this->commercialPhoneFor($plan['key'], $account['district']),
            'address' => $account['address'],
            'postal_code' => $account['postal_code'],
            'city' => $account['city'],
        ]);
    }

    /**
     * Horários e redes sociais de todos os perfis de demonstração: apaga os atuais e insere os dos
     * dados em lote (duas consultas por tabela em vez de duas por perfil, o que conta no TiDB).
     *
     * @param  array<string, array<string, mixed>>  $accounts  Contas por chave.
     */
    private function syncHoursAndSocialLinks(array $accounts): void
    {
        $profileIds = array_values($this->profileIds);
        $timestamp = $this->now->format('Y-m-d H:i:s');
        $hours = [];
        $links = [];

        foreach ($accounts as $key => $account) {
            foreach ($account['hours'] ?? [] as $weekDayId => [$open, $close]) {
                $hours[] = [
                    'id' => (new BusinessHour)->newUniqueId(),
                    'profile_id' => $this->profileIds[$key],
                    'week_day_id' => $weekDayId,
                    'open_time' => $open,
                    'close_time' => $close,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            foreach ($account['social'] ?? [] as $platform => $url) {
                $links[] = [
                    'id' => (new SocialLink)->newUniqueId(),
                    'profile_id' => $this->profileIds[$key],
                    'platform_id' => $this->lookupId(SocialPlatform::class, $platform),
                    'url' => $url,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        DB::table('business_hours')->whereIn('profile_id', $profileIds)->delete();
        DB::table('social_links')->whereIn('profile_id', $profileIds)->delete();

        foreach (array_chunk($hours, self::BATCH) as $chunk) {
            DB::table('business_hours')->insert($chunk);
        }

        foreach (array_chunk($links, self::BATCH) as $chunk) {
            DB::table('social_links')->insert($chunk);
        }
    }

    /**
     * Logótipos e portefólio de todos os perfis no disco de media. Sem GD os perfis ficam sem
     * logótipo; sem FreeType os logótipos ficam só com as iniciais.
     *
     * @param  array<string, array<string, mixed>>  $members
     * @param  array<string, array<string, mixed>>  $applicants
     */
    private function seedMedia(array $members, array $applicants): void
    {
        $canDrawImages = extension_loaded('gd');

        if (! $canDrawImages) {
            $this->command?->warn('A extensão GD do PHP não está ativa: os perfis ficam sem logótipo.');
        } elseif (! $this->logos->supportsTrueType()) {
            $this->command?->warn('O GD do PHP não tem FreeType (ou falta a fonte em database/seeders/demo/fonts): os logótipos ficam só com as iniciais.');
        }

        $portfolios = $this->planPortfolios($members);
        $profiles = MemberProfile::whereIn('id', $this->profileIds)->get()->keyBy('id');

        foreach ([...$members, ...$applicants] as $key => $account) {
            $profile = $profiles[$this->profileIds[$key]];

            $this->seedLogo($profile, $key, $canDrawImages && ($account['logo'] ?? false));
            $this->seedPortfolio($profile, $portfolios[$key] ?? []);
        }
    }

    /**
     * Logótipo 400×400 em logos/{profile_id}/demo-logo-v2.png no disco de media (a pasta do upload
     * do membro). O nome versionado evita que o R2 sirva em cache o logótipo da versão anterior, que
     * é apagado (demo-logo.png, também na pasta antiga logos/{user_id}). Um perfil sem logótipo nos
     * dados perde o de demonstração, mas nunca um logótipo carregado pelo próprio membro. Um perfil
     * com logótipo nos dados cujo logótipo atual é um upload (logos/{profile_id}/…) perde esse
     * ficheiro, como na troca de logótipo pelo membro, para não ficar órfão no disco.
     */
    private function seedLogo(MemberProfile $profile, string $key, bool $withLogo): void
    {
        $disk = MediaStorage::disk();
        $path = "logos/{$profile->id}/demo-logo-v2.png";
        $demoPaths = [$path, "logos/{$profile->id}/demo-logo.png", "logos/{$profile->user_id}/demo-logo.png"];

        $disk->delete(array_slice($demoPaths, 1));

        if ($withLogo) {
            if ($profile->logo_path !== null && ! in_array($profile->logo_path, $demoPaths, true) && str_starts_with($profile->logo_path, "logos/{$profile->id}/") && ! str_contains($profile->logo_path, '..')) {
                $disk->delete($profile->logo_path);
            }

            if (! $disk->exists($path)) {
                $disk->put($path, $this->logos->generate($profile->business_name, crc32("{$key}|logótipo") % 8));
            }
        } else {
            $disk->delete($path);
        }

        $logoPath = $withLogo ? $path : (in_array($profile->logo_path, $demoPaths, true) ? null : $profile->logo_path);

        if ($profile->logo_path !== $logoPath) {
            $profile->update(['logo_path' => $logoPath]);
        }
    }

    /**
     * Escolhe as fotografias do portefólio: cerca de 35 perfis (o demo.membro primeiro, os outros
     * por uma ordem fixa derivada da chave), 3 a 5 fotografias do conjunto do setor, sem repetir
     * fotografias no mesmo distrito nem entre perfis que possam aparecer na 1.ª página do diretório.
     * Um perfil sem 3 fotografias livres fica sem portefólio.
     *
     * @param  array<string, array<string, mixed>>  $members
     * @return array<string, list<string>> Ficheiros de origem por chave da conta.
     */
    private function planPortfolios(array $members): array
    {
        $sets = [];

        foreach (glob(database_path('seeders/demo/portfolio/*.jpg')) as $file) {
            $sets[preg_replace('/-\d+\.jpg$/', '', basename($file))][] = basename($file);
        }

        $firstPage = $this->firstDirectoryPageCandidates($members);
        $order = array_keys($members);
        usort($order, fn (string $a, string $b) => crc32("{$a}|portefólio") <=> crc32("{$b}|portefólio"));
        $order = ['membro', ...array_values(array_diff($order, ['membro']))];

        $usedInDistrict = [];
        $usedOnFirstPage = [];
        $usage = [];
        $plan = [];

        foreach ($order as $key) {
            if (count($plan) === self::PORTFOLIO_PROFILES) {
                break;
            }

            $district = $members[$key]['district'];
            $available = array_values(array_filter(
                $sets[self::PORTFOLIO_SETS[$members[$key]['sector']]] ?? [],
                fn (string $file) => ! isset($usedInDistrict[$district][$file]) && ! (isset($firstPage[$key]) && isset($usedOnFirstPage[$file]))
            ));

            if (count($available) < 3) {
                continue;
            }

            usort($available, fn (string $a, string $b) => [$usage[$a] ?? 0, crc32($key.$a)] <=> [$usage[$b] ?? 0, crc32($key.$b)]);
            $photos = array_slice($available, 0, 3 + crc32($key) % 3);
            sort($photos, SORT_NATURAL);

            foreach ($photos as $file) {
                $usedInDistrict[$district][$file] = true;
                $usage[$file] = ($usage[$file] ?? 0) + 1;

                if (isset($firstPage[$key])) {
                    $usedOnFirstPage[$file] = true;
                }
            }

            $plan[$key] = $photos;
        }

        return $plan;
    }

    /**
     * Chaves dos perfis que podem calhar na 1.ª página do diretório (ordenado por empresa e nome):
     * as primeiras posições com a comparação byte a byte do SQLite e com uma aproximação da
     * ordenação do MySQL (sem distinguir maiúsculas nem acentos).
     *
     * @param  array<string, array<string, mixed>>  $members
     * @return array<string, true>
     */
    private function firstDirectoryPageCandidates(array $members): array
    {
        $binary = fn (array $a, array $b) => [$a['business_name'], $a['name']] <=> [$b['business_name'], $b['name']];
        $folded = fn (array $a, array $b) => [Str::lower(Str::ascii($a['business_name'])), Str::lower(Str::ascii($a['name']))]
            <=> [Str::lower(Str::ascii($b['business_name'])), Str::lower(Str::ascii($b['name']))];

        $candidates = [];

        foreach ([$binary, $folded] as $compare) {
            $sorted = $members;
            uasort($sorted, $compare);

            foreach (array_slice(array_keys($sorted), 0, self::FIRST_PAGE_MARGIN) as $key) {
                $candidates[$key] = true;
            }
        }

        return $candidates;
    }

    /**
     * Fotografias do portefólio, copiadas de database/seeders/demo/portfolio para
     * portfolios/{profile_id}/demo-{origem} no disco de media, como no upload do membro. Copia só
     * o que falta ou mudou (tamanho diferente: a versão anterior tinha fotografias com os mesmos
     * nomes). As imagens de demonstração (demo-*) que já não fazem parte do conjunto são apagadas,
     * com o ficheiro, também na pasta antiga portfolios/{user_id}. Origem e licença das
     * fotografias em THIRD_PARTY_NOTICES.md.
     *
     * @param  list<string>  $sources  Ficheiros de origem (vazio = perfil sem portefólio).
     */
    private function seedPortfolio(MemberProfile $profile, array $sources): void
    {
        $disk = MediaStorage::disk();
        $existing = $profile->portfolios()->pluck('image_path')->all();
        $paths = [];
        $rows = [];

        foreach ($sources as $position => $source) {
            $path = "portfolios/{$profile->id}/demo-{$source}";
            $file = database_path("seeders/demo/portfolio/{$source}");
            $paths[] = $path;

            if ($this->storedSize($path) !== filesize($file)) {
                $disk->put($path, file_get_contents($file));
            }

            if (! in_array($path, $existing, true)) {
                $timestamp = $this->now->copy()->addSeconds($position)->format('Y-m-d H:i:s');
                $rows[] = ['id' => (new Portfolio)->newUniqueId(), 'profile_id' => $profile->id, 'image_path' => $path, 'created_at' => $timestamp, 'updated_at' => $timestamp];
            }
        }

        if ($rows !== []) {
            Portfolio::insert($rows);
        }

        $stale = $profile->portfolios()
            ->where(fn ($query) => $query
                ->where('image_path', 'like', "portfolios/{$profile->id}/demo-%")
                ->orWhere('image_path', 'like', "portfolios/{$profile->user_id}/demo-%"))
            ->whereNotIn('image_path', $paths)
            ->get();

        foreach ($stale as $image) {
            $disk->delete($image->image_path);
            $image->delete();
        }
    }

    /**
     * Tamanho do ficheiro no disco de media, ou null se não existir (uma só chamada ao R2).
     */
    private function storedSize(string $path): ?int
    {
        try {
            return MediaStorage::disk()->size($path);
        } catch (FilesystemException) {
            return null;
        }
    }

    /**
     * Contas Active (na base de dados, depois de semeadas) que podem inscrever-se em eventos e
     * escrever cartões, ordenadas pela chave para a escolha dos inscritos ser sempre igual, e os ids
     * de todas as contas demo.
     */
    private function loadActivePool(): void
    {
        $keys = array_flip(array_map(fn (array $plan) => $plan['email'], $this->plans));

        $this->demoUserIds = User::whereIn('email', array_keys($keys))->pluck('id')->all();

        $this->activePool = User::whereIn('email', array_keys($keys))
            ->whereHas('accountStatus', fn ($query) => $query->where('name', 'Active'))
            ->get(['id', 'email', 'created_at'])
            ->map(fn (User $user) => ['key' => $keys[$user->email], 'user_id' => $user->id, 'created_at' => $user->created_at])
            ->sortBy('key')
            ->values()
            ->all();
    }

    /**
     * Eventos únicos, séries, inscrições e cartões de oração. Apaga primeiro os eventos da versão
     * anterior que já não existem (lista fixa em events.php), mas só se ainda tiverem a descrição
     * dessa versão: nunca apaga eventos fora dessa lista nem com outra descrição, como os criados
     * ou editados pelo professor no deploy.
     */
    private function seedEvents(array $data): void
    {
        foreach ($data['retired'] as [$name, $district]) {
            $retired = EventInstance::whereNull('series_id')
                ->where('name', $name)
                ->where('location_id', $this->lookupId(Location::class, $district))
                ->get();

            foreach ($retired as $event) {
                if ($event->description !== (self::PREVIOUS_DESCRIPTIONS[$name] ?? null)) {
                    $this->command?->warn("O evento \"{$name}\" ({$district}) não foi apagado: a descrição não é a da versão anterior da demonstração.");

                    continue;
                }

                $this->deleteDemoEvent($event);
            }
        }

        foreach ($data['events'] as $event) {
            $this->seedEvent($event, $data['places']);
        }

        foreach ($data['series'] as $series) {
            $this->seedSeries($series, $data['places']);
        }
    }

    /**
     * Apaga um evento de demonstração (inscrições e cartões primeiro), a não ser que tenha
     * inscrições ou cartões de contas que não são demo: nesse caso fica e o seeder avisa.
     */
    private function deleteDemoEvent(EventInstance $event): void
    {
        if ($this->hasOtherPeoplesData($event)) {
            $this->command?->warn("O evento \"{$event->name}\" ({$event->event_date->toDateString()}) não foi apagado: tem inscrições ou cartões de contas que não são de demonstração.");

            return;
        }

        $event->registrations()->delete();
        PrayerCard::withTrashed()->where('event_instance_id', $event->id)->forceDelete();
        $event->delete();
    }

    /**
     * Indica se o evento tem inscrições ou cartões de oração (escritos ou recebidos) de contas que
     * não são de demonstração.
     */
    private function hasOtherPeoplesData(EventInstance $event): bool
    {
        return $event->registrations()->whereNotIn('user_id', $this->demoUserIds)->exists()
            || PrayerCard::withTrashed()
                ->where('event_instance_id', $event->id)
                ->where(fn ($query) => $query
                    ->whereNotIn('author_user_id', $this->demoUserIds)
                    ->orWhere(fn ($received) => $received->whereNotNull('recipient_user_id')->whereNotIn('recipient_user_id', $this->demoUserIds)))
                ->exists();
    }

    /**
     * Evento único com datas relativas ao dia de hoje em Lisboa. Procura pelo nome e distrito (e não
     * pela data): correr noutro dia atualiza as datas em vez de duplicar o evento. O prazo de
     * inscrição fica às 23:59 (hora de Lisboa) do dia indicado, por omissão a véspera, como o
     * backoffice o guarda. As coordenadas ficam sempre a null (o mapa usa só o place_id).
     * Um evento sem inscrições fica por anunciar (announced_at null).
     *
     * @param  array<string, mixed>  $event  Uma entrada de events.php['events'].
     * @param  array<string, array{address: string, place_id: string}>  $places
     */
    private function seedEvent(array $event, array $places): void
    {
        $today = now($this->eventTimezone())->startOfDay();
        [$start, $end] = $event['start'] === 'now' ? $this->todayEventTimes() : [$event['start'], $event['end']];
        $date = $today->copy()->addDays($event['days']);
        $deadline = $today->copy()->addDays($event['deadline_days'] ?? $event['days'] - 1)->setTime(23, 59);
        $place = isset($event['place']) ? $places[$event['place']] : null;
        $announcedAt = $event['registrations'] > 0 ? $this->announcementDate($date) : null;

        $instance = EventInstance::whereNull('series_id')->firstOrNew([
            'name' => $event['name'],
            'location_id' => $this->lookupId(Location::class, $event['district']),
        ]);

        $instance->fill([
            'category_id' => $this->lookupId(EventCategory::class, $event['category']),
            'event_date' => $date->format('Y-m-d'),
            'event_start_time' => $start.':00',
            'event_end_time' => $end.':00',
            'description' => $event['description'],
            'is_online' => isset($event['online_url']),
            'online_url' => $event['online_url'] ?? null,
            'address' => $place['address'] ?? null,
            'venue_name' => $event['venue'] ?? null,
            'place_id' => $place['place_id'] ?? null,
            'latitude' => null,
            'longitude' => null,
            'max_attendees' => $event['max_attendees'],
            'registration_deadline' => $deadline->format('Y-m-d H:i:s'),
            'organizer_notes' => $event['notes'] ?? null,
            'prayer_cards_enabled' => isset($event['prayer_verse']),
            'prayer_verse' => $event['prayer_verse'] ?? null,
        ]);
        $instance->forceFill(['announced_at' => $announcedAt])->save();

        $seed = "{$event['name']}|{$event['district']}";
        $registrants = $this->pickRegistrants($instance, $seed, $event['registrations'], $event['include'] ?? [], $event['exclude'] ?? [], $announcedAt ?? $this->announcementDate($date), $deadline);
        $this->replaceRegistrations($instance, $registrants);

        $cards = $event['prayer_cards'] ?? null;
        $authors = $cards === null ? [] : $this->pickCardAuthors($seed, $registrants, $cards);
        $this->syncPrayerCards($instance, $authors, $cards['distributed'] ?? false, crc32($seed));
    }

    /**
     * Data de publicação de um evento: 35 dias antes, nunca depois de ontem (um evento já
     * inscrito foi publicado antes de hoje). Devolve o instante no fuso da aplicação.
     */
    private function announcementDate(Carbon $eventDate): Carbon
    {
        $announced = $eventDate->copy()->subDays(35)->setTimezone(config('app.timezone'));
        $latest = $this->now->copy()->subDay();

        return $announced->lessThan($latest) ? $announced : $latest;
    }

    /**
     * Série semanal, com as datas geradas pelo mesmo serviço do backoffice (EventSeriesSchedule) e
     * os mesmos campos que o backoffice grava em cada sessão: prazo de inscrição N dias antes da
     * sessão às 23:59 (Lisboa), sem cartões de oração e sem anúncio (o anúncio só sai na criação de
     * eventos únicos no backoffice).
     *
     * Não pode procurar pelo nome e distrito como os eventos únicos (as sessões partilham os dois):
     * encontra a série pela sessão com esse nome e distrito e atualiza as sessões por ordem de data,
     * para os ids (e os links do frontend) se manterem entre execuções. Volta a criar as sessões que
     * faltem (ex.: apagada à mão "só nesta sessão") e apaga as que sobrem.
     *
     * @param  array<string, mixed>  $data  Uma entrada de events.php['series'].
     * @param  array<string, array{address: string, place_id: string}>  $places
     */
    private function seedSeries(array $data, array $places): void
    {
        $timezone = $this->eventTimezone();
        $locationId = $this->lookupId(Location::class, $data['district']);
        $place = isset($data['place']) ? $places[$data['place']] : null;

        $firstDate = now($timezone)->startOfDay()->addDays($data['days']);
        $endsOn = $firstDate->copy()->addWeeks($data['sessions'] - 1);
        $dates = app(EventSeriesSchedule::class)->generate($firstDate->toDateString(), $endsOn->toDateString(), 'weekly');

        $seriesId = EventInstance::where('name', $data['name'])
            ->where('location_id', $locationId)
            ->whereNotNull('series_id')
            ->value('series_id');

        $series = EventSeries::findOrNew($seriesId);
        $series->fill([
            'frequency' => 'weekly',
            'ends_on' => $endsOn->toDateString(),
            'registration_deadline_days_before' => $data['deadline_days_before'],
        ])->save();

        $sessions = $series->eventInstances()->orderBy('event_date')->get();

        foreach ($sessions->slice(count($dates)) as $extra) {
            $this->deleteDemoEvent($extra);
        }

        foreach ($dates as $index => $date) {
            $session = $sessions->get($index) ?? new EventInstance;
            $sessionDate = Carbon::createFromFormat('!Y-m-d', $date, $timezone);
            $deadline = $sessionDate->copy()->subDays($data['deadline_days_before'])->setTime(23, 59);

            $session->fill([
                'series_id' => $series->id,
                'category_id' => $this->lookupId(EventCategory::class, $data['category']),
                'location_id' => $locationId,
                'name' => $data['name'],
                'event_date' => $date,
                'event_start_time' => $data['start'].':00',
                'event_end_time' => $data['end'].':00',
                'description' => $data['description'],
                'is_online' => isset($data['online_url']),
                'online_url' => $data['online_url'] ?? null,
                'address' => $place['address'] ?? null,
                'venue_name' => null,
                'place_id' => $place['place_id'] ?? null,
                'latitude' => null,
                'longitude' => null,
                'max_attendees' => $data['max_attendees'],
                'registration_deadline' => $deadline->format('Y-m-d H:i:s'),
                'organizer_notes' => $data['notes'] ?? null,
                'prayer_cards_enabled' => false,
                'prayer_verse' => null,
            ]);
            $session->forceFill(['announced_at' => null])->save();

            $seed = "{$data['name']}|{$data['district']}|{$index}";
            $include = isset($data['registrants'])
                ? array_keys(array_filter($data['registrants'], fn (array $numbers) => in_array($index + 1, $numbers, true)))
                : [];
            $count = isset($data['registrants']) ? count($include) : $data['registrations'][$index];

            $this->replaceRegistrations($session, $this->pickRegistrants($session, $seed, $count, $include, [], $this->announcementDate($sessionDate), $deadline));
            $this->syncPrayerCards($session, [], false, 0);
        }
    }

    /**
     * Escolhe os inscritos de forma determinística (semente a partir do evento) entre as contas
     * Active que já existiam antes do fim das inscrições, com as contas forçadas (include) e sem as
     * proibidas (exclude). A data de cada inscrição fica entre a publicação (ou o registo da conta,
     * se for depois) e o fim das inscrições ou agora. As inscrições de contas que não são demo (ex.:
     * as contas preparadas para a defesa) ocupam lugares: se não couberem todos, ficam menos inscritos
     * demo e o seeder avisa, sem nunca passar da lotação.
     *
     * @param  list<string>  $include  Chaves de contas que têm de estar inscritas.
     * @param  list<string>  $exclude  Chaves de contas que não podem estar inscritas.
     * @param  Carbon  $deadline  Fim das inscrições (hora de Lisboa).
     * @return list<array{key: string, user_id: string, created_at: Carbon}>
     *
     * @throws RuntimeException Se uma conta forçada não puder inscrever-se ou não houver contas suficientes.
     */
    private function pickRegistrants(EventInstance $event, string $seed, int $count, array $include, array $exclude, Carbon $openedAt, Carbon $deadline): array
    {
        $closesAt = $deadline->copy()->setTimezone(config('app.timezone'));

        if ($closesAt->greaterThan($this->now)) {
            $closesAt = $this->now->copy();
        }

        $eligible = collect($this->activePool)
            ->filter(fn (array $account) => $account['created_at']->lessThan($closesAt->copy()->subHour()))
            ->keyBy('key');

        $randomizer = new Randomizer(new Mt19937(crc32($seed)));
        $others = $eligible->keys()->diff([...$include, ...$exclude])->values()->all();
        $chosen = [...$include, ...array_slice($randomizer->shuffleArray($others), 0, max(0, $count - count($include)))];

        if (array_diff($include, $eligible->keys()->all()) !== [] || count($chosen) !== $count || $count > $event->max_attendees) {
            throw new RuntimeException("Inscrições de demonstração inválidas no evento \"{$event->name}\" ({$event->event_date->toDateString()}).");
        }

        $freeSeats = $event->max_attendees - $event->registrations()->whereNotIn('user_id', $this->demoUserIds)->count();

        if (count($chosen) > $freeSeats) {
            $chosen = array_slice($chosen, 0, max(0, $freeSeats));
            $this->command?->warn("O evento \"{$event->name}\" ({$event->event_date->toDateString()}) tem inscrições de contas que não são demo: ficou com ".count($chosen)." inscritos demo em vez de {$count}.");
        }

        return array_map(function (string $key) use ($eligible, $openedAt, $closesAt, $randomizer) {
            $account = $eligible[$key];
            $from = $account['created_at']->greaterThan($openedAt) ? $account['created_at']->copy()->addMinutes(30) : $openedAt->copy();
            $seconds = max(0, (int) $from->diffInSeconds($closesAt));

            return [...$account, 'created_at' => $from->addSeconds($randomizer->getInt(0, $seconds))];
        }, $chosen);
    }

    /**
     * Repõe as inscrições demo do evento: apaga as das contas demo (incluindo inscrições e
     * cancelamentos feitos à mão numa demonstração) e insere as escolhidas em lote, com a data de
     * cada uma. As inscrições de outras contas nunca são apagadas.
     *
     * @param  list<array{key: string, user_id: string, created_at: Carbon}>  $registrants
     */
    private function replaceRegistrations(EventInstance $event, array $registrants): void
    {
        $event->registrations()->whereIn('user_id', $this->demoUserIds)->delete();

        $rows = array_map(fn (array $registrant) => [
            'id' => (new EventRegistration)->newUniqueId(),
            'event_instance_id' => $event->id,
            'user_id' => $registrant['user_id'],
            'created_at' => $registrant['created_at']->format('Y-m-d H:i:s'),
        ], $registrants);

        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            EventRegistration::insert($chunk);
        }
    }

    /**
     * Autores dos cartões de oração, escolhidos entre os inscritos: as contas forçadas primeiro e as
     * restantes de forma determinística, sem as proibidas. A ordem é a da cadeia. Os dados garantem
     * (validateData) que os autores forçados estão nas inscrições forçadas; só ficam menos autores se
     * o evento tiver menos inscritos demo por causa de inscrições de outras contas.
     *
     * @param  list<array{key: string, user_id: string, created_at: Carbon}>  $registrants
     * @param  array{authors: int, include?: list<string>, exclude?: list<string>, distributed?: bool}  $cards
     * @return list<string> Ids das contas.
     */
    private function pickCardAuthors(string $seed, array $registrants, array $cards): array
    {
        $userIds = array_column($registrants, 'user_id', 'key');
        $include = array_values(array_intersect($cards['include'] ?? [], array_keys($userIds)));
        $others = array_values(array_diff(array_keys($userIds), $include, $cards['exclude'] ?? []));
        $randomizer = new Randomizer(new Mt19937(crc32("{$seed}|cartões")));
        $authors = [...$include, ...array_slice($randomizer->shuffleArray($others), 0, max(0, $cards['authors'] - count($include)))];

        return array_map(fn (string $key) => $userIds[$key], $authors);
    }

    /**
     * Repõe os cartões de oração demo do evento: apaga os escritos por contas demo (incluindo os
     * enviados à mão numa demonstração) e cria um por autor, com pedidos de prayer_requests.php a partir de uma posição
     * diferente em cada evento. Num evento distribuído, a cadeia segue a ordem dos autores: o autor
     * na posição i recebe o cartão do autor na posição i+1 e o último recebe o do primeiro; a
     * distribuição fica às 00:05 do dia seguinte ao evento, como a do comando de hora a hora.
     * Não envia emails. Grava pelo modelo (e não em lote) porque os pedidos são encriptados.
     * Os cartões escritos por contas que não são demo nunca são apagados; enquanto existirem, a data
     * de distribuição do evento também não é limpa.
     *
     * @param  list<string>  $authorIds  Ids das contas autoras (todas inscritas no evento).
     */
    private function syncPrayerCards(EventInstance $event, array $authorIds, bool $distributed, int $requestStart): void
    {
        PrayerCard::withTrashed()->where('event_instance_id', $event->id)->whereIn('author_user_id', $this->demoUserIds)->forceDelete();

        if (PrayerCard::withTrashed()->where('event_instance_id', $event->id)->doesntExist()) {
            $event->forceFill(['prayer_cards_distributed_at' => null])->save();
        }

        if ($authorIds === []) {
            return;
        }

        $created = [];

        foreach ($authorIds as $position => $authorId) {
            [$personal, $work, $salvation] = $this->prayerRequests[($requestStart + $position) % count($this->prayerRequests)];
            $submittedAt = $distributed ? $event->startsAt()->addMinutes(15 * ($position + 1))->utc() : $this->now->copy();

            $card = new PrayerCard([
                'event_instance_id' => $event->id,
                'author_user_id' => $authorId,
                'personal_request' => $personal,
                'work_request' => $work,
                'salvation_request' => $salvation,
                'consent_given_at' => $submittedAt,
            ]);
            $card->forceFill(['created_at' => $submittedAt, 'updated_at' => $submittedAt])->save();
            $created[] = $card;
        }

        if (! $distributed) {
            return;
        }

        $distributedAt = $event->prayerCardsWindowClosesAt()->addMinutes(5)->startOfMinute()->utc();

        foreach ($created as $position => $receiverCard) {
            $created[($position + 1) % count($created)]
                ->forceFill(['recipient_user_id' => $receiverCard->author_user_id, 'received_at' => $distributedAt])
                ->save();
        }

        $event->forceFill(['prayer_cards_distributed_at' => $distributedAt])->save();
    }

    /**
     * Repõe as avaliações entre perfis de demonstração: apaga as que existirem entre eles (incluindo
     * as feitas ou moderadas à mão numa demonstração; as denúncias saem em cascata) e insere em lote
     * as de reviews.php, com as denúncias como a API as grava. Não toca em avaliações em que o autor
     * ou o avaliado não seja um perfil de demonstração.
     *
     * @param  array{visible: list<array>, moderated: list<array<string, mixed>>}  $data
     */
    private function seedReviews(array $data): void
    {
        $profileIds = array_values($this->profileIds);

        Review::whereIn('reviewer_profile_id', $profileIds)
            ->whereIn('reviewed_profile_id', $profileIds)
            ->delete();

        $reviews = [];
        $reports = [];

        foreach ($data['visible'] as [$by, $to, $rating, $daysAgo, $comment]) {
            $reviews[] = $this->reviewRow($by, $to, $rating, $daysAgo, 'Visible', $comment, null);
        }

        foreach ($data['moderated'] as $moderated) {
            $review = $this->reviewRow($moderated['by'], $moderated['to'], $moderated['rating'], $moderated['days_ago'], $moderated['status'], $moderated['comment'], $moderated['hidden_reason'] ?? null);
            $reviews[] = $review;

            foreach ($moderated['reports'] as $report) {
                $reports[] = $this->reviewReportRow($review, $moderated['to'], $report);
            }
        }

        foreach (array_chunk($reviews, self::BATCH) as $chunk) {
            Review::insert($chunk);
        }

        foreach (array_chunk($reports, self::BATCH) as $chunk) {
            ReviewReport::insert($chunk);
        }
    }

    /**
     * Linha de uma avaliação. A data é "há N dias", mas nunca antes do dia seguinte ao registo da
     * conta mais recente do par nem no futuro.
     *
     * @return array<string, mixed>
     */
    private function reviewRow(string $by, string $to, int $rating, int $daysAgo, string $status, string $comment, ?string $hiddenReason): array
    {
        $date = $this->now->copy()->subDays($daysAgo)->subHours(crc32("{$by}|{$to}") % 8);
        [$byCreatedAt, $toCreatedAt] = [$this->plans[$by]['created_at'], $this->plans[$to]['created_at']];
        $earliest = ($byCreatedAt->greaterThan($toCreatedAt) ? $byCreatedAt : $toCreatedAt)->copy()->addDay();

        if ($date->lessThan($earliest)) {
            $date = $earliest;
        }

        if ($date->greaterThan($this->now)) {
            $date = $this->now->copy();
        }

        return [
            'id' => (new Review)->newUniqueId(),
            'reviewer_profile_id' => $this->profileIds[$by],
            'reviewed_profile_id' => $this->profileIds[$to],
            'rating' => $rating,
            'comment' => $comment,
            'status_id' => $this->lookupId(ReviewStatus::class, $status),
            'hidden_reason' => $hiddenReason,
            'created_at' => $date->format('Y-m-d H:i:s'),
            'updated_at' => $date->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Linha de uma denúncia: a automática vem do filtro de linguagem (sem autor); a de um membro tem
     * o motivo, o comentário e o autor (por omissão a empresa avaliada). Uma denúncia resolvida tem
     * a data em que o admin a tratou (um dia depois, nunca no futuro).
     *
     * @param  array<string, mixed>  $review  Linha de reviewRow().
     * @param  array<string, mixed>  $report  Uma entrada de reports em reviews.php.
     * @return array<string, mixed>
     */
    private function reviewReportRow(array $review, string $reviewedKey, array $report): array
    {
        $reviewedAt = Carbon::parse($review['created_at']);
        $resolvedAt = $reviewedAt->copy()->addDay();
        $automatic = $report['source'] === ReviewReport::SOURCE_AUTOMATIC;

        return [
            'id' => (new ReviewReport)->newUniqueId(),
            'review_id' => $review['id'],
            'reporter_profile_id' => $automatic ? null : $this->profileIds[$report['by'] ?? $reviewedKey],
            'reason' => $automatic ? 'language_filter' : $report['reason'],
            'comment' => $automatic ? null : ($report['comment'] ?? null),
            'source' => $report['source'],
            'resolved_at' => ($report['resolved'] ?? false) ? ($resolvedAt->greaterThan($this->now) ? $this->now : $resolvedAt)->format('Y-m-d H:i:s') : null,
            'created_at' => $review['created_at'],
            'updated_at' => $review['created_at'],
        ];
    }

    /**
     * Dados de pagamento de demonstração, sem chamar o Stripe: por conta, apaga os da execução
     * anterior, preenche a faturação e cria a subscrição e as faturas do plano da conta.
     * As contas Pending e Approved ficam sem faturação nem subscrição (ainda não ativaram).
     * Uma conta demo com uma subscrição real em curso (ex.: reativada pelo Checkout num ensaio)
     * fica como está: duas subscrições em curso fariam o webhook seguinte tratar a real como duplicada.
     */
    private function seedPayments(): void
    {
        $plans = array_column($this->plans, null, 'email');

        foreach (User::with('memberProfile')->whereIn('email', array_keys($plans))->get() as $user) {
            DB::transaction(fn () => $this->seedAccountPayments($user, $plans[$user->email]));
        }
    }

    /**
     * Pagamentos de uma conta numa transação: se a emissão de uma fatura falhar, a conta não fica
     * sem subscrição nem com metade das faturas (os PDFs já apagados não voltam; correr outra vez
     * cria-os de novo).
     *
     * @param  array<string, mixed>  $plan  Resultado de accountPlan().
     */
    private function seedAccountPayments(User $user, array $plan): void
    {
        $this->resetDemoPayments($user);

        if ($this->hasRealRunningSubscription($user)) {
            $this->command?->warn("A conta {$user->email} tem uma subscrição real do Stripe em curso: ficou sem dados de pagamento de demonstração.");
            $this->command?->warn('Para os ter, cancele-a no dashboard de teste do Stripe e volte a correr o seeder.');

            return;
        }

        $this->seedBilling($user, $plan['nif']);

        if ($plan['subscription'] !== null) {
            $this->seedSubscription($user, $plan['subscription']);
        }
    }

    /**
     * Apaga os dados de pagamento de demonstração da conta (linhas com o prefixo "_demo_" e os PDFs
     * das faturas). Nunca toca em linhas reais. Exceção consciente à regra "as faturas nunca se
     * apagam": são documentos de demonstração, sem validade fiscal.
     */
    private function resetDemoPayments(User $user): void
    {
        $invoices = $user->issuedInvoices()->where('stripe_invoice_id', 'like', 'in_demo_%')->get()
            ->filter(fn (Invoice $invoice) => str_starts_with($invoice->stripe_invoice_id, 'in_demo_'));

        foreach ($invoices as $invoice) {
            $path = (string) $invoice->provider_invoice_id;

            if ($invoice->provider === 'demo' && str_starts_with($path, "invoices/{$user->id}/") && ! str_contains($path, '..')) {
                Storage::disk(config('filesystems.invoices_disk'))->delete($path);
            }

            $invoice->delete();
        }

        $subscriptions = $user->subscriptions()->where('stripe_id', 'like', 'sub_demo_%')->get()
            ->filter(fn (Subscription $subscription) => str_starts_with($subscription->stripe_id, 'sub_demo_'));

        foreach ($subscriptions as $subscription) {
            $subscription->items()->delete();
            $subscription->delete();
        }
    }

    /**
     * Se a conta (já existente) tem uma subscrição real do Stripe em curso, que o seeder não pode tocar.
     */
    private function hasRealRunningSubscription(User $user): bool
    {
        return $user->exists
            && $user->subscriptions()->where('stripe_id', 'not like', 'sub_demo_%')->active()->exists();
    }

    /**
     * Dados de faturação da conta (os pedidos na ativação): a empresa e a morada do perfil e um NIF
     * de demonstração. Null em quem ainda não ativou. Sem cartão guardado (pm_type, pm_last_four).
     */
    private function seedBilling(User $user, ?string $nif): void
    {
        $profile = $user->memberProfile;
        $activated = $nif !== null;

        $user->forceFill([
            'billing_name' => $activated ? $profile->business_name : null,
            'nif' => $nif,
            'billing_address' => $activated ? $profile->address : null,
            'billing_postal_code' => $activated ? $profile->postal_code : null,
            'billing_city' => $activated ? $profile->city : null,
            'pm_type' => null,
            'pm_last_four' => null,
        ])->save();
    }

    /**
     * Grava a subscrição e o item como o Cashier os grava no customer.subscription.created, as
     * datas de trial e carência na conta (como o webhook) e emite as faturas dos períodos pagos.
     *
     * @param  array{status: string, created_at: Carbon, trial_ends_at: Carbon, ends_at: ?Carbon, grace_ends_at: ?Carbon, periods: list<array{Carbon, Carbon}>}  $dates
     */
    private function seedSubscription(User $user, array $dates): void
    {
        $subscription = $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => $this->demoStripeId($user, 'sub'),
            'stripe_status' => $dates['status'],
            'stripe_price' => 'price_demo',
            'quantity' => 1,
            'trial_ends_at' => $dates['trial_ends_at'],
            'ends_at' => $dates['ends_at'],
            'created_at' => $dates['created_at'],
            'updated_at' => $dates['created_at'],
        ]);

        $subscription->items()->create([
            'stripe_id' => $this->demoStripeId($user, 'si'),
            'stripe_product' => 'prod_demo',
            'stripe_price' => 'price_demo',
            'quantity' => 1,
        ]);

        $user->forceFill([
            'trial_ends_at' => $dates['trial_ends_at'],
            'grace_ends_at' => $dates['grace_ends_at'],
        ])->save();

        foreach ($dates['periods'] as $index => [$start, $end]) {
            $this->issueDemoInvoice($user, $index + 1, $start, $end);
        }
    }

    /**
     * Fatura de um período pago pelo mesmo caminho do webhook: linha pending e IssueInvoiceJob,
     * aqui síncrono e com as trocas de withDemoInvoicing() (driver demo, sem email, data do pagamento).
     */
    private function issueDemoInvoice(User $user, int $number, Carbon $periodStart, Carbon $periodEnd): void
    {
        $this->withDemoInvoicing($periodStart, function () use ($user, $number, $periodStart, $periodEnd) {
            $invoice = $user->issuedInvoices()->create([
                'stripe_invoice_id' => $this->demoStripeId($user, 'in')."_{$number}",
                'provider' => config('services.invoicing.driver'),
                'amount' => (int) round(config('subscription.price_amount') * 100),
                'currency' => config('subscription.currency'),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => InvoiceStatus::Pending,
            ]);

            IssueInvoiceJob::dispatchSync($invoice->id);
        });
    }

    /**
     * Corre a emissão com três trocas, repostas num finally (também se a emissão falhar):
     * driver de faturação demo (o seeder nunca chama a InvoiceExpress e o PDF existe mesmo com
     * INVOICING_DRIVER=log), mailer array (o PDF tem NIF e morada e não pode sair por email nem ficar
     * no laravel.log) e relógio no dia do pagamento (o job usa "agora" como data de emissão, que dá
     * a data e o ano do número da fatura).
     */
    private function withDemoInvoicing(Carbon $paidAt, Closure $issue): void
    {
        $driver = config('services.invoicing.driver');
        $mailer = config('mail.default');
        $testNow = Carbon::getTestNow();

        config(['services.invoicing.driver' => 'demo', 'mail.default' => 'array']);
        Carbon::setTestNow($paidAt->copy());

        try {
            $issue();
        } finally {
            config(['services.invoicing.driver' => $driver, 'mail.default' => $mailer]);
            Carbon::setTestNow($testNow);
        }
    }

    /**
     * Id falso do Stripe para a conta (ex.: sub_demo_ seguido dos 32 hex do id): o "_demo_" mostra que não vem do
     * Stripe e marca as linhas que o seeder apaga; derivar do id da conta evita repetir o id quando
     * uma conta demo anonimizada (cujas linhas ficam) é criada de novo.
     */
    private function demoStripeId(User $user, string $prefix): string
    {
        return "{$prefix}_demo_".str_replace('-', '', $user->id);
    }

    /**
     * Resumo no fim: contas por estado da subscrição (como no backoffice), volumes criados, as
     * contas principais, a password, o lembrete do storage:link (só com o disco public) e o que não
     * funciona com as subscrições de demonstração.
     */
    private function printSummary(): void
    {
        $users = User::with('accountStatus')->whereIn('email', array_column($this->plans, 'email'))->get();
        $states = $users->countBy(fn (User $user) => $user->subscriptionState()->label())->sortKeys();
        $profileIds = array_values($this->profileIds);

        $this->command?->table(['Estado', 'Contas'], $states->map(fn (int $count, string $state) => [$state, $count])->values()->all());
        $this->command?->table(['Dados', 'Quantidade'], [
            ['Perfis no diretório', MemberProfile::listedInDirectory()->whereIn('id', $profileIds)->count()],
            ['Eventos (com sessões das séries)', EventInstance::count()],
            ['Inscrições', EventRegistration::count()],
            ['Avaliações entre perfis demo', Review::whereIn('reviewed_profile_id', $profileIds)->count()],
            ['Cartões de oração', PrayerCard::count()],
            ['Faturas demo', Invoice::where('stripe_invoice_id', 'like', 'in_demo_%')->count()],
        ]);

        $this->command?->info('Contas principais: demo.admin@aspec.test (administrador) e demo.membro@aspec.test (membro com mais dados). As restantes: demo.{chave}@aspec.test (ver database/seeders/demo/data).');
        $this->command?->info(app()->environment('local', 'testing')
            ? 'Password de todas as contas: '.$this->demoPassword()
            : 'Password de todas as contas: a definida em DEMO_PASSWORD');

        if (config('filesystems.media_disk') === 'public') {
            $this->command?->warn('Para as imagens abrirem no frontend, corra uma vez: php artisan storage:link');
        }

        $this->command?->warn('As subscrições de demonstração não existem no Stripe: o portal de faturação, cancelar e retomar não funcionam com elas (use uma conta ativada pelo Checkout).');
    }

    /**
     * Id de um registo de lookup pelo nome (falha se o nome não existir, para apanhar gralhas nos dados).
     *
     * @param  class-string<Model>  $model
     */
    private function lookupId(string $model, string $name): string|int
    {
        return $this->lookupIds[$model][$name] ??= $model::query()->where('name', $name)->firstOrFail()->getKey();
    }

    /**
     * Fuso horário dos eventos (o da agenda pública e do backoffice).
     */
    private function eventTimezone(): string
    {
        return config('app.event_timezone', 'Europe/Lisbon');
    }

    /**
     * Horas da formação de hoje com cartões de oração: começa na hora certa anterior a "agora − 1 hora"
     * (Lisboa), para o prazo do cartão já estar aberto quando o seeder corre, e dura 2 horas sem
     * passar das 23:59. Antes da 01:00 começa às 00:00.
     *
     * @return array{string, string}
     */
    private function todayEventTimes(): array
    {
        $now = now($this->eventTimezone());
        $start = $now->copy()->subHour()->startOfHour();

        if (! $start->isSameDay($now)) {
            $start = $now->copy()->startOfDay();
        }

        $end = $start->copy()->addHours(2);

        return [$start->format('H:i'), $end->isSameDay($start) ? $end->format('H:i') : '23:59'];
    }
}
