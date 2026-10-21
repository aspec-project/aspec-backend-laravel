<?php

namespace Tests\Feature\Admin;

use App\Enums\InactiveReason;
use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminEventAttendeeTest extends TestCase
{
    use RefreshDatabase;

    private const BOM = "\xEF\xBB\xBF";

    private const HEADER = "Nome;Empresa;Email;Telefone;Data de inscrição\r\n";

    private const LIST_MESSAGE = 'Inscritos do evento obtidos com sucesso.';

    private const OPEN_REGISTRATIONS_MESSAGE = 'As inscrições ainda estão abertas. A lista de presenças só pode ser exportada depois do prazo de inscrição.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function listUrl(string $eventId): string
    {
        return "/api/admin/events/{$eventId}/attendees";
    }

    private function csvUrl(string $eventId): string
    {
        return "/api/admin/events/{$eventId}/attendees.csv";
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function member(string $name, string $businessName, array $userAttributes = []): User
    {
        $user = User::factory()->create($userAttributes);

        MemberProfile::factory()->create([
            'user_id' => $user->id,
            'name' => $name,
            'business_name' => $businessName,
        ]);

        return $user;
    }

    /**
     * Evento de 14/11/2026 com o prazo de inscrição na véspera às 23:59 (hora de Lisboa).
     */
    private function closingEvent(array $attributes = []): EventInstance
    {
        return EventInstance::factory()->create(array_merge([
            'event_date' => '2026-11-14',
            'registration_deadline' => '2026-11-13 23:59:00',
        ], $attributes));
    }

    /**
     * Evento que já aconteceu (as inscrições passadas sobrevivem ao apagar da conta).
     */
    private function pastEvent(): EventInstance
    {
        return EventInstance::factory()->create([
            'event_date' => now()->subDays(10)->format('Y-m-d'),
            'registration_deadline' => now()->subDays(11)->format('Y-m-d').' 23:59:00',
        ]);
    }

    private function register(EventInstance $event, User $user, ?string $atUtc = null): EventRegistration
    {
        if ($atUtc !== null) {
            $this->travelTo(Carbon::parse($atUtc, 'UTC'));
        }

        return EventRegistration::query()->create([
            'event_instance_id' => $event->id,
            'user_id' => $user->id,
        ]);
    }

    private function travelAfterDeadline(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
    }

    private function assertJsonError(TestResponse $response, int $status, string $message): void
    {
        $response->assertStatus($status)->assertJson(['success' => false, 'message' => $message]);
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function guest_cannot_list_or_export_attendees(): void
    {
        $event = $this->closingEvent();

        $this->assertJsonError($this->getJson($this->listUrl($event->id)), 401, 'Não autenticado.');
        $this->assertJsonError($this->getJson($this->csvUrl($event->id)), 401, 'Não autenticado.');
    }

    #[Test]
    public function member_cannot_list_or_export_attendees(): void
    {
        $event = $this->closingEvent();
        Sanctum::actingAs(User::factory()->create());

        $this->assertJsonError($this->getJson($this->listUrl($event->id)), 403, 'Não tem permissão para realizar esta ação.');
        $this->assertJsonError($this->getJson($this->csvUrl($event->id)), 403, 'Não tem permissão para realizar esta ação.');
    }

    #[Test]
    public function inactive_admin_cannot_list_or_export_attendees(): void
    {
        $event = $this->closingEvent();
        Sanctum::actingAs(User::factory()->admin()->inactive(InactiveReason::Blocked)->create());

        $this->assertJsonError($this->getJson($this->listUrl($event->id)), 403, 'A conta não está ativa e não pode editar dados.');
        $this->assertJsonError($this->getJson($this->csvUrl($event->id)), 403, 'A conta não está ativa e não pode editar dados.');
    }

    /**
     * Já passa antes da implementação (uma rota inexistente também dá 404): é uma guarda de regressão.
     */
    #[Test]
    public function unknown_or_invalid_event_returns_not_found(): void
    {
        $this->actingAsAdmin();

        foreach ([(string) Str::uuid(), 'nao-e-uuid'] as $id) {
            $this->assertJsonError($this->getJson($this->listUrl($id)), 404, 'Recurso não encontrado.');
            $this->assertJsonError($this->getJson($this->csvUrl($id)), 404, 'Recurso não encontrado.');
        }
    }

    #[Test]
    public function admin_gets_personal_contacts_of_each_registrant(): void
    {
        $event = $this->closingEvent();
        $user = User::factory()->create([
            'email' => 'ana.pessoal@example.pt',
            'phone' => '+351912345678',
        ]);
        MemberProfile::factory()->create([
            'user_id' => $user->id,
            'name' => 'Ana Pessoal',
            'business_name' => 'Ana Lda.',
            'commercial_email' => 'geral@ana-lda.example.pt',
            'commercial_phone' => '+351213000000',
        ]);
        $registration = $this->register($event, $user);
        $this->actingAsAdmin();

        $response = $this->getJson($this->listUrl($event->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', self::LIST_MESSAGE)
            ->assertJsonCount(1, 'data');

        $this->assertEqualsCanonicalizing(
            ['id', 'user_id', 'name', 'business_name', 'email', 'phone', 'registered_at'],
            array_keys($response->json('data.0'))
        );
        $response
            ->assertJsonPath('data.0.id', $registration->id)
            ->assertJsonPath('data.0.user_id', $user->id)
            ->assertJsonPath('data.0.name', 'Ana Pessoal')
            ->assertJsonPath('data.0.business_name', 'Ana Lda.')
            ->assertJsonPath('data.0.email', 'ana.pessoal@example.pt')
            ->assertJsonPath('data.0.phone', '+351912345678');
        $this->assertStringNotContainsString('geral@ana-lda.example.pt', $response->getContent());
        $this->assertStringNotContainsString('+351213000000', $response->getContent());
    }

    #[Test]
    public function only_registrants_of_the_requested_event_are_listed(): void
    {
        $eventA = $this->closingEvent();
        $eventB = $this->closingEvent();
        $eventC = $this->closingEvent();
        $first = $this->member('Ana Pessoal', 'Ana Lda.');
        $second = $this->member('Rui Pessoal', 'Rui Lda.');
        $other = $this->member('Eva Outra', 'Eva Lda.');
        $this->register($eventA, $first);
        $this->register($eventA, $second);
        $this->register($eventB, $other);
        $this->actingAsAdmin();

        $response = $this->getJson($this->listUrl($eventA->id))->assertOk();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], array_column($response->json('data'), 'user_id'));

        $this->getJson($this->listUrl($eventC->id))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    #[Test]
    public function attendees_are_ordered_by_name_in_portuguese_order(): void
    {
        $event = $this->closingEvent();
        $this->register($event, $this->member('Bruno Costa', 'Costa Lda.'), '2026-10-01 10:00:00');
        $this->register($event, $this->member('andré Sousa', 'Sousa Lda.'), '2026-10-02 10:00:00');
        $this->register($event, User::factory()->admin()->create(), '2026-10-03 10:00:00');
        $this->register($event, $this->member('Álvaro Dias', 'Dias Lda.'), '2026-10-04 10:00:00');
        $this->actingAsAdmin();

        $response = $this->getJson($this->listUrl($event->id))->assertOk();

        $this->assertSame(['Álvaro Dias', 'andré Sousa', 'Bruno Costa', null], array_column($response->json('data'), 'name'));
    }

    #[Test]
    public function registration_date_is_returned_in_iso_8601(): void
    {
        $event = $this->closingEvent();
        $this->register($event, $this->member('Ana Pessoal', 'Ana Lda.'), '2026-07-15 13:05:00');
        $this->actingAsAdmin();

        $this->getJson($this->listUrl($event->id))
            ->assertOk()
            ->assertJsonPath('data.0.registered_at', '2026-07-15T13:05:00+00:00');
    }

    #[Test]
    public function deleted_registrant_keeps_anonymised_name_without_contacts(): void
    {
        $event = $this->pastEvent();
        $user = $this->member('Ana Pessoal', 'Ana Lda.');
        $this->register($event, $user);
        $user->anonymizeAndDelete();
        $this->actingAsAdmin();

        $response = $this->getJson($this->listUrl($event->id))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Utilizador Anónimo')
            ->assertJsonPath('data.0.business_name', 'Empresa Removida')
            ->assertJsonPath('data.0.email', null)
            ->assertJsonPath('data.0.phone', null)
            ->assertJsonPath('data.0.user_id', null);

        $this->assertStringNotContainsString('@aspec.local', $response->getContent());
        $this->assertStringNotContainsString('000000000', $response->getContent());
    }

    #[Test]
    public function admin_without_profile_is_listed_with_personal_contacts(): void
    {
        $event = $this->closingEvent();
        $registrant = User::factory()->admin()->create([
            'email' => 'admin.inscrito@example.pt',
            'phone' => '+351213456789',
        ]);
        $this->register($event, $registrant);
        $this->actingAsAdmin();

        $this->getJson($this->listUrl($event->id))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', null)
            ->assertJsonPath('data.0.business_name', null)
            ->assertJsonPath('data.0.email', 'admin.inscrito@example.pt')
            ->assertJsonPath('data.0.phone', '+351213456789')
            ->assertJsonPath('data.0.user_id', $registrant->id);
    }

    #[Test]
    public function blocked_member_is_still_listed(): void
    {
        $event = $this->closingEvent();
        $user = $this->member('Ana Pessoal', 'Ana Lda.', [
            'email' => 'ana.bloqueada@example.pt',
            'phone' => '+351912345678',
        ]);
        $this->register($event, $user);
        $user->deactivate('blocked');
        $this->actingAsAdmin();

        $this->getJson($this->listUrl($event->id))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $user->id)
            ->assertJsonPath('data.0.email', 'ana.bloqueada@example.pt')
            ->assertJsonPath('data.0.phone', '+351912345678');
    }

    #[Test]
    public function admin_downloads_csv_with_excel_friendly_headers(): void
    {
        $event = $this->closingEvent(['name' => 'Jantar de Networking']);
        $this->travelAfterDeadline();
        $this->actingAsAdmin();

        $response = $this->get($this->csvUrl($event->id))->assertOk();

        $response->assertDownload('presencas_2026-11-14_jantar-de-networking.csv');
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    #[Test]
    public function csv_contains_bom_header_and_rows_in_lisbon_time(): void
    {
        $event = $this->closingEvent([
            'event_date' => '2026-12-14',
            'registration_deadline' => '2026-12-13 23:59:00',
        ]);
        $bruno = $this->member('Bruno Costa', 'Costa Lda.', ['email' => 'bruno@example.pt', 'phone' => '+351936543210']);
        $alvaro = $this->member('Álvaro Dias', 'Dias & Filhos, Lda.', ['email' => 'alvaro@example.pt', 'phone' => '+351912345678']);
        $this->register($event, $bruno, '2026-12-01 09:30:00');
        $this->register($event, $alvaro, '2026-07-15 13:05:00');
        $this->travelTo(Carbon::parse('2026-12-20 12:00:00', 'UTC'));
        $this->actingAsAdmin();

        $response = $this->get($this->csvUrl($event->id))->assertOk();

        $this->assertSame(
            self::BOM.self::HEADER
            ."Álvaro Dias;Dias & Filhos, Lda.;alvaro@example.pt;'+351912345678;15/07/2026 14:05\r\n"
            ."Bruno Costa;Costa Lda.;bruno@example.pt;'+351936543210;01/12/2026 09:30\r\n",
            $response->streamedContent()
        );
    }

    #[Test]
    public function csv_neutralises_formulas_in_member_supplied_fields(): void
    {
        $event = $this->closingEvent();
        $this->register($event, $this->member('=HYPERLINK("http://evil.test")', '@SUM(1+1)'));
        $this->travelAfterDeadline();
        $this->actingAsAdmin();

        $content = $this->get($this->csvUrl($event->id))->assertOk()->streamedContent();

        $this->assertStringStartsWith(
            self::BOM.self::HEADER.'"\'=HYPERLINK(""http://evil.test"")";\'@SUM(1+1);',
            $content
        );
    }

    #[Test]
    public function csv_of_an_online_event_without_registrants_has_only_the_header(): void
    {
        $event = $this->closingEvent([
            'is_online' => true,
            'address' => null,
            'latitude' => null,
            'longitude' => null,
            'online_url' => 'https://meet.example.pt/jantar',
        ]);
        $this->travelAfterDeadline();
        $this->actingAsAdmin();

        $response = $this->get($this->csvUrl($event->id))->assertOk();

        $this->assertSame(self::BOM.self::HEADER, $response->streamedContent());
    }

    #[Test]
    public function csv_rows_for_deleted_registrants_and_admins_without_profile(): void
    {
        $event = $this->pastEvent();
        $deleted = $this->member('Ana Pessoal', 'Ana Lda.');
        $this->register($event, $deleted);
        $deleted->anonymizeAndDelete();
        $this->register($event, User::factory()->admin()->create([
            'email' => 'admin.inscrito@example.pt',
            'phone' => '+351213456789',
        ]));
        $this->actingAsAdmin();

        $content = $this->get($this->csvUrl($event->id))->assertOk()->streamedContent();

        $this->assertStringContainsString("\r\nUtilizador Anónimo;Empresa Removida;;;", $content);
        $this->assertStringContainsString("\r\n;;admin.inscrito@example.pt;'+351213456789;", $content);
    }

    public static function filenames(): array
    {
        return [
            'pontuação e acentos' => ['Formação: "Liderança" / 2026?', 'presencas_2026-11-14_formacao-lideranca-2026.csv'],
            'sem letras nem números' => ['!!!', 'presencas_2026-11-14.csv'],
            'nome longo' => [
                'Encontro anual de empresários cristãos da região de Lisboa e Vale do Tejo',
                'presencas_2026-11-14_encontro-anual-de-empresarios-cristaos-da-regiao-d.csv',
            ],
        ];
    }

    #[Test]
    #[DataProvider('filenames')]
    public function csv_filename_is_ascii_and_limited(string $eventName, string $expectedFilename): void
    {
        $event = $this->closingEvent(['name' => $eventName]);
        $this->travelAfterDeadline();
        $this->actingAsAdmin();

        $this->get($this->csvUrl($event->id))
            ->assertOk()
            ->assertDownload($expectedFilename);
    }

    #[Test]
    public function content_disposition_is_exposed_to_the_frontend_origin(): void
    {
        $event = $this->closingEvent();
        $this->travelAfterDeadline();
        $this->actingAsAdmin();

        $response = $this->get($this->csvUrl($event->id), ['Origin' => config('cors.allowed_origins')[0]])
            ->assertOk();

        $this->assertStringContainsStringIgnoringCase(
            'Content-Disposition',
            (string) $response->headers->get('Access-Control-Expose-Headers')
        );
    }

    #[Test]
    public function attendee_routes_share_a_limit_of_30_requests_per_minute(): void
    {
        $event = $this->closingEvent();
        $this->travelAfterDeadline();
        $this->freezeTime();
        $this->actingAsAdmin();

        for ($i = 1; $i <= 30; $i++) {
            $this->getJson($this->listUrl($event->id))->assertOk();
        }

        $this->assertJsonError(
            $this->getJson($this->csvUrl($event->id)),
            429,
            'Demasiados pedidos. Tente novamente mais tarde.'
        );

        $this->actingAsAdmin();
        $this->getJson($this->listUrl($event->id))->assertOk();
    }

    #[Test]
    public function listing_runs_a_fixed_number_of_queries(): void
    {
        $small = $this->closingEvent();
        $large = $this->closingEvent();
        $this->register($small, $this->member('Ana Pessoal', 'Ana Lda.'));
        for ($i = 1; $i <= 5; $i++) {
            $this->register($large, $this->member("Membro {$i}", "Empresa {$i}"));
        }
        $this->actingAsAdmin();
        // Pedido de aquecimento: os middlewares carregam o papel e o estado do admin só no primeiro pedido.
        $this->getJson($this->listUrl($small->id))->assertOk();

        $counts = [];
        foreach ([$small, $large] as $event) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->listUrl($event->id))->assertOk();
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        $this->assertSame($counts[0], $counts[1]);
    }

    #[Test]
    public function csv_export_is_only_allowed_after_the_registration_deadline(): void
    {
        $event = $this->closingEvent();
        $user = $this->member('Ana Pessoal', 'Ana Lda.');
        $this->register($event, $user, '2026-10-20 10:00:00');
        $this->actingAsAdmin();

        $this->travelTo(Carbon::parse('2026-11-13 23:59:00', 'Europe/Lisbon'));

        $response = $this->get($this->csvUrl($event->id));
        $this->assertJsonError($response, 409, self::OPEN_REGISTRATIONS_MESSAGE);
        $this->assertStringStartsNotWith(self::BOM, (string) $response->getContent());

        $this->getJson($this->listUrl($event->id))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $user->id);

        $this->travelTo(Carbon::parse('2026-11-14 00:00:00', 'Europe/Lisbon'));

        $this->get($this->csvUrl($event->id))->assertOk();
    }
}
