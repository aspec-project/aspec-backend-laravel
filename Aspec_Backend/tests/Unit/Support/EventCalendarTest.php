<?php

namespace Tests\Unit\Support;

use App\Models\EventInstance;
use App\Models\Location;
use App\Support\EventCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gerador do ficheiro iCalendar (RFC 5545) de um evento, sem HTTP. As horas saem em UTC (sufixo Z)
 * a partir da hora de Lisboa em que o backoffice guarda os eventos; o texto é escapado e as linhas
 * dobradas a 75 octetos, sem partir caracteres UTF-8. O link da reunião online nunca entra no ficheiro,
 * porque o endpoint é público.
 */
class EventCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND = 'https://app.aspec.test';

    private const ONLINE_TEXT = 'Evento online: o link fica disponível na página do evento';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => self::FRONTEND, 'app.event_timezone' => 'Europe/Lisbon']);
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:15:30', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function event(array $attributes = []): EventInstance
    {
        return EventInstance::factory()->create([
            'location_id' => Location::query()->where('name', 'Porto')->firstOrFail()->id,
            'name' => 'Jantar de Networking',
            'event_date' => '2027-07-15',
            'event_start_time' => '19:00:00',
            'event_end_time' => '21:00:00',
            'description' => 'Jantar entre empresários.',
            'venue_name' => 'Salão Nobre',
            'address' => 'Rua de Santa Catarina 100',
            'registration_deadline' => '2027-07-14 23:59:00',
            ...$attributes,
        ]);
    }

    private function calendar(EventInstance $event): string
    {
        return app(EventCalendar::class)->forEvent($event->fresh());
    }

    private function unfold(string $ics): string
    {
        return str_replace("\r\n ", '', $ics);
    }

    /**
     * Valor (já desdobrado) da primeira propriedade com este nome.
     */
    private function property(string $ics, string $name): ?string
    {
        foreach (explode("\r\n", $this->unfold($ics)) as $line) {
            if (str_starts_with($line, $name.':')) {
                return substr($line, strlen($name) + 1);
            }
        }

        return null;
    }

    #[Test]
    public function summer_event_is_converted_from_lisbon_time_to_utc(): void
    {
        $ics = $this->calendar($this->event(['event_date' => '2027-07-15']));

        $this->assertSame('20270715T180000Z', $this->property($ics, 'DTSTART'));
        $this->assertSame('20270715T200000Z', $this->property($ics, 'DTEND'));
    }

    #[Test]
    public function winter_event_keeps_the_same_hour_in_utc(): void
    {
        $ics = $this->calendar($this->event([
            'event_date' => '2026-12-10',
            'registration_deadline' => '2026-12-09 23:59:00',
        ]));

        $this->assertSame('20261210T190000Z', $this->property($ics, 'DTSTART'));
        $this->assertSame('20261210T210000Z', $this->property($ics, 'DTEND'));
    }

    #[Test]
    public function times_have_no_timezone_identifier(): void
    {
        $ics = $this->calendar($this->event());

        $this->assertStringNotContainsString('TZID', $ics);
        $this->assertStringNotContainsString('VTIMEZONE', $ics);
    }

    #[Test]
    public function uid_is_the_event_id_at_the_frontend_host(): void
    {
        $event = $this->event();

        $this->assertSame($event->id.'@app.aspec.test', $this->property($this->calendar($event), 'UID'));
    }

    #[Test]
    public function dtstamp_is_the_generation_moment_in_utc(): void
    {
        $this->assertSame('20261106T101530Z', $this->property($this->calendar($this->event()), 'DTSTAMP'));
    }

    #[Test]
    public function last_modified_is_the_event_update_time_in_utc(): void
    {
        $event = $this->event();
        EventInstance::query()->whereKey($event->id)->update(['updated_at' => '2026-10-01 08:30:00']);

        $this->assertSame('20261001T083000Z', $this->property($this->calendar($event), 'LAST-MODIFIED'));
    }

    #[Test]
    public function calendar_has_the_fixed_header_and_a_single_event(): void
    {
        $ics = $this->calendar($this->event());
        $lines = explode("\r\n", $this->unfold($ics));

        $this->assertSame('BEGIN:VCALENDAR', $lines[0]);
        $this->assertContains('VERSION:2.0', $lines);
        $this->assertContains('PRODID:-//ASPEC//Plataforma ASPEC//PT', $lines);
        $this->assertContains('CALSCALE:GREGORIAN', $lines);
        $this->assertContains('METHOD:PUBLISH', $lines);
        $this->assertSame(1, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertSame(1, substr_count($ics, 'END:VEVENT'));
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
    }

    #[Test]
    public function every_line_ends_with_crlf(): void
    {
        $ics = $this->calendar($this->event(['description' => "Primeira linha\nSegunda linha"]));

        $this->assertStringEndsWith("\r\n", $ics);
        $withoutCrlf = str_replace("\r\n", '', $ics);
        $this->assertStringNotContainsString("\r", $withoutCrlf);
        $this->assertStringNotContainsString("\n", $withoutCrlf);
    }

    #[Test]
    public function text_values_escape_backslash_semicolon_and_comma(): void
    {
        $ics = $this->calendar($this->event(['name' => 'Jantar; Porto, 2026 \\ Teste']));

        $this->assertSame('Jantar\\; Porto\\, 2026 \\\\ Teste', $this->property($ics, 'SUMMARY'));
    }

    #[Test]
    public function line_breaks_in_the_description_become_escaped_newlines(): void
    {
        $ics = $this->calendar($this->event(['description' => "Linha 1\r\nLinha 2\nLinha 3\rLinha 4"]));

        $this->assertStringStartsWith('Linha 1\\nLinha 2\\nLinha 3\\nLinha 4', $this->property($ics, 'DESCRIPTION'));
    }

    #[Test]
    public function other_control_characters_are_removed_but_tabs_are_kept(): void
    {
        $ics = $this->calendar($this->event(['name' => "Jantar\x07 de\x00 Natal\tASPEC"]));

        $this->assertSame("Jantar de Natal\tASPEC", $this->property($ics, 'SUMMARY'));
    }

    #[Test]
    public function long_accented_lines_are_folded_at_75_octets_without_breaking_characters(): void
    {
        $name = str_repeat('Formação em gestão de ações, ', 12);
        $ics = $this->calendar($this->event(['name' => $name]));

        $lines = explode("\r\n", rtrim($ics, "\r\n"));
        $continuations = 0;

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), "Linha com mais de 75 octetos: {$line}");
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), 'Uma dobragem partiu um carácter UTF-8.');
            $continuations += str_starts_with($line, ' ') ? 1 : 0;
        }

        $this->assertGreaterThan(0, $continuations);
        $this->assertSame(str_replace(',', '\\,', $name), $this->property($ics, 'SUMMARY'));
    }

    #[Test]
    public function folded_lines_never_start_without_a_space_inside_a_property(): void
    {
        $ics = $this->calendar($this->event(['description' => str_repeat('Descrição longa çãõé ', 40)]));

        foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
            $this->assertMatchesRegularExpression('/^( |[A-Z-]+[:;])/', $line);
        }
    }

    #[Test]
    public function description_is_cut_to_500_characters_and_ends_with_the_event_page_link(): void
    {
        $event = $this->event(['description' => str_repeat('a', 2000)]);
        $description = $this->property($this->calendar($event), 'DESCRIPTION');

        $this->assertStringContainsString(str_repeat('a', 500), $description);
        $this->assertStringNotContainsString(str_repeat('a', 501), $description);
        $this->assertStringEndsWith('\\n\\nMais informações: '.self::FRONTEND.'/eventos/'.$event->id, $description);
    }

    #[Test]
    public function url_points_to_the_event_page_on_the_frontend(): void
    {
        config(['app.frontend_url' => self::FRONTEND.'/']);
        $event = $this->event();

        $this->assertSame(self::FRONTEND.'/eventos/'.$event->id, $this->property($this->calendar($event), 'URL'));
    }

    #[Test]
    public function in_person_location_has_venue_address_and_district(): void
    {
        $ics = $this->calendar($this->event());

        $this->assertSame('Salão Nobre\\, Rua de Santa Catarina 100\\, Porto', $this->property($ics, 'LOCATION'));
    }

    #[Test]
    public function in_person_location_skips_empty_parts(): void
    {
        $ics = $this->calendar($this->event(['venue_name' => null]));

        $this->assertSame('Rua de Santa Catarina 100\\, Porto', $this->property($ics, 'LOCATION'));
    }

    #[Test]
    public function online_event_never_includes_the_meeting_link(): void
    {
        $event = $this->event([
            'is_online' => true,
            'online_url' => 'https://meet.example.com/segredo-123',
            'venue_name' => null,
            'address' => null,
            'latitude' => null,
            'longitude' => null,
        ]);
        $ics = $this->calendar($event);
        $unfolded = $this->unfold($ics);

        $this->assertSame(self::ONLINE_TEXT, $this->property($ics, 'LOCATION'));
        $this->assertStringContainsString(self::ONLINE_TEXT.'.', $this->property($ics, 'DESCRIPTION'));
        $this->assertStringNotContainsString('meet.example.com', $unfolded);
        $this->assertStringNotContainsString('segredo-123', $unfolded);
    }

    #[Test]
    public function filename_is_an_ascii_slug_of_the_event_name(): void
    {
        $event = $this->event(['name' => 'Ação de Formação em Évora']);

        $this->assertSame('acao-de-formacao-em-evora.ics', app(EventCalendar::class)->filename($event));
    }

    #[Test]
    public function filename_slug_is_cut_to_60_characters(): void
    {
        $event = $this->event(['name' => str_repeat('Encontro ', 20)]);
        $filename = app(EventCalendar::class)->filename($event);

        $this->assertStringEndsWith('.ics', $filename);
        $this->assertLessThanOrEqual(60, strlen(substr($filename, 0, -4)));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+\.ics$/', $filename);
    }

    #[Test]
    public function filename_falls_back_to_evento_when_the_name_has_only_symbols(): void
    {
        $event = $this->event(['name' => '*** !!! ???']);

        $this->assertSame('evento.ics', app(EventCalendar::class)->filename($event));
    }
}
