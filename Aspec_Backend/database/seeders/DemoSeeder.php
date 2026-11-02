<?php

namespace Database\Seeders;

use App\Enums\InactiveReason;
use App\Models\AccountStatus;
use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\Location;
use App\Models\MemberProfile;
use App\Models\PrayerCard;
use App\Models\Review;
use App\Models\ReviewStatus;
use App\Models\Role;
use App\Models\Sector;
use App\Models\SocialPlatform;
use App\Models\User;
use App\Models\WeekDay;
use GdImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dados de demonstração: contas em todos os estados, perfis completos com logótipo e portefólio,
 * avaliações entre perfis, eventos (presenciais, online e passados), inscrições e cartões de oração. Só para desenvolvimento e apresentações; corre à parte do DatabaseSeeder:
 * php artisan db:seed --class=DemoSeeder
 *
 * Pode correr várias vezes: procura as contas pelo email, os perfis pelo utilizador e os eventos
 * pelo nome e distrito, e repõe os dados originais, incluindo as avaliações entre perfis de demonstração
 * e as inscrições e os cartões de oração desses eventos (útil para repetir a demonstração).
 * Os cartões de oração ficam encriptados com a APP_KEY de quem corre o seeder.
 * Não chama o Stripe nem o InvoiceExpress: as contas Active não têm subscrição.
 */
class DemoSeeder extends Seeder
{
    /** Password de todas as contas de demonstração (cumpre as regras de password da aplicação). */
    public const PASSWORD = 'Demo#2026';

    /** Cores de fundo dos logótipos e do portefólio (RGB). */
    private const PALETTE = [
        [13, 31, 53],
        [49, 90, 121],
        [138, 112, 67],
        [46, 107, 79],
        [122, 62, 72],
        [75, 78, 140],
        [160, 82, 45],
        [197, 164, 107],
    ];

    /** Linhas da tabela de contas mostrada no fim. */
    private array $accountRows = [];

    /** Ids dos lookups já procurados, por modelo e nome. */
    private array $lookupIds = [];

    /**
     * Cria ou repõe os dados de demonstração. Recusa correr em produção e, se os lookups
     * ainda não existirem, corre primeiro o DatabaseSeeder.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('O DemoSeeder não pode correr em produção.');

            return;
        }

        if ($this->lookupsMissing()) {
            $this->call(DatabaseSeeder::class);
        }

        $canDrawImages = extension_loaded('gd');

        if (! $canDrawImages) {
            $this->command?->warn('A extensão GD do PHP não está ativa: os perfis ficam sem logótipo e sem portefólio.');
        }

        $profileIds = [];

        foreach ($this->activeMembers() as $index => $member) {
            $profile = $this->seedAccount($member);
            $profileIds[$member['email']] = $profile->id;

            $this->syncBusinessHours($profile, $member['hours']);
            $this->syncSocialLinks($profile, $member['social']);

            if ($canDrawImages) {
                $this->seedLogo($profile, self::PALETTE[$index % count(self::PALETTE)]);
                $this->seedPortfolio($profile, $member['portfolio'], $index);
            }
        }

        foreach ($this->applicants() as $applicant) {
            $this->seedAccount($applicant);
        }

        $this->seedReviews($profileIds);
        $this->seedEvents();

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
     * Cria ou repõe a conta (papel, estado, motivo, telefone e password) e o respetivo perfil.
     * Limpa a marca da agenda semanal, para a demonstração a poder enviar outra vez na mesma semana.
     *
     * @param  array<string, mixed>  $data  Uma entrada de activeMembers() ou applicants().
     */
    private function seedAccount(array $data): MemberProfile
    {
        $user = User::firstOrNew(['email' => $data['email']]);

        // Papel, estado e motivo não são fillable (só o admin e os pagamentos os mudam), daí o forceFill.
        $user->forceFill([
            'password' => self::PASSWORD,
            'phone' => $data['phone'],
            'role_id' => $this->lookupId(Role::class, $data['role'] ?? 'Member'),
            'account_status_id' => $this->lookupId(AccountStatus::class, $data['status'] ?? 'Active'),
            'inactive_reason' => $data['reason'] ?? null,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'weekly_agenda_sent_at' => null,
        ])->save();

        $profile = $data['profile'];

        $this->accountRows[] = [
            $user->email,
            $data['role'] ?? 'Member',
            ($data['status'] ?? 'Active').(isset($data['reason']) ? " ({$data['reason']->value})" : ''),
            $profile['business_name'],
        ];

        return MemberProfile::updateOrCreate(['user_id' => $user->id], [
            'sector_id' => $this->lookupId(Sector::class, $profile['sector']),
            'location_id' => $this->lookupId(Location::class, $profile['location']),
            'name' => $profile['name'],
            'business_name' => $profile['business_name'],
            'congregation' => $profile['congregation'],
            'role_in_congregation' => $profile['role_in_congregation'],
            'description' => $profile['description'],
            'website_url' => $profile['website_url'] ?? null,
            'commercial_email' => $profile['commercial_email'] ?? null,
            'commercial_phone' => $profile['commercial_phone'] ?? null,
            'address' => $profile['address'],
            'postal_code' => $profile['postal_code'],
            'city' => $profile['city'],
        ]);
    }

    /**
     * Horário de funcionamento: dias úteis 09:00–18:00, com ou sem sábado de manhã, ou nenhum.
     *
     * @param  string|null  $schedule  'weekdays', 'weekdays_saturday' ou null (sem horário).
     */
    private function syncBusinessHours(MemberProfile $profile, ?string $schedule): void
    {
        $hours = [];

        if ($schedule !== null) {
            foreach (range(1, 5) as $weekDayId) {
                $hours[$weekDayId] = ['open_time' => '09:00', 'close_time' => '18:00'];
            }
        }

        if ($schedule === 'weekdays_saturday') {
            $hours[6] = ['open_time' => '09:00', 'close_time' => '13:00'];
        }

        $profile->weekDays()->sync($hours);
    }

    /**
     * Redes sociais do perfil.
     *
     * @param  array<string, string>  $links  Nome da plataforma => URL.
     */
    private function syncSocialLinks(MemberProfile $profile, array $links): void
    {
        $pivot = [];

        foreach ($links as $platform => $url) {
            $pivot[$this->lookupId(SocialPlatform::class, $platform)] = ['url' => $url];
        }

        $profile->socialPlatforms()->sync($pivot);
    }

    /**
     * Logótipo 400×400 com as iniciais da empresa, em logos/{user_id} no disco public
     * (a mesma pasta do upload do membro). Nome fixo para não criar ficheiros repetidos.
     *
     * @param  array{int, int, int}  $background  Cor de fundo (RGB).
     */
    private function seedLogo(MemberProfile $profile, array $background): void
    {
        $path = "logos/{$profile->user_id}/demo-logo.png";

        if (! Storage::disk('public')->exists($path)) {
            $image = imagecreatetruecolor(400, 400);
            imagefill($image, 0, 0, imagecolorallocate($image, ...$background));
            $this->drawText($image, $this->initials($profile->business_name), 12, 200, 200, [255, 255, 255]);

            Storage::disk('public')->put($path, $this->toPng($image));
        }

        $profile->update(['logo_path' => $path]);
    }

    /**
     * Imagens do portefólio (1200×800, gradiente e "Projeto N"), em portfolios/{user_id}
     * no disco public, como no upload do membro.
     *
     * @param  int  $count  Número de imagens (0 = perfil sem portefólio).
     */
    private function seedPortfolio(MemberProfile $profile, int $count, int $paletteOffset): void
    {
        for ($number = 1; $number <= $count; $number++) {
            $path = "portfolios/{$profile->user_id}/demo-projeto-{$number}.png";

            if (! Storage::disk('public')->exists($path)) {
                $top = self::PALETTE[($paletteOffset + $number) % count(self::PALETTE)];
                $bottom = self::PALETTE[($paletteOffset + $number + 3) % count(self::PALETTE)];

                $image = $this->gradient(1200, 800, $top, $bottom);
                $this->drawText($image, "Projeto {$number}", 8, 600, 360, [255, 255, 255]);
                $this->drawText($image, Str::ascii($profile->business_name), 3, 600, 520, [255, 255, 255]);

                Storage::disk('public')->put($path, $this->toPng($image));
            }

            $profile->portfolios()->firstOrCreate(['image_path' => $path]);
        }
    }

    /**
     * Eventos (futuros, online e passados) com datas relativas ao dia de hoje em Lisboa, e as
     * respetivas inscrições. Procura pelo nome e distrito (e não pela data): correr noutro dia
     * atualiza as datas em vez de duplicar os eventos. O prazo de inscrição fica às 23:59 (hora de
     * Lisboa) do dia indicado, por omissão a véspera, como o backoffice o guarda.
     */
    private function seedEvents(): void
    {
        $today = now(config('app.event_timezone', 'Europe/Lisbon'))->startOfDay();

        foreach ($this->events() as $event) {
            $deadlineDays = $event['deadline_days_from_now'] ?? $event['days_from_now'] - 1;

            $instance = EventInstance::updateOrCreate(
                [
                    'name' => $event['name'],
                    'location_id' => $this->lookupId(Location::class, $event['location']),
                ],
                [
                    'category_id' => $this->lookupId(EventCategory::class, $event['category']),
                    'event_date' => $today->copy()->addDays($event['days_from_now'])->format('Y-m-d'),
                    'event_start_time' => $event['start'].':00',
                    'event_end_time' => $event['end'].':00',
                    'description' => $event['description'],
                    'is_online' => isset($event['online_url']),
                    'online_url' => $event['online_url'] ?? null,
                    'address' => $event['address'] ?? null,
                    'venue_name' => $event['venue'] ?? null,
                    'place_id' => $event['place_id'] ?? null,
                    'latitude' => $event['latitude'] ?? null,
                    'longitude' => $event['longitude'] ?? null,
                    'max_attendees' => $event['max_attendees'],
                    'registration_deadline' => $today->copy()->addDays($deadlineDays)->setTime(23, 59)->format('Y-m-d H:i:s'),
                    'organizer_notes' => $event['notes'] ?? null,
                    'prayer_cards_enabled' => isset($event['prayer_verse']),
                    'prayer_verse' => $event['prayer_verse'] ?? null,
                ]
            );

            $this->syncRegistrations($instance, $event['registrants'] ?? []);
            $this->syncPrayerCards($instance, $event['prayer_cards'] ?? null);
        }
    }

    /**
     * Repõe as inscrições do evento de demonstração: apaga as que existirem e volta a criar as
     * da lista, para a lotação ficar sempre igual depois de uma demonstração (inscrições ou
     * cancelamentos feitos à mão). Só aceita contas Active, como a inscrição pela API.
     *
     * @param  list<string>  $emails
     *
     * @throws RuntimeException Se algum email não for de uma conta Active ou exceder a lotação.
     */
    private function syncRegistrations(EventInstance $event, array $emails): void
    {
        $userIds = User::whereIn('email', $emails)
            ->whereHas('accountStatus', fn ($query) => $query->where('name', 'Active'))
            ->pluck('id');

        if ($userIds->count() !== count($emails) || count($emails) > $event->max_attendees) {
            throw new RuntimeException("Inscrições de demonstração inválidas no evento \"{$event->name}\".");
        }

        $event->registrations()->delete();

        foreach ($userIds as $userId) {
            $event->registrations()->create(['user_id' => $userId]);
        }
    }

    /**
     * Repõe os cartões de oração do evento de demonstração: apaga os que existirem (incluindo os
     * enviados à mão numa demonstração) e volta a criar os da lista, cada um com pedidos de exemplo.
     * Num evento distribuído, a cadeia segue a ordem da lista: o autor na posição i recebe o cartão
     * do autor na posição i+1 e o último recebe o do primeiro; a distribuição fica às 00:05 do dia
     * seguinte ao evento, como a do comando de hora a hora. Não envia emails.
     *
     * @param  array{authors: list<string>, distributed?: bool}|null  $cards
     *
     * @throws RuntimeException Se algum autor não estiver inscrito no evento.
     */
    private function syncPrayerCards(EventInstance $event, ?array $cards): void
    {
        PrayerCard::withTrashed()->where('event_instance_id', $event->id)->forceDelete();
        $event->forceFill(['prayer_cards_distributed_at' => null])->save();

        if ($cards === null) {
            return;
        }

        $authorIds = User::whereIn('email', $cards['authors'])
            ->whereHas('eventRegistrations', fn ($query) => $query->where('event_instance_id', $event->id))
            ->pluck('id', 'email');

        if ($authorIds->count() !== count($cards['authors'])) {
            throw new RuntimeException("Cartões de oração de demonstração inválidos no evento \"{$event->name}\".");
        }

        $distributed = $cards['distributed'] ?? false;
        $requests = $this->prayerRequests();
        $created = [];

        foreach ($cards['authors'] as $position => $email) {
            [$personal, $work, $salvation] = $requests[$position % count($requests)];
            $submittedAt = $distributed ? $event->startsAt()->addMinutes(15 * ($position + 1))->utc() : now();

            $card = new PrayerCard([
                'event_instance_id' => $event->id,
                'author_user_id' => $authorIds[$email],
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
     * Pedidos de exemplo dos cartões de oração [Pessoal, Emprego, Salvação]; null = parte não preenchida.
     *
     * @return list<array{?string, ?string, ?string}>
     */
    private function prayerRequests(): array
    {
        return [
            ['Pela saúde da minha mãe, que vai ser operada no próximo mês.', 'Sabedoria para decidir a contratação de dois novos colaboradores.', null],
            ['Pela recuperação do meu pai, internado desde a semana passada.', 'Que a obra em Guimarães termine dentro do prazo e sem acidentes.', 'Pelo meu sócio, para que encontre a paz.'],
            [null, 'Discernimento para a abertura do novo escritório em Aveiro.', 'Pelo meu irmão, que se afastou da fé.'],
            ['Pela minha família, para termos mais tempo juntos.', null, 'Pelos meus vizinhos, para que conheçam Jesus.'],
        ];
    }

    /**
     * Repõe as avaliações entre perfis de demonstração: apaga as que existirem entre eles (incluindo
     * as feitas ou moderadas à mão numa demonstração) e volta a criar as de reviews().
     * Não toca em avaliações em que o autor ou o avaliado não seja um perfil de demonstração.
     *
     * @param  array<string, string>  $profileIds  Id do perfil por email da conta.
     */
    private function seedReviews(array $profileIds): void
    {
        Review::whereIn('reviewer_profile_id', $profileIds)
            ->whereIn('reviewed_profile_id', $profileIds)
            ->delete();

        foreach ($this->reviews() as [$reviewer, $reviewed, $rating, $status, $comment]) {
            Review::create([
                'reviewer_profile_id' => $profileIds[$reviewer],
                'reviewed_profile_id' => $profileIds[$reviewed],
                'rating' => $rating,
                'comment' => $comment,
                'status_id' => $this->lookupId(ReviewStatus::class, $status),
            ]);
        }
    }

    /**
     * Mostra as contas criadas, a password comum e o lembrete do storage:link.
     */
    private function printSummary(): void
    {
        $this->command?->table(['Email', 'Papel', 'Estado', 'Empresa'], $this->accountRows);
        $this->command?->info('Password de todas as contas: '.self::PASSWORD);
        $this->command?->warn('Para as imagens abrirem no frontend, corra uma vez: php artisan storage:link');
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
     * Iniciais das duas primeiras palavras com mais de duas letras (ex.: "Carvalho & Filhos" → "CF").
     */
    private function initials(string $businessName): string
    {
        return Str::of(Str::ascii($businessName))
            ->explode(' ')
            ->filter(fn (string $word) => strlen($word) > 2)
            ->take(2)
            ->map(fn (string $word) => strtoupper($word[0]))
            ->implode('');
    }

    /**
     * Escreve texto centrado em ($centerX, $centerY), ampliado $scale vezes. A fonte embutida do GD
     * é muito pequena (9×15 px), por isso o texto é desenhado numa imagem pequena e depois ampliado.
     *
     * @param  array{int, int, int}  $rgb
     */
    private function drawText(GdImage $canvas, string $text, int $scale, int $centerX, int $centerY, array $rgb): void
    {
        $font = 5;
        $width = imagefontwidth($font) * strlen($text);
        $height = imagefontheight($font);

        $small = imagecreatetruecolor($width, $height);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagestring($small, $font, 0, 0, $text, imagecolorallocate($small, ...$rgb));

        imagecopyresized(
            $canvas, $small,
            $centerX - intdiv($width * $scale, 2), $centerY - intdiv($height * $scale, 2),
            0, 0,
            $width * $scale, $height * $scale,
            $width, $height
        );
    }

    /**
     * Imagem com um gradiente vertical entre duas cores.
     *
     * @param  array{int, int, int}  $top
     * @param  array{int, int, int}  $bottom
     */
    private function gradient(int $width, int $height, array $top, array $bottom): GdImage
    {
        $image = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / ($height - 1);
            $rgb = array_map(fn (int $from, int $to) => (int) round($from + ($to - $from) * $ratio), $top, $bottom);

            imageline($image, 0, $y, $width - 1, $y, imagecolorallocate($image, ...$rgb));
        }

        return $image;
    }

    /**
     * Conteúdo PNG de uma imagem GD.
     */
    private function toPng(GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    /**
     * Os 13 perfis Active do diretório (o admin incluído), com setores e distritos variados.
     *
     * @return list<array<string, mixed>>
     */
    private function activeMembers(): array
    {
        return [
            [
                'email' => 'demo.admin@aspec.test',
                'role' => 'Admin',
                'phone' => '+351912000001',
                'hours' => 'weekdays',
                'social' => ['LinkedIn' => 'https://www.linkedin.com/company/mendes-consultoria-gestao'],
                'portfolio' => 0,
                'profile' => [
                    'name' => 'Ricardo Mendes',
                    'business_name' => 'Mendes Consultoria de Gestão',
                    'sector' => 'Consultoria de Gestão e Negócios',
                    'location' => 'Lisboa',
                    'description' => 'Apoiamos pequenas e médias empresas a organizar processos, definir objetivos e crescer de forma sustentável. Fazemos diagnósticos de gestão, planos de negócio e acompanhamento mensal.',
                    'website_url' => 'https://www.mendesconsultoria.pt',
                    'commercial_email' => 'geral@mendesconsultoria.pt',
                    'commercial_phone' => '+351213000101',
                    'address' => 'Avenida da República 50, 3.º Esq.',
                    'postal_code' => '1050-196',
                    'city' => 'Lisboa',
                    'congregation' => 'Comunidade Cristã do Areeiro',
                    'role_in_congregation' => 'Presbítero',
                ],
            ],
            [
                'email' => 'demo.membro@aspec.test',
                'phone' => '+351912000002',
                'hours' => 'weekdays_saturday',
                'social' => [
                    'LinkedIn' => 'https://www.linkedin.com/company/ferreira-arquitetura-interiores',
                    'Instagram' => 'https://www.instagram.com/ferreira.arquitetura',
                    'Facebook' => 'https://www.facebook.com/ferreira.arquitetura',
                ],
                'portfolio' => 4,
                'profile' => [
                    'name' => 'Ana Ferreira',
                    'business_name' => 'Ferreira Arquitetura e Interiores',
                    'sector' => 'Arquitetura e Design',
                    'location' => 'Porto',
                    'description' => 'Projetos de arquitetura residencial e comercial, da ideia ao licenciamento. Remodelações de interiores com acompanhamento de obra e escolha de materiais.',
                    'website_url' => 'https://www.ferreiraarquitetura.pt',
                    'commercial_email' => 'projetos@ferreiraarquitetura.pt',
                    'commercial_phone' => '+351220000202',
                    'address' => 'Rua de Cedofeita 300',
                    'postal_code' => '4050-174',
                    'city' => 'Porto',
                    'congregation' => 'Igreja Evangélica da Boavista',
                    'role_in_congregation' => 'Líder do ministério de jovens',
                ],
            ],
            [
                'email' => 'demo.membro01@aspec.test',
                'phone' => '+351913000003',
                'hours' => 'weekdays_saturday',
                'social' => [
                    'Facebook' => 'https://www.facebook.com/carvalhoefilhosconstrucoes',
                    'YouTube' => 'https://www.youtube.com/@carvalhoefilhos',
                ],
                'portfolio' => 3,
                'profile' => [
                    'name' => 'João Carvalho',
                    'business_name' => 'Carvalho & Filhos Construções',
                    'sector' => 'Construção Civil e Obras Públicas',
                    'location' => 'Braga',
                    'description' => 'Empresa familiar de construção civil com mais de 20 anos de experiência. Construção de moradias, reabilitação de edifícios e pequenas obras públicas.',
                    'website_url' => 'https://www.carvalhoefilhos.pt',
                    'commercial_email' => 'orcamentos@carvalhoefilhos.pt',
                    'commercial_phone' => '+351253000303',
                    'address' => 'Rua Nova de Santa Cruz 85',
                    'postal_code' => '4710-409',
                    'city' => 'Braga',
                    'congregation' => 'Igreja Batista de Braga Norte',
                    'role_in_congregation' => 'Diácono',
                ],
            ],
            [
                'email' => 'demo.membro02@aspec.test',
                'phone' => '+351916000004',
                'hours' => 'weekdays',
                'social' => ['LinkedIn' => 'https://www.linkedin.com/company/contas-certas-contabilidade'],
                'portfolio' => 0,
                'profile' => [
                    'name' => 'Marta Sousa',
                    'business_name' => 'Contas Certas Contabilidade',
                    'sector' => 'Contabilidade e Finanças',
                    'location' => 'Coimbra',
                    'description' => 'Contabilidade organizada para empresas e empresários em nome individual. Processamento salarial, declarações fiscais e apoio na candidatura a incentivos.',
                    'website_url' => 'https://www.contascertas-coimbra.pt',
                    'commercial_email' => 'geral@contascertas-coimbra.pt',
                    'commercial_phone' => '+351239000404',
                    'address' => 'Avenida Fernão de Magalhães 410',
                    'postal_code' => '3000-177',
                    'city' => 'Coimbra',
                    'congregation' => 'Comunidade Evangélica de Celas',
                    'role_in_congregation' => 'Tesoureira',
                ],
            ],
            [
                'email' => 'demo.membro03@aspec.test',
                'phone' => '+351919000005',
                'hours' => 'weekdays_saturday',
                'social' => [
                    'Instagram' => 'https://www.instagram.com/saboresdoalgarve.faro',
                    'Facebook' => 'https://www.facebook.com/saboresdoalgarve.faro',
                ],
                'portfolio' => 3,
                'profile' => [
                    'name' => 'Pedro Almeida',
                    'business_name' => 'Sabores do Algarve Restaurante',
                    'sector' => 'Restauração e Hotelaria',
                    'location' => 'Faro',
                    'description' => 'Cozinha tradicional algarvia com peixe fresco da lota de Faro. Servimos almoços de grupo, jantares de empresa e serviço de catering para eventos.',
                    'website_url' => 'https://www.saboresdoalgarve-faro.pt',
                    'commercial_email' => 'reservas@saboresdoalgarve-faro.pt',
                    'commercial_phone' => '+351289000505',
                    'address' => 'Rua Conselheiro Bivar 20',
                    'postal_code' => '8000-255',
                    'city' => 'Faro',
                    'congregation' => 'Igreja Cristã da Sé de Faro',
                    'role_in_congregation' => 'Membro',
                ],
            ],
            [
                'email' => 'demo.membro04@aspec.test',
                'phone' => '+447911123456',
                'hours' => 'weekdays',
                'social' => [
                    'LinkedIn' => 'https://www.linkedin.com/company/ribeiro-digital',
                    'YouTube' => 'https://www.youtube.com/@ribeirodigital',
                ],
                'portfolio' => 0,
                'profile' => [
                    'name' => 'Sofia Ribeiro',
                    'business_name' => 'Ribeiro Digital Soluções Informáticas',
                    'sector' => 'Tecnologias de Informação (TI)',
                    'location' => 'Aveiro',
                    'description' => 'Desenvolvimento de sites e aplicações à medida, manutenção de redes e apoio informático a escritórios. Contratos de assistência mensal com tempo de resposta garantido.',
                    'website_url' => 'https://www.ribeirodigital.pt',
                    'commercial_email' => 'suporte@ribeirodigital.pt',
                    'commercial_phone' => '+351234000606',
                    'address' => 'Rua de Viana do Castelo 15',
                    'postal_code' => '3800-275',
                    'city' => 'Aveiro',
                    'congregation' => 'Igreja Evangélica da Glória',
                    'role_in_congregation' => 'Responsável pela equipa de som',
                ],
            ],
            [
                'email' => 'demo.membro05@aspec.test',
                'phone' => '+351924000007',
                'hours' => 'weekdays',
                'social' => ['LinkedIn' => 'https://www.linkedin.com/company/martins-advogados-associados'],
                'portfolio' => 0,
                'profile' => [
                    'name' => 'Tiago Martins',
                    'business_name' => 'Martins Advogados Associados',
                    'sector' => 'Serviços Jurídicos e Advocacia',
                    'location' => 'Leiria',
                    'description' => 'Sociedade de advogados dedicada ao direito empresarial, laboral e imobiliário. Constituição de sociedades, contratos comerciais e cobranças.',
                    'website_url' => 'https://www.martinsadvogados.pt',
                    'commercial_email' => 'escritorio@martinsadvogados.pt',
                    'commercial_phone' => '+351244000707',
                    'address' => 'Largo Cândido dos Reis 7',
                    'postal_code' => '2400-112',
                    'city' => 'Leiria',
                    'congregation' => 'Comunidade Cristã de Leiria',
                    'role_in_congregation' => 'Conselheiro',
                ],
            ],
            [
                'email' => 'demo.membro06@aspec.test',
                'phone' => '+351927000008',
                'hours' => 'weekdays_saturday',
                'social' => [
                    'Facebook' => 'https://www.facebook.com/clinicabemcuidar',
                    'Instagram' => 'https://www.instagram.com/clinicabemcuidar',
                ],
                'portfolio' => 0,
                'profile' => [
                    'name' => 'Inês Costa',
                    'business_name' => 'Clínica Bem Cuidar',
                    'sector' => 'Saúde e Medicina',
                    'location' => 'Setúbal',
                    'description' => 'Clínica de medicina geral e familiar, fisioterapia e nutrição. Acordos com empresas para medicina do trabalho.',
                    'website_url' => 'https://www.clinicabemcuidar.pt',
                    'commercial_email' => 'marcacoes@clinicabemcuidar.pt',
                    'commercial_phone' => '+351265000808',
                    'address' => 'Avenida Luísa Todi 250',
                    'postal_code' => '2900-452',
                    'city' => 'Setúbal',
                    'congregation' => 'Igreja Evangélica de Setúbal',
                    'role_in_congregation' => 'Diaconisa',
                ],
            ],
            [
                'email' => 'demo.membro07@aspec.test',
                'phone' => '+5511912345678',
                'hours' => null,
                'social' => ['LinkedIn' => 'https://www.linkedin.com/company/rota-segura-transportes'],
                'portfolio' => 0,
                'profile' => [
                    'name' => 'Miguel Rodrigues',
                    'business_name' => 'Rota Segura Transportes',
                    'sector' => 'Transportes e Logística',
                    'location' => 'Santarém',
                    'description' => 'Transporte rodoviário de mercadorias em todo o país e armazenagem. Frota própria com rastreamento e entregas no dia seguinte.',
                    'website_url' => 'https://www.rotasegura.pt',
                    'commercial_email' => 'logistica@rotasegura.pt',
                    'commercial_phone' => '+351243000909',
                    'address' => 'Zona Industrial de Santarém, Lote 12',
                    'postal_code' => '2005-002',
                    'city' => 'Santarém',
                    'congregation' => 'Igreja Batista de Santarém',
                    'role_in_congregation' => 'Membro',
                ],
            ],
            [
                'email' => 'demo.membro08@aspec.test',
                'phone' => '+351934000010',
                'hours' => 'weekdays_saturday',
                'social' => [
                    'Instagram' => 'https://www.instagram.com/casalusa.imobiliaria',
                    'Facebook' => 'https://www.facebook.com/casalusa.imobiliaria',
                ],
                'portfolio' => 0,
                'profile' => [
                    'name' => 'Catarina Lopes',
                    'business_name' => 'Casa Lusa Imobiliária',
                    'sector' => 'Imobiliário',
                    'location' => 'Viseu',
                    'description' => 'Compra, venda e arrendamento de imóveis na região de Viseu. Avaliação gratuita e acompanhamento até à escritura.',
                    'website_url' => 'https://www.casalusa-imobiliaria.pt',
                    'commercial_email' => 'imoveis@casalusa-imobiliaria.pt',
                    'commercial_phone' => '+351232000110',
                    'address' => 'Rua Formosa 60',
                    'postal_code' => '3500-135',
                    'city' => 'Viseu',
                    'congregation' => 'Comunidade Evangélica de Viseu',
                    'role_in_congregation' => 'Coordenadora da escola dominical',
                ],
            ],
            [
                'email' => 'demo.membro09@aspec.test',
                'phone' => '+351937000011',
                'hours' => 'weekdays',
                'social' => [
                    'LinkedIn' => 'https://www.linkedin.com/company/ideia-viva-comunicacao',
                    'Instagram' => 'https://www.instagram.com/ideiaviva.comunicacao',
                    'YouTube' => 'https://www.youtube.com/@ideiaviva',
                ],
                'portfolio' => 4,
                'profile' => [
                    'name' => 'Daniel Pereira',
                    'business_name' => 'Ideia Viva Comunicação',
                    'sector' => 'Marketing, Comunicação e Publicidade',
                    'location' => 'Lisboa',
                    'description' => 'Agência de comunicação para negócios locais: identidade visual, gestão de redes sociais e campanhas digitais. Fotografia e vídeo para empresas.',
                    'website_url' => 'https://www.ideiaviva.pt',
                    'commercial_email' => 'ola@ideiaviva.pt',
                    'commercial_phone' => '+351218000111',
                    'address' => 'Rua Morais Soares 90',
                    'postal_code' => '1900-345',
                    'city' => 'Lisboa',
                    'congregation' => 'Igreja Cristã de Arroios',
                    'role_in_congregation' => 'Responsável pela comunicação',
                ],
            ],
            [
                'email' => 'demo.membro10@aspec.test',
                'phone' => '+351961000012',
                'hours' => 'weekdays',
                'social' => [
                    'LinkedIn' => 'https://www.linkedin.com/company/saber-mais-formacao',
                    'Facebook' => 'https://www.facebook.com/sabermais.formacao',
                ],
                'portfolio' => 0,
                'profile' => [
                    'name' => 'Rita Gonçalves',
                    'business_name' => 'Saber Mais Centro de Formação',
                    'sector' => 'Educação e Formação',
                    'location' => 'Porto',
                    'description' => 'Centro de formação profissional certificado. Cursos de gestão, línguas e informática, em sala e à distância, com planos de formação à medida para empresas.',
                    'website_url' => 'https://www.sabermais-formacao.pt',
                    'commercial_email' => 'inscricoes@sabermais-formacao.pt',
                    'commercial_phone' => '+351225000112',
                    'address' => 'Rua de Santa Catarina 500',
                    'postal_code' => '4000-446',
                    'city' => 'Porto',
                    'congregation' => 'Igreja Evangélica do Bonfim',
                    'role_in_congregation' => 'Professora da escola bíblica',
                ],
            ],
            [
                'email' => 'demo.membro11@aspec.test',
                'phone' => '+351964000013',
                'hours' => null,
                'social' => [
                    'Instagram' => 'https://www.instagram.com/quintavaleverde.evora',
                    'Facebook' => 'https://www.facebook.com/quintavaleverde.evora',
                ],
                'portfolio' => 3,
                'profile' => [
                    'name' => 'Bruno Fernandes',
                    'business_name' => 'Quinta do Vale Verde',
                    'sector' => 'Agricultura e Pescas',
                    'location' => 'Évora',
                    'description' => 'Produção de azeite, vinho e hortícolas em modo biológico. Venda direta na quinta, cabazes semanais e visitas para grupos.',
                    'website_url' => 'https://www.quintavaleverde-evora.pt',
                    'commercial_email' => 'encomendas@quintavaleverde-evora.pt',
                    'commercial_phone' => '+351266000113',
                    'address' => 'Estrada de Arraiolos, km 4',
                    'postal_code' => '7000-171',
                    'city' => 'Évora',
                    'congregation' => 'Igreja Evangélica de Évora',
                    'role_in_congregation' => 'Membro',
                ],
            ],
        ];
    }

    /**
     * Contas fora do diretório (para o backoffice): candidaturas pendentes, aprovada à espera de
     * ativação, inativa por falta de pagamento e bloqueada. Perfis mais simples, sem imagens.
     *
     * @return list<array<string, mixed>>
     */
    private function applicants(): array
    {
        $applicant = fn (string $email, string $phone, string $status, ?InactiveReason $reason, array $profile) => [
            'email' => $email,
            'phone' => $phone,
            'status' => $status,
            'reason' => $reason,
            'profile' => $profile,
        ];

        return [
            $applicant('demo.pendente01@aspec.test', '+351912000101', 'Pending', null, [
                'name' => 'Luís Teixeira',
                'business_name' => 'Teixeira Eletricidade',
                'sector' => 'Engenharia e Indústria',
                'location' => 'Vila Real',
                'description' => 'Instalações elétricas, certificação energética e manutenção industrial.',
                'commercial_email' => 'geral@teixeira-eletricidade.pt',
                'commercial_phone' => '+351259000101',
                'address' => 'Avenida Carvalho Araújo 30',
                'postal_code' => '5000-657',
                'city' => 'Vila Real',
                'congregation' => 'Igreja Evangélica de Vila Real',
                'role_in_congregation' => 'Membro',
            ]),
            $applicant('demo.pendente02@aspec.test', '+351913000102', 'Pending', null, [
                'name' => 'Joana Moreira',
                'business_name' => 'Essência Natural Estética',
                'sector' => 'Estética e Bem-Estar',
                'location' => 'Viana do Castelo',
                'description' => 'Tratamentos de estética, massagens e cuidados de pele.',
                'commercial_email' => 'marcacoes@essencianatural.pt',
                'commercial_phone' => '+351258000102',
                'address' => 'Rua da Bandeira 120',
                'postal_code' => '4900-560',
                'city' => 'Viana do Castelo',
                'congregation' => 'Comunidade Cristã de Viana',
                'role_in_congregation' => 'Membro do coro',
            ]),
            $applicant('demo.pendente03@aspec.test', '+351916000103', 'Pending', null, [
                'name' => 'André Pinto',
                'business_name' => 'Caminhos do Douro Turismo',
                'sector' => 'Turismo e Lazer',
                'location' => 'Bragança',
                'description' => 'Passeios guiados e alojamento local no Nordeste Transmontano.',
                'commercial_email' => 'reservas@caminhosdodouro.pt',
                'commercial_phone' => '+351273000103',
                'address' => 'Rua Almirante Reis 45',
                'postal_code' => '5300-033',
                'city' => 'Bragança',
                'congregation' => 'Igreja Batista de Bragança',
                'role_in_congregation' => 'Membro',
            ]),
            $applicant('demo.aprovado@aspec.test', '+351919000104', 'Approved', null, [
                'name' => 'Helena Marques',
                'business_name' => 'Marques Comércio de Papelaria',
                'sector' => 'Comércio Retalhista',
                'location' => 'Castelo Branco',
                'description' => 'Papelaria, material de escritório e consumíveis para empresas.',
                'commercial_email' => 'encomendas@marquespapelaria.pt',
                'commercial_phone' => '+351272000104',
                'address' => 'Avenida 1.º de Maio 18',
                'postal_code' => '6000-086',
                'city' => 'Castelo Branco',
                'congregation' => 'Igreja Evangélica de Castelo Branco',
                'role_in_congregation' => 'Membro',
            ]),
            $applicant('demo.inativo.pagamento@aspec.test', '+351921000105', 'Inactive', InactiveReason::Unpaid, [
                'name' => 'Paulo Correia',
                'business_name' => 'Correia Mediação de Seguros',
                'sector' => 'Contabilidade e Finanças',
                'location' => 'Guarda',
                'description' => 'Seguros para particulares e empresas.',
                'commercial_email' => 'seguros@correiamediacao.pt',
                'commercial_phone' => '+351271000105',
                'address' => 'Rua do Comércio 22',
                'postal_code' => '6300-680',
                'city' => 'Guarda',
                'congregation' => 'Comunidade Cristã da Guarda',
                'role_in_congregation' => 'Membro',
            ]),
            $applicant('demo.bloqueado@aspec.test', '+351924000106', 'Inactive', InactiveReason::Blocked, [
                'name' => 'Vítor Santos',
                'business_name' => 'Santos Reparações Auto',
                'sector' => 'Transportes e Logística',
                'location' => 'Portalegre',
                'description' => 'Oficina de mecânica geral e pneus.',
                'commercial_email' => 'oficina@santosreparacoes.pt',
                'commercial_phone' => '+351245000106',
                'address' => 'Rua 19 de Junho 8',
                'postal_code' => '7300-155',
                'city' => 'Portalegre',
                'congregation' => 'Igreja Evangélica de Portalegre',
                'role_in_congregation' => 'Membro',
            ]),
        ];
    }

    /**
     * Dezasseis eventos: onze presenciais futuros (o pequeno-almoço e o jantar repetem-se em várias
     * cidades, um esgotado e outro quase), três online e dois já passados. Os online cobrem os três
     * casos do link: inscrições abertas (link ainda escondido), prazo terminado com o demo.membro
     * inscrito (vê o link) e prazo terminado sem ele (não vê). Coordenadas no centro de cada cidade.
     * Os eventos online também têm distrito porque o backoffice o exige; usam a sede (Lisboa).
     *
     * Cartões de oração em dois eventos: a formação de Aveiro é hoje (já começou, por isso não aparece
     * na agenda pública), com o prazo do cartão aberto, o demo.membro inscrito sem cartão e dois
     * cartões já enviados; o pequeno-almoço de Leiria (passado)
     * já foi distribuído e o demo.membro recebeu o cartão do demo.membro02.
     *
     * Os presenciais têm o place_id da Google da morada (obtido uma vez com o GoogleGeocoder; os
     * termos da Google permitem guardá-lo), para o mapa Google da página do evento. As coordenadas
     * são nossas, não da Google, e ficam para o mapa antigo enquanto o frontend não usa o place_id.
     *
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        $breakfast = 'Pequeno-almoço de Negócios ASPEC';
        $breakfastDescription = 'Um pequeno-almoço para conhecer outros empresários da região, apresentar o seu negócio em dois minutos e criar parcerias.';
        $dinner = 'Jantar de Networking ASPEC';
        $dinnerDescription = 'Jantar informal entre membros e convidados, com um breve testemunho empresarial e tempo livre para conversar.';
        $dinnerNotes = 'Custo de participação: 25 € (pago no local).';
        $demoMember = 'demo.membro@aspec.test';
        [$todayStart, $todayEnd] = $this->todayEventTimes();

        return [
            [
                'name' => $breakfast, 'category' => 'Pequeno-almoço', 'location' => 'Lisboa',
                'days_from_now' => 5, 'start' => '08:30', 'end' => '10:30', 'max_attendees' => 40,
                'description' => $breakfastDescription,
                'venue' => 'Hotel Tivoli Oriente',
                'address' => 'Avenida D. João II 27, 1990-083 Lisboa',
                'place_id' => 'ChIJJ8bvTYYxGQ0RoC1RSUhyz2k',
                'latitude' => 38.7676, 'longitude' => -9.0973,
                'registrants' => [$demoMember, ...$this->demoMembers(1, 6)],
            ],
            [
                'name' => $breakfast, 'category' => 'Pequeno-almoço', 'location' => 'Porto',
                'days_from_now' => 12, 'start' => '08:30', 'end' => '10:30', 'max_attendees' => 30,
                'description' => $breakfastDescription,
                'address' => 'Salão de eventos, Avenida dos Aliados 120, 4000-065 Porto',
                'place_id' => 'Ei1Bdi4gZG9zIEFsaWFkb3MgMTIwLCA0MDAwLTA2NyBQb3J0bywgUG9ydHVnYWwiMBIuChQKEgkLQ3Be42QkDREzxCX1feNMXxB4KhQKEgmP_UBZ42QkDREQ0m1U5syNcw',
                'latitude' => 41.1486, 'longitude' => -8.6110,
                'registrants' => $this->demoMembers(7, 9),
            ],
            [
                'name' => $breakfast, 'category' => 'Pequeno-almoço', 'location' => 'Braga',
                'days_from_now' => 19, 'start' => '08:30', 'end' => '10:30', 'max_attendees' => 12,
                'description' => $breakfastDescription,
                'address' => 'Salão de eventos, Avenida Central 40, 4710-228 Braga',
                'place_id' => 'ChIJz2iE28H-JA0R2oEkaaewzME',
                'latitude' => 41.5512, 'longitude' => -8.4229,
                'registrants' => $this->demoMembers(1, 11),
            ],
            [
                'name' => $dinner, 'category' => 'Jantar', 'location' => 'Lisboa',
                'days_from_now' => 9, 'start' => '20:00', 'end' => '23:00', 'max_attendees' => 80,
                'description' => $dinnerDescription,
                'address' => 'Restaurante (sala privada), Rua Castilho 149, 1070-051 Lisboa',
                'place_id' => 'ChIJr7Xg63EzGQ0Rm8LgeMFJfpQ',
                'latitude' => 38.7290, 'longitude' => -9.1560,
                'notes' => $dinnerNotes,
                'registrants' => [$demoMember, ...$this->demoMembers(2, 8)],
            ],
            [
                'name' => $dinner, 'category' => 'Jantar', 'location' => 'Coimbra',
                'days_from_now' => 23, 'start' => '20:00', 'end' => '23:00', 'max_attendees' => 50,
                'description' => $dinnerDescription,
                'address' => 'Restaurante (sala privada), Rua da Sofia 120, 3000-389 Coimbra',
                'place_id' => 'EixSdWEgZGEgU29maWEgMTIwLCAzMDAwLTM5MCBDb2ltYnJhLCBQb3J0dWdhbCIwEi4KFAoSCQffjZ8F-SINEbdWcj5j3bFVEHgqFAoSCQtDsAoF-SINEY0Z8kdZQA8q',
                'latitude' => 40.2115, 'longitude' => -8.4290,
                'notes' => $dinnerNotes,
                'registrants' => $this->demoMembers(4, 5),
            ],
            [
                'name' => $dinner, 'category' => 'Jantar', 'location' => 'Faro',
                'days_from_now' => 37, 'start' => '20:00', 'end' => '23:00', 'max_attendees' => 40,
                'description' => $dinnerDescription,
                'address' => 'Salão de eventos, Praça D. Francisco Gomes 2, 8000-168 Faro',
                'place_id' => 'ChIJ9y1z1sZSBQ0RS0zUXIu14FA',
                'latitude' => 37.0153, 'longitude' => -7.9352,
            ],
            [
                'name' => 'Almoço de Negócios ASPEC', 'category' => 'Almoço', 'location' => 'Viseu',
                'days_from_now' => 33, 'start' => '12:30', 'end' => '14:30', 'max_attendees' => 40,
                'description' => 'Almoço entre empresários da região, com uma breve apresentação de cada participante e tempo para conversar e criar parcerias.',
                'address' => 'Restaurante (sala privada), Rua Formosa 50, 3500-135 Viseu',
                'place_id' => 'ChIJ-VK4d1I2Iw0RBstTicF8-28',
                'latitude' => 40.6566, 'longitude' => -7.9125,
                'notes' => 'Custo de participação: 18 € (pago no local).',
                'registrants' => $this->demoMembers(10, 11),
            ],
            [
                'name' => 'Formação: Finanças para Pequenas Empresas', 'category' => 'Formação', 'location' => 'Aveiro',
                'days_from_now' => 0, 'start' => $todayStart, 'end' => $todayEnd, 'max_attendees' => 30,
                'description' => 'Tesouraria, margens e preços: como ler os números do seu negócio e tomar melhores decisões. Formação prática com exercícios.',
                'venue' => 'Centro de Congressos de Aveiro',
                'address' => 'Cais da Fonte Nova, 3810-200 Aveiro',
                'place_id' => 'Ei1DYWlzIGRhIEZvbnRlIE5vdmEsIDM4MTAtMjAwIEF2ZWlybywgUG9ydHVnYWwiLiosChQKEgm_8TFeCZgjDREuH0xpIAU3QxIUChIJxdSxZAmYIw0R6bcGCHI5Zj8',
                'latitude' => 40.6380, 'longitude' => -8.6440,
                'registrants' => [$demoMember, 'demo.membro01@aspec.test', 'demo.membro03@aspec.test', 'demo.membro09@aspec.test'],
                'prayer_verse' => 'Levai as cargas uns dos outros e assim cumprireis a lei de Cristo. (Gálatas 6:2)',
                'prayer_cards' => ['authors' => ['demo.membro01@aspec.test', 'demo.membro03@aspec.test']],
            ],
            [
                'name' => 'Formação: Marketing Digital para Negócios Locais', 'category' => 'Formação', 'location' => 'Leiria',
                'days_from_now' => 29, 'start' => '14:00', 'end' => '18:00', 'max_attendees' => 25,
                'description' => 'Como usar as redes sociais e o Google para atrair clientes da sua zona, com um plano simples para aplicar no dia seguinte.',
                'address' => 'Auditório, Rua Barão de Viamonte 20, 2400-262 Leiria',
                'place_id' => 'ChIJLfCNbWxzIg0RRw7VWELDhHQ',
                'latitude' => 39.7445, 'longitude' => -8.8070,
                'notes' => 'Traga o seu portátil. Custo de participação: 15 € (pago no local).',
            ],
            [
                'name' => 'Formação: Liderança com Valores', 'category' => 'Formação', 'location' => 'Setúbal',
                'days_from_now' => 44, 'start' => '14:00', 'end' => '18:00', 'max_attendees' => 35,
                'description' => 'Liderar equipas com ética, confiança e propósito. Casos práticos de empresários portugueses e partilha entre participantes.',
                'venue' => 'Fórum Municipal Luísa Todi',
                'address' => 'Avenida Luísa Todi 65, 2900-461 Setúbal',
                'place_id' => 'ChIJv0-wxQdDGQ0RypZhgbJrUks',
                'latitude' => 38.5225, 'longitude' => -8.8920,
                'registrants' => $this->demoMembers(6, 6),
            ],
            [
                'name' => 'Workshop: Apresente o seu Negócio em Dois Minutos', 'category' => 'Workshop', 'location' => 'Porto',
                'days_from_now' => 26, 'start' => '18:30', 'end' => '20:30', 'max_attendees' => 10,
                'description' => 'Sessão prática para preparar e treinar a apresentação do seu negócio, com exercícios em grupo e comentários dos outros participantes. Grupo pequeno para todos treinarem.',
                'address' => 'Sala de Conferências, Rua de Júlio Dinis 750, 4050-012 Porto',
                'place_id' => 'EjFSdWEgZGUgSsO6bGlvIERpbmlzIDc1MCwgNDA1MC0wMTIgUG9ydG8sIFBvcnR1Z2FsIjESLwoUChIJJ9OqgQhlJA0Rd8tz-E9VUKEQ7gUqFAoSCT-ZVf4FZSQNEQM0UeGusGGV',
                'latitude' => 41.1580, 'longitude' => -8.6290,
                'registrants' => $this->demoMembers(1, 10),
            ],
            [
                'name' => 'Webinar: Faturação Eletrónica sem Complicações', 'category' => 'Formação', 'location' => 'Lisboa',
                'days_from_now' => 10, 'start' => '18:00', 'end' => '19:30', 'max_attendees' => 100,
                'description' => 'Sessão online sobre as regras da faturação eletrónica, o QR code e o ATCUD nas faturas, com tempo para perguntas no fim.',
                'online_url' => 'https://meet.example.test/aspec-faturacao-eletronica',
                'registrants' => $this->demoMembers(9, 10),
            ],
            [
                'name' => 'Workshop Online: Vender Mais nas Redes Sociais', 'category' => 'Workshop', 'location' => 'Lisboa',
                'days_from_now' => 3, 'deadline_days_from_now' => -1, 'start' => '18:30', 'end' => '20:00', 'max_attendees' => 50,
                'description' => 'Workshop online com exemplos reais de publicações que trazem clientes e um calendário simples para o próximo mês.',
                'online_url' => 'https://meet.example.test/aspec-redes-sociais',
                'notes' => 'O link da sessão aparece na página do evento depois do fecho das inscrições.',
                'registrants' => [$demoMember, ...$this->demoMembers(1, 4)],
            ],
            [
                'name' => 'Formação Online: RGPD para Pequenas Empresas', 'category' => 'Formação', 'location' => 'Lisboa',
                'days_from_now' => 4, 'deadline_days_from_now' => -1, 'start' => '14:00', 'end' => '17:00', 'max_attendees' => 60,
                'description' => 'O que uma pequena empresa tem de fazer para cumprir o RGPD: consentimentos, registos de tratamento e pedidos dos clientes.',
                'online_url' => 'https://meet.example.test/aspec-rgpd',
                'registrants' => $this->demoMembers(5, 8),
            ],
            [
                'name' => $breakfast, 'category' => 'Pequeno-almoço', 'location' => 'Leiria',
                'days_from_now' => -14, 'start' => '08:30', 'end' => '10:30', 'max_attendees' => 30,
                'description' => $breakfastDescription,
                'address' => 'Salão de eventos, Largo Cândido dos Reis 10, 2400-112 Leiria',
                'place_id' => 'ChIJ0aliKvRzIg0R30hY7E0CvTE',
                'latitude' => 39.7436, 'longitude' => -8.8071,
                'registrants' => [$demoMember, 'demo.membro02@aspec.test', 'demo.membro05@aspec.test', 'demo.membro07@aspec.test', 'demo.membro11@aspec.test'],
                'prayer_verse' => 'Entrega o teu caminho ao Senhor, confia nele, e ele tudo fará. (Salmo 37:5)',
                'prayer_cards' => [
                    'authors' => [$demoMember, 'demo.membro02@aspec.test', 'demo.membro05@aspec.test', 'demo.membro07@aspec.test'],
                    'distributed' => true,
                ],
            ],
            [
                'name' => $dinner, 'category' => 'Jantar', 'location' => 'Aveiro',
                'days_from_now' => -30, 'start' => '20:00', 'end' => '23:00', 'max_attendees' => 40,
                'description' => $dinnerDescription,
                'address' => 'Restaurante (sala privada), Rua João Mendonça 25, 3800-200 Aveiro',
                'place_id' => 'ChIJNbMUggOYIw0RZ_7oCGcXLLo',
                'latitude' => 40.6412, 'longitude' => -8.6537,
                'notes' => $dinnerNotes,
                'registrants' => [$demoMember, 'demo.membro01@aspec.test', 'demo.membro03@aspec.test', 'demo.membro04@aspec.test', 'demo.membro06@aspec.test', 'demo.membro08@aspec.test', 'demo.membro10@aspec.test'],
            ],
        ];
    }

    /**
     * Avaliações de demonstração [autor, avaliado, estrelas, estado, comentário], sem autoavaliações nem pares repetidos.
     * Os autores são sempre membros (role Member), como exige o POST de avaliações.
     * Médias visíveis: Contas Certas 4,8 e Ferreira 4,7 (aparecem em "4,5+"), Sabores do Algarve 4,3 (só "4+"),
     * Carvalho 3,5 e Clínica Bem Cuidar 3,3 (só "3+"). A Hidden e a Flagged não contam para a média.
     *
     * @return list<array{string, string, int, string, string}>
     */
    private function reviews(): array
    {
        $member = fn (int $number) => sprintf('demo.membro%02d@aspec.test', $number);
        $demoMember = 'demo.membro@aspec.test';

        return [
            [$member(2), $demoMember, 5, 'Visible', 'Projeto de remodelação do nosso escritório impecável, cumpriram prazos e orçamento.'],
            [$member(5), $demoMember, 5, 'Visible', 'Muito profissionais, ouviram as nossas ideias e melhoraram-nas.'],
            [$member(7), $demoMember, 4, 'Visible', 'Bom trabalho de interiores; a obra atrasou uma semana, mas avisaram com tempo.'],

            [$demoMember, $member(2), 5, 'Visible', 'Contabilidade sempre em dia e respostas rápidas às dúvidas.'],
            [$member(3), $member(2), 5, 'Visible', 'Ajudaram-nos a organizar as contas do restaurante desde o primeiro mês.'],
            [$member(4), $member(2), 5, 'Visible', 'Recomendo: explicam tudo com clareza e sem termos técnicos.'],
            [$member(8), $member(2), 4, 'Visible', 'Serviço fiável, por vezes demoram um pouco a responder por email.'],

            [$demoMember, $member(3), 4, 'Visible', 'Excelente jantar de equipa, peixe muito fresco.'],
            [$member(7), $member(3), 4, 'Visible', 'Boa comida e atendimento simpático; ao fim de semana convém reservar.'],
            [$member(9), $member(3), 5, 'Visible', 'Cataplana memorável, voltaremos com clientes.'],

            [$member(6), $member(1), 4, 'Visible', 'Obra bem executada e equipa organizada.'],
            [$member(10), $member(1), 3, 'Visible', 'Trabalho correto, mas a comunicação durante a obra podia ser melhor.'],
            [$member(11), $member(1), 1, 'Flagged', 'Nunca mais! Bando de aldrabões, fujam desta empresa!'],

            [$member(1), $member(6), 3, 'Visible', 'Atendimento simpático, mas esperei bastante para a consulta.'],
            [$member(4), $member(6), 4, 'Visible', 'Clínica limpa e profissionais atenciosos.'],
            [$member(9), $member(6), 3, 'Visible', 'Razoável; a marcação por telefone é difícil.'],
            [$member(11), $member(6), 1, 'Hidden', 'Péssimos, só querem dinheiro. Não percam tempo com esta gente.'],
        ];
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
        $now = now(config('app.event_timezone', 'Europe/Lisbon'));
        $start = $now->copy()->subHour()->startOfHour();

        if (! $start->isSameDay($now)) {
            $start = $now->copy()->startOfDay();
        }

        $end = $start->copy()->addHours(2);

        return [$start->format('H:i'), $end->isSameDay($start) ? $end->format('H:i') : '23:59'];
    }

    /**
     * Emails dos membros Active numerados da demonstração (ex.: 1 a 3 → demo.membro01 a demo.membro03).
     *
     * @return list<string>
     */
    private function demoMembers(int $from, int $to): array
    {
        return array_map(fn (int $number) => sprintf('demo.membro%02d@aspec.test', $number), range($from, $to));
    }
}
