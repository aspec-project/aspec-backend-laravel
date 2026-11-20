<?php

namespace Tests\Feature\Seeders;

use App\Enums\InactiveReason;
use App\Enums\SubscriptionState;
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
use App\Models\Sector;
use App\Models\User;
use App\Rules\InternationalPhone;
use App\Rules\PortuguesePostalCode;
use App\Services\Reviews\ReviewContentModerator;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Laravel\Cashier\Subscription;
use Laravel\Cashier\SubscriptionItem;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsDemoData;
use Tests\TestCase;

/**
 * Seeder de dados de demonstração (php artisan db:seed --class=DemoSeeder): contas, perfis do
 * diretório, textos, contactos, avaliações, proteções e repetição. Eventos, pagamentos e imagens
 * estão nas classes DemoSeederEventsTest, DemoSeederPaymentsTest e DemoSeederMediaTest.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase, SeedsDemoData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDemoSeeder();
    }

    /**
     * Os 19 logins usados no README, nos guiões de teste e na mensagem ao professor mantêm o papel,
     * o estado e o motivo.
     */
    #[Test]
    public function keeps_the_nineteen_existing_logins_with_the_same_role_status_and_reason(): void
    {
        $this->runDemoSeeder();

        $expected = [
            'demo.admin@aspec.test' => ['Admin', 'Active', null],
            'demo.membro@aspec.test' => ['Member', 'Active', null],
            'demo.pendente01@aspec.test' => ['Member', 'Pending', null],
            'demo.pendente02@aspec.test' => ['Member', 'Pending', null],
            'demo.pendente03@aspec.test' => ['Member', 'Pending', null],
            'demo.aprovado@aspec.test' => ['Member', 'Approved', null],
            'demo.inativo.pagamento@aspec.test' => ['Member', 'Inactive', InactiveReason::Unpaid],
            'demo.bloqueado@aspec.test' => ['Member', 'Inactive', InactiveReason::Blocked],
        ];

        foreach (range(1, 11) as $number) {
            $expected[sprintf('demo.membro%02d@aspec.test', $number)] = ['Member', 'Active', null];
        }

        foreach ($expected as $email => [$role, $status, $reason]) {
            $user = $this->demoUser($email);

            $this->assertSame($role, $user->role->name, $email);
            $this->assertSame($status, $user->accountStatus->name, $email);
            $this->assertSame($reason, $user->inactive_reason, $email);
            $this->assertNotNull($user->email_verified_at, $email);
            $this->assertNotNull($user->memberProfile, $email);
        }
    }

    #[Test]
    public function demo_member_can_log_in_with_the_demo_password(): void
    {
        $this->runDemoSeeder();

        $this->postJson('/api/auth/token', [
            'email' => 'demo.membro@aspec.test',
            'password' => DemoSeeder::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('Demo#2026', DemoSeeder::PASSWORD);
    }

    /**
     * Volumes por estado da conta: 60 perfis no diretório, 7 candidaturas, 2 aprovadas e 4 inativas
     * (2 por falta de pagamento, 1 cancelada pelo membro e 1 bloqueada).
     */
    #[Test]
    public function creates_the_target_number_of_accounts_in_each_status(): void
    {
        $this->runDemoSeeder();

        $users = $this->demoUsers();

        $this->assertSame(60, $users->filter(fn (User $user) => $user->accountStatus->name === 'Active')->count());
        $this->assertSame(7, $users->filter(fn (User $user) => $user->accountStatus->name === 'Pending')->count());
        $this->assertSame(2, $users->filter(fn (User $user) => $user->accountStatus->name === 'Approved')->count());

        $inactive = $users->filter(fn (User $user) => $user->accountStatus->name === 'Inactive');
        $this->assertSame(2, $inactive->where('inactive_reason', InactiveReason::Unpaid)->count());
        $this->assertSame(1, $inactive->where('inactive_reason', InactiveReason::Cancelled)->count());
        $this->assertSame(1, $inactive->where('inactive_reason', InactiveReason::Blocked)->count());
        $this->assertCount(4, $inactive);

        foreach ($users as $user) {
            $this->assertNotNull($user->memberProfile, $user->email);
            $this->assertNotNull($user->email_verified_at, $user->email);
        }
    }

    /**
     * O admin da demo tem perfil e paga como os outros administradores: só o aspec:create-admin
     * cria o administrador técnico isento, e o seeder nunca escreve essa marca.
     */
    #[Test]
    public function demo_admin_is_not_subscription_exempt_and_shows_as_active(): void
    {
        $this->runDemoSeeder();

        $admin = $this->demoUser('demo.admin@aspec.test');

        $this->assertFalse($admin->subscription_exempt);
        $this->assertSame(SubscriptionState::Active, $admin->subscriptionState());
        $this->assertSame(0, User::where('subscription_exempt', true)->count());
    }

    #[Test]
    public function every_account_email_is_on_the_reserved_aspec_test_domain(): void
    {
        $this->runDemoSeeder();

        $this->assertSame(0, User::where('email', 'not like', '%@aspec.test')->count());
        $this->assertGreaterThanOrEqual(73, User::where('email', 'like', 'demo.%@aspec.test')->count());
    }

    #[Test]
    public function stores_every_phone_in_e164_with_at_least_two_foreign_members(): void
    {
        $this->runDemoSeeder();

        $phones = $this->demoUsers()->pluck('phone');

        foreach ($phones as $phone) {
            $this->assertMatchesRegularExpression('/^\+\d{8,15}$/', $phone);
            $this->assertSame($phone, InternationalPhone::normalize($phone));
        }

        $this->assertGreaterThanOrEqual(2, $phones->reject(fn (string $phone) => str_starts_with($phone, '+351'))->count());
    }

    /**
     * O diretório público tem 60 perfis (5 páginas de 12) e cobre todos os setores e distritos.
     */
    #[Test]
    public function directory_lists_sixty_profiles_covering_every_sector_and_district(): void
    {
        $this->runDemoSeeder();

        $this->getJson('/api/members')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 60)
            ->assertJsonPath('data.pagination.last_page', 5);

        $items = $this->directoryItems();

        $this->assertCount(60, $items);
        $this->assertEqualsCanonicalizing(Sector::pluck('name')->all(), $items->pluck('sector.name')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(Location::pluck('name')->all(), $items->pluck('location.name')->unique()->values()->all());
        $this->assertSame(17, Sector::count());
        $this->assertSame(20, Location::count());
    }

    /**
     * Distribuição plausível: nenhum setor com mais de 6 perfis nem menos de 2, e Lisboa e Porto
     * com mais perfis do que qualquer outro distrito.
     */
    #[Test]
    public function profiles_are_spread_across_sectors_and_districts_like_a_real_association(): void
    {
        $this->runDemoSeeder();

        $profiles = $this->directoryProfiles();
        $perSector = $profiles->countBy(fn (MemberProfile $profile) => $profile->sector->name);
        $perDistrict = $profiles->countBy(fn (MemberProfile $profile) => $profile->location->name);

        foreach ($perSector as $sector => $count) {
            $this->assertGreaterThanOrEqual(2, $count, $sector);
            $this->assertLessThanOrEqual(6, $count, $sector);
        }

        $others = $perDistrict->except(['Lisboa', 'Porto']);
        $this->assertGreaterThan($others->max(), $perDistrict['Lisboa']);
        $this->assertGreaterThan($others->max(), $perDistrict['Porto']);
    }

    #[Test]
    public function every_directory_profile_has_the_fields_the_public_profile_shows(): void
    {
        $this->runDemoSeeder();

        foreach ($this->directoryProfiles() as $profile) {
            foreach (['name', 'business_name', 'description', 'commercial_email', 'commercial_phone', 'address', 'postal_code', 'city', 'congregation', 'role_in_congregation'] as $field) {
                $this->assertNotEmpty($profile->{$field}, "{$profile->business_name}: {$field}");
            }
        }
    }

    #[Test]
    public function business_names_are_unique(): void
    {
        $this->runDemoSeeder();

        $names = $this->demoProfiles()->pluck('business_name');

        $this->assertSame($names->count(), $names->unique()->count(), 'Nomes de empresas repetidos: '.$names->duplicates()->implode(', '));
    }

    /**
     * Descrições escritas à mão: 2 parágrafos (o perfil público mostra-os com whitespace-pre-wrap),
     * entre 450 e 1000 carateres (máximo da validação) e todas diferentes.
     */
    #[Test]
    public function descriptions_have_two_paragraphs_a_plausible_length_and_are_all_different(): void
    {
        $this->runDemoSeeder();

        $profiles = $this->directoryProfiles();

        foreach ($profiles as $profile) {
            $description = $profile->description;
            $paragraphs = preg_split('/\R\s*\R/u', trim($description));

            $this->assertGreaterThanOrEqual(450, mb_strlen($description), $profile->business_name);
            $this->assertLessThanOrEqual(1000, mb_strlen($description), $profile->business_name);
            $this->assertCount(2, $paragraphs, "{$profile->business_name}: parágrafos");
        }

        $descriptions = $this->demoProfiles()->pluck('description');
        $this->assertSame($descriptions->count(), $descriptions->unique()->count());
    }

    #[Test]
    public function descriptions_avoid_repeated_marketing_cliches(): void
    {
        $this->runDemoSeeder();

        foreach ($this->demoProfiles() as $profile) {
            foreach (['líder de mercado', 'empresa de referência'] as $cliche) {
                $this->assertStringNotContainsString($cliche, mb_strtolower($profile->description), $profile->business_name);
            }
        }
    }

    /**
     * Empresas, pessoas e locais são fictícios: nenhuma marca real nos nomes, nas descrições nem
     * nos comentários das avaliações.
     */
    #[Test]
    public function profiles_and_reviews_mention_no_real_brand(): void
    {
        $this->runDemoSeeder();

        foreach ($this->demoProfiles() as $profile) {
            $this->assertNoForbiddenBrand($profile->business_name, $profile->business_name);
            $this->assertNoForbiddenBrand($profile->description, "{$profile->business_name}: descrição");
        }

        foreach (Review::all() as $review) {
            $this->assertNoForbiddenBrand($review->comment, "Avaliação {$review->id}");
        }
    }

    #[Test]
    public function every_demo_profile_has_street_postal_code_and_city_separated(): void
    {
        $this->runDemoSeeder();

        foreach ($this->demoProfiles() as $profile) {
            $validator = Validator::make(
                $profile->only(['postal_code', 'city']),
                [
                    'postal_code' => ['required', 'string', new PortuguesePostalCode],
                    'city' => 'required|string|max:100',
                ],
            );

            $this->assertFalse($validator->fails(), "{$profile->business_name}: ".$validator->errors()->toJson());
            $this->assertNotEmpty($profile->address, $profile->business_name);
            $this->assertStringNotContainsString($profile->postal_code, $profile->address, $profile->business_name);
        }
    }

    #[Test]
    public function every_demo_profile_has_valid_commercial_email_and_e164_phone(): void
    {
        $this->runDemoSeeder();

        foreach ($this->demoProfiles() as $profile) {
            $validator = Validator::make(
                $profile->only(['commercial_email', 'commercial_phone']),
                [
                    'commercial_email' => 'required|string|email:rfc|max:255',
                    'commercial_phone' => ['required', 'string', new InternationalPhone],
                ],
            );

            $this->assertFalse($validator->fails(), "{$profile->business_name}: ".$validator->errors()->toJson());
            $this->assertSame(InternationalPhone::normalize($profile->commercial_phone), $profile->commercial_phone, $profile->business_name);
        }
    }

    /**
     * Sites e emails comerciais em .test (domínio reservado, RFC 2606): um .pt inventado pode
     * existir e um clique na demonstração abriria o site de uma empresa real.
     */
    #[Test]
    public function websites_and_commercial_emails_use_the_reserved_test_domain(): void
    {
        $this->runDemoSeeder();

        $profiles = $this->demoProfiles();
        $this->assertGreaterThan(0, $profiles->whereNotNull('website_url')->count());

        foreach ($profiles as $profile) {
            $this->assertStringEndsWith('.test', strtolower(substr(strrchr($profile->commercial_email, '@'), 1)), $profile->business_name);

            if ($profile->website_url !== null) {
                $this->assertStringStartsWith('https://', $profile->website_url, $profile->business_name);
                $this->assertStringEndsWith('.test', strtolower((string) parse_url($profile->website_url, PHP_URL_HOST)), $profile->business_name);
            }
        }
    }

    /**
     * Redes sociais em cerca de 2/3 dos perfis do diretório, sempre no domínio da plataforma.
     */
    #[Test]
    public function about_two_thirds_of_the_directory_profiles_have_social_links(): void
    {
        $this->runDemoSeeder();

        $profiles = MemberProfile::listedInDirectory()->with('socialPlatforms')->get();
        $withLinks = $profiles->filter(fn (MemberProfile $profile) => $profile->socialPlatforms->isNotEmpty());

        $this->assertGreaterThanOrEqual(35, $withLinks->count());
        $this->assertLessThanOrEqual(45, $withLinks->count());

        foreach ($withLinks as $profile) {
            foreach ($profile->socialPlatforms as $platform) {
                $host = strtolower((string) parse_url($platform->pivot->url, PHP_URL_HOST));

                $this->assertStringStartsWith('https://', $platform->pivot->url, $profile->business_name);
                $this->assertStringContainsString(strtolower($platform->name), $host, "{$profile->business_name}: {$platform->name}");
            }
        }
    }

    /**
     * Cerca de 150 avaliações entre perfis de demonstração, sem autoavaliações nem pares repetidos.
     */
    #[Test]
    public function creates_about_one_hundred_and_fifty_reviews_without_self_reviews_or_repeated_pairs(): void
    {
        $this->runDemoSeeder();

        $reviews = Review::all();

        $this->assertGreaterThanOrEqual(130, $reviews->count());
        $this->assertLessThanOrEqual(170, $reviews->count());
        $this->assertSame(0, $reviews->filter(fn (Review $review) => $review->reviewer_profile_id === $review->reviewed_profile_id)->count());

        $pairs = $reviews->map(fn (Review $review) => $review->reviewer_profile_id.'|'.$review->reviewed_profile_id);
        $this->assertSame($pairs->count(), $pairs->unique()->count());

        foreach ($reviews as $review) {
            $this->assertGreaterThanOrEqual(1, $review->rating);
            $this->assertLessThanOrEqual(5, $review->rating);
            $this->assertNotEmpty($review->comment);
        }
    }

    /**
     * As avaliações visíveis passam no filtro de linguagem da aplicação: se o filtro as apanhasse,
     * uma avaliação igual feita pela API ficaria sinalizada.
     */
    #[Test]
    public function no_visible_review_is_caught_by_the_language_filter(): void
    {
        $this->runDemoSeeder();

        $moderator = app(ReviewContentModerator::class);
        $visible = Review::whereHas('status', fn ($query) => $query->where('name', 'Visible'))->get();

        $this->assertNotEmpty($visible);

        foreach ($visible as $review) {
            $this->assertFalse($moderator->containsFlaggedLanguage($review->comment), $review->comment);
        }
    }

    /**
     * Notas com a distribuição de uma associação real (a maioria de 5 estrelas, todas as notas
     * presentes) e médias entre 2,8 e 5,0, com pelo menos 10 perfis ainda sem avaliações.
     */
    #[Test]
    public function ratings_have_a_plausible_distribution_and_some_profiles_have_none(): void
    {
        $this->runDemoSeeder();

        $ratings = Review::whereHas('status', fn ($query) => $query->where('name', 'Visible'))->pluck('rating');
        $counts = $ratings->countBy();

        foreach (range(1, 5) as $stars) {
            $this->assertGreaterThan(0, $counts[$stars] ?? 0, "{$stars} estrelas");
        }

        $fiveShare = ($counts[5] ?? 0) / $ratings->count();
        $this->assertGreaterThanOrEqual(0.35, $fiveShare);
        $this->assertLessThanOrEqual(0.55, $fiveShare);

        $items = $this->directoryItems();
        $rated = $items->whereNotNull('average_rating');

        $this->assertGreaterThanOrEqual(10, $items->whereNull('average_rating')->count());
        $this->assertGreaterThanOrEqual(40, $rated->count());

        foreach ($rated as $item) {
            $this->assertGreaterThanOrEqual(2.8, $item['average_rating'], $item['business_name']);
            $this->assertLessThanOrEqual(5.0, $item['average_rating'], $item['business_name']);
        }
    }

    /**
     * Os filtros "3+", "4+" e "4,5+" do diretório dão resultados diferentes.
     */
    #[Test]
    public function rating_filters_give_different_results(): void
    {
        $this->runDemoSeeder();

        $total = fn (float $minRating) => $this->getJson("/api/members?min_rating={$minRating}")->assertOk()->json('data.pagination.total');

        $this->assertGreaterThan(0, $total(4.5));
        $this->assertGreaterThan($total(4.5), $total(4));
        $this->assertGreaterThan($total(4), $total(3));
    }

    /**
     * Avaliações espalhadas pelos últimos 6 meses, nunca no futuro.
     */
    #[Test]
    public function reviews_are_spread_over_the_last_six_months(): void
    {
        $this->runDemoSeeder();

        $dates = Review::pluck('created_at');

        foreach ($dates as $date) {
            $this->assertTrue($date->lessThanOrEqualTo(now()), (string) $date);
            $this->assertTrue($date->greaterThanOrEqualTo(now()->subDays(190)), (string) $date);
        }

        $this->assertGreaterThanOrEqual(5, $dates->map(fn ($date) => $date->format('Y-m'))->unique()->count());
    }

    /**
     * Exemplos de moderação: 5 sinalizadas (3 pelo filtro automático e 2 só por denúncia de um
     * membro), 4 ocultas com justificação e 2 denúncias já resolvidas em avaliações visíveis.
     */
    #[Test]
    public function moderation_examples_cover_automatic_and_member_flags_hidden_and_resolved_reports(): void
    {
        $this->runDemoSeeder();

        $moderator = app(ReviewContentModerator::class);
        $byStatus = fn (string $status) => Review::with('reports')->whereHas('status', fn ($query) => $query->where('name', $status))->get();

        $flagged = $byStatus('Flagged');
        $this->assertCount(5, $flagged);

        $automatic = $flagged->filter(fn (Review $review) => $review->reports->contains('source', ReviewReport::SOURCE_AUTOMATIC));
        $this->assertCount(3, $automatic);

        foreach ($automatic as $review) {
            $this->assertTrue($moderator->containsFlaggedLanguage($review->comment), $review->comment);
        }

        foreach ($flagged->diff($automatic) as $review) {
            $this->assertFalse($moderator->containsFlaggedLanguage($review->comment), $review->comment);
            $this->assertTrue($review->reports->contains('source', ReviewReport::SOURCE_MEMBER), $review->comment);
        }

        foreach ($flagged as $review) {
            $this->assertTrue($review->reports->contains(fn (ReviewReport $report) => $report->resolved_at === null), $review->comment);
        }

        $hidden = $byStatus('Hidden');
        $this->assertCount(4, $hidden);

        foreach ($hidden as $review) {
            $this->assertNotEmpty($review->hidden_reason, $review->comment);
        }

        $visibleWithReports = $byStatus('Visible')->filter(fn (Review $review) => $review->reports->isNotEmpty());
        $this->assertCount(2, $visibleWithReports);

        foreach ($visibleWithReports as $review) {
            foreach ($review->reports as $report) {
                $this->assertNotNull($report->resolved_at, $review->comment);
            }
        }
    }

    /**
     * O demo.membro mantém a avaliação oculta e a sinalizada recebidas: a sinalizada tem a
     * sinalização automática e a da própria empresa avaliada.
     */
    #[Test]
    public function demo_member_keeps_one_hidden_and_one_flagged_review_received(): void
    {
        $this->runDemoSeeder();

        $profileId = $this->demoUser('demo.membro@aspec.test')->memberProfile->id;
        $received = Review::with(['status', 'reports'])->where('reviewed_profile_id', $profileId)->get();

        $flagged = $received->filter(fn (Review $review) => $review->status->name === 'Flagged');
        $hidden = $received->filter(fn (Review $review) => $review->status->name === 'Hidden');

        $this->assertCount(1, $flagged);
        $this->assertCount(1, $hidden);

        $reports = $flagged->first()->reports;
        $this->assertEqualsCanonicalizing([ReviewReport::SOURCE_AUTOMATIC, ReviewReport::SOURCE_MEMBER], $reports->pluck('source')->all());

        $memberReport = $reports->firstWhere('source', ReviewReport::SOURCE_MEMBER);
        $this->assertSame($profileId, $memberReport->reporter_profile_id);
        $this->assertContains($memberReport->reason, ReviewReport::REASONS);
        $this->assertNotEmpty($memberReport->comment);
        $this->assertNotEmpty($hidden->first()->hidden_reason);
    }

    #[Test]
    public function admin_moderation_list_shows_where_the_flag_came_from(): void
    {
        $this->runDemoSeeder();
        Sanctum::actingAs($this->demoUser('demo.admin@aspec.test'));

        $items = collect($this->getJson('/api/admin/reviews?status=flagged&per_page=50')->assertOk()->json('data.items'));

        $flagged = $items->filter(fn (array $item) => $item['status'] === 'Flagged'
            && count(array_intersect([ReviewReport::SOURCE_AUTOMATIC, ReviewReport::SOURCE_MEMBER], $item['flagging_sources'])) === 2);

        $this->assertNotEmpty($flagged, 'Sem avaliação sinalizada pelo filtro e por um membro na lista de moderação');
    }

    /**
     * Avaliações moderadas ou apagadas à mão durante uma demonstração voltam ao estado original;
     * avaliações que não são entre perfis de demonstração ficam como estão.
     */
    #[Test]
    public function running_again_resets_demo_reviews_without_touching_other_reviews(): void
    {
        $this->runDemoSeeder();

        $demoCount = Review::count();
        $demoMemberProfileId = $this->demoUser('demo.membro@aspec.test')->memberProfile->id;
        $receivedCount = Review::where('reviewed_profile_id', $demoMemberProfileId)->count();

        Review::where('reviewed_profile_id', $demoMemberProfileId)->delete();
        $other = Review::factory()->create(['reviewed_profile_id' => $demoMemberProfileId]);

        $this->runDemoSeeder();

        $this->assertSame($demoCount + 1, Review::count());
        $this->assertModelExists($other);
        $this->assertSame($receivedCount + 1, Review::where('reviewed_profile_id', $demoMemberProfileId)->count());
    }

    #[Test]
    public function running_again_does_not_duplicate_review_reports(): void
    {
        $this->runDemoSeeder();
        $reports = ReviewReport::count();

        $this->runDemoSeeder();

        $this->assertGreaterThan(0, $reports);
        $this->assertSame($reports, ReviewReport::count());
    }

    /**
     * A agenda semanal só sai uma vez por semana a cada conta; repetir a demonstração tem de
     * permitir enviá-la de novo às contas demo, sem mexer nas outras contas.
     */
    #[Test]
    public function running_again_clears_the_weekly_agenda_mark_of_demo_accounts_only(): void
    {
        $this->runDemoSeeder();

        $otherUser = User::factory()->create(['weekly_agenda_sent_at' => now()]);
        User::where('email', 'like', 'demo.%@aspec.test')->update(['weekly_agenda_sent_at' => now()]);

        $this->runDemoSeeder();

        $this->assertSame(0, User::where('email', 'like', 'demo.%@aspec.test')->whereNotNull('weekly_agenda_sent_at')->count());
        $this->assertNotNull($otherUser->fresh()->weekly_agenda_sent_at);
    }

    /**
     * As contas demo têm emails .test: com os emails de eventos ligados, um evento criado no deploy
     * mandaria dezenas de emails que voltam para trás e gastam a quota diária do serviço de email.
     */
    #[Test]
    public function demo_accounts_have_event_emails_turned_off(): void
    {
        $this->runDemoSeeder();

        User::where('email', 'like', 'demo.%@aspec.test')->update(['notify_new_events' => true, 'notify_weekly_agenda' => true]);

        $this->runDemoSeeder();

        $demoUsers = User::where('email', 'like', 'demo.%@aspec.test');

        $this->assertGreaterThanOrEqual(73, (clone $demoUsers)->count());
        $this->assertSame(0, (clone $demoUsers)->where(fn ($query) => $query->where('notify_new_events', true)->orWhere('notify_weekly_agenda', true))->count());
    }

    /**
     * Correr duas vezes deixa tudo igual, incluindo os ficheiros (imagens e PDFs das faturas).
     */
    #[Test]
    public function running_twice_does_not_duplicate_anything(): void
    {
        $this->useRealInvoiceService();
        $this->runDemoSeeder();

        $counts = fn () => [
            'users' => User::count(),
            'profiles' => MemberProfile::count(),
            'business_hours' => DB::table('business_hours')->count(),
            'social_links' => DB::table('social_links')->count(),
            'portfolios' => Portfolio::count(),
            'media_files' => count(Storage::disk('public')->allFiles()),
            'events' => EventInstance::count(),
            'event_series' => EventSeries::count(),
            'registrations' => EventRegistration::count(),
            'registrations_per_event' => EventInstance::withCount('registrations')->orderBy('id')->pluck('registrations_count', 'id')->all(),
            'prayer_cards' => PrayerCard::withTrashed()->count(),
            'reviews' => Review::count(),
            'review_reports' => ReviewReport::count(),
            'subscriptions' => Subscription::count(),
            'subscription_items' => SubscriptionItem::count(),
            'invoices' => Invoice::count(),
            'invoice_files' => count(Storage::disk('local')->allFiles()),
        ];

        $first = $counts();

        $this->runDemoSeeder();

        $this->assertSame($first, $counts());
    }

    /**
     * Uma conta criada fora da demonstração (ex.: pelo professor no deploy) nunca é apagada nem alterada.
     */
    #[Test]
    public function running_again_never_deletes_nor_changes_accounts_that_are_not_demo(): void
    {
        $this->runDemoSeeder();

        $other = User::factory()->create(['email' => 'professor@aspec.test']);
        $profile = MemberProfile::factory()->create(['user_id' => $other->id]);
        $before = $profile->fresh()->toArray();

        $this->runDemoSeeder();

        $this->assertModelExists($other);
        $this->assertSame($before, $profile->fresh()->toArray());
    }

    #[Test]
    public function refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])
            ->expectsOutputToContain('O DemoSeeder não pode correr em produção.')
            ->assertSuccessful();

        $this->assertNoDemoDataWasCreated();
        $this->assertEmpty(Storage::disk('public')->allFiles());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    /**
     * O repositório é público e a password Demo#2026 está no código: fora de local/testing (ex. a demo
     * publicada no Render, ambiente staging) as contas demo só podem ter a password de DEMO_PASSWORD.
     */
    private function runDemoSeederInStaging(?string $password): void
    {
        config(['aspec.demo_password' => $password]);
        $this->app->detectEnvironment(fn () => 'staging');

        try {
            $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->run();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    private function assertNoDemoDataWasCreated(): void
    {
        $this->assertSame(0, User::where('email', 'like', 'demo.%')->count());
        $this->assertSame(0, MemberProfile::count());
        $this->assertSame(0, EventInstance::count());
        $this->assertSame(0, EventSeries::count());
        $this->assertSame(0, Review::count());
        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, Invoice::count());
    }

    #[Test]
    public function demo_password_comes_from_config_and_is_empty_in_tests(): void
    {
        $this->assertTrue(config()->has('aspec.demo_password'));
        $this->assertEmpty(config('aspec.demo_password'));
    }

    #[Test]
    public function refuses_to_run_outside_local_and_testing_without_a_demo_password(): void
    {
        $this->runDemoSeederInStaging(null);

        $this->assertNoDemoDataWasCreated();
    }

    #[Test]
    public function refuses_to_run_outside_local_and_testing_with_the_public_demo_password(): void
    {
        $this->runDemoSeederInStaging(DemoSeeder::PASSWORD);

        $this->assertNoDemoDataWasCreated();
    }

    #[Test]
    public function outside_local_and_testing_demo_accounts_use_the_configured_password(): void
    {
        $password = 'Outra#Demo-2026x';
        config(['aspec.demo_password' => $password]);
        $this->app->detectEnvironment(fn () => 'staging');

        try {
            $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])
                ->doesntExpectOutputToContain($password)
                ->doesntExpectOutputToContain(DemoSeeder::PASSWORD)
                ->assertSuccessful();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertTrue(User::where('email', 'demo.membro@aspec.test')->exists());

        $this->postJson('/api/auth/token', ['email' => 'demo.membro@aspec.test', 'password' => $password])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->postJson('/api/auth/token', ['email' => 'demo.membro@aspec.test', 'password' => DemoSeeder::PASSWORD])
            ->assertUnauthorized();
    }
}
