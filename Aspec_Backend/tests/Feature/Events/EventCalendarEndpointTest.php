<?php

namespace Tests\Feature\Events;

use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\EventSeries;
use App\Models\User;
use App\Support\EventCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Concerns\FillsRateLimits;
use Tests\TestCase;

/**
 * GET /api/events/{event}/calendar.ics: o evento em ficheiro iCalendar, público e com as mesmas regras
 * do detalhe público (qualquer evento existente, incluindo passados e sessões de séries). Não usa
 * sessão: um clique vindo do frontend não pode criar sessão nem cookie XSRF em cada download.
 */
class EventCalendarEndpointTest extends TestCase
{
    use FillsRateLimits, RefreshDatabase;

    private const FRONTEND = 'http://localhost:5173';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => self::FRONTEND]);
    }

    private function url(string $eventId): string
    {
        return "/api/events/{$eventId}/calendar.ics";
    }

    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse
            ? $response->streamedContent()
            : (string) $response->getContent();
    }

    #[Test]
    public function visitor_downloads_the_event_as_an_icalendar_file(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06 10:00:00', 'UTC'));
        $event = EventInstance::factory()->create(['name' => 'Jantar de Networking do Porto']);

        $response = $this->get($this->url($event->id))->assertOk();

        $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(app(EventCalendar::class)->forEvent($event->fresh()), $this->body($response));
    }

    #[Test]
    public function file_is_sent_as_an_attachment_named_after_the_event(): void
    {
        $event = EventInstance::factory()->create(['name' => 'Jantar de Networking do Porto']);

        $response = $this->get($this->url($event->id))->assertOk();

        $this->assertMatchesRegularExpression(
            '/^attachment; filename="?jantar-de-networking-do-porto\.ics"?$/',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    #[Test]
    public function response_may_only_be_cached_privately_for_five_minutes(): void
    {
        $event = EventInstance::factory()->create();

        $cacheControl = (string) $this->get($this->url($event->id))->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=300', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    #[Test]
    public function past_event_can_still_be_downloaded_like_its_public_page(): void
    {
        $date = now('Europe/Lisbon')->subMonth();
        $event = EventInstance::factory()->create([
            'event_date' => $date->toDateString(),
            'registration_deadline' => $date->copy()->subDay()->setTime(23, 59)->format('Y-m-d H:i:s'),
        ]);

        $this->getJson("/api/events/{$event->id}")->assertOk();
        $this->get($this->url($event->id))->assertOk();
    }

    #[Test]
    public function series_session_can_be_downloaded(): void
    {
        $series = EventSeries::create([
            'frequency' => 'weekly',
            'ends_on' => now()->addMonths(3)->toDateString(),
            'registration_deadline_days_before' => 1,
        ]);
        $session = EventInstance::factory()->create(['series_id' => $series->id]);

        $this->get($this->url($session->id))->assertOk();
    }

    #[Test]
    public function unknown_event_returns_the_same_404_as_the_public_detail(): void
    {
        $missing = '00000000-0000-4000-8000-000000000000';

        $detail = $this->getJson("/api/events/{$missing}")->assertNotFound();

        $this->getJson($this->url($missing))
            ->assertNotFound()
            ->assertExactJson($detail->json())
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Recurso não encontrado.');
    }

    #[Test]
    public function event_deleted_by_an_admin_returns_404(): void
    {
        Notification::fake();
        $event = EventInstance::factory()->create();
        Sanctum::actingAs(User::factory()->technicalAdmin()->create());

        $this->deleteJson("/api/admin/events/{$event->id}")->assertOk();

        $this->getJson($this->url($event->id))
            ->assertNotFound()
            ->assertJsonPath('message', 'Recurso não encontrado.');
    }

    #[Test]
    public function non_uuid_id_returns_404(): void
    {
        $this->getJson($this->url('not-a-uuid'))
            ->assertNotFound()
            ->assertJsonPath('message', 'Recurso não encontrado.');
    }

    #[Test]
    public function request_from_the_frontend_does_not_start_a_session(): void
    {
        $event = EventInstance::factory()->create();

        $response = $this->withHeaders(['Origin' => self::FRONTEND, 'Referer' => self::FRONTEND.'/'])
            ->get($this->url($event->id))
            ->assertOk();

        $response->assertCookieMissing('XSRF-TOKEN');
        $response->assertCookieMissing(config('session.cookie'));
    }

    #[Test]
    public function calendar_shares_the_public_events_rate_limit(): void
    {
        $event = EventInstance::factory()->create();
        $this->fillRateLimit('public-events', '127.0.0.1', 119);

        $this->getJson('/api/events')->assertOk();

        $this->getJson($this->url($event->id))
            ->assertTooManyRequests()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function registered_member_never_gets_the_meeting_link_in_the_file(): void
    {
        $date = now('Europe/Lisbon')->addDays(2);
        $event = EventInstance::factory()->create([
            'is_online' => true,
            'online_url' => 'https://meet.example.com/segredo-123',
            'address' => null,
            'latitude' => null,
            'longitude' => null,
            'event_date' => $date->toDateString(),
            'registration_deadline' => now('Europe/Lisbon')->subDay()->setTime(23, 59)->format('Y-m-d H:i:s'),
        ]);
        $member = User::factory()->create();
        EventRegistration::create(['event_instance_id' => $event->id, 'user_id' => $member->id]);
        Sanctum::actingAs($member);

        $body = str_replace("\r\n ", '', $this->body($this->get($this->url($event->id))->assertOk()));

        $this->assertStringNotContainsString('meet.example.com', $body);
        $this->assertStringNotContainsString('segredo-123', $body);
    }
}
