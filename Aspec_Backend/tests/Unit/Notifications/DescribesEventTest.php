<?php

namespace Tests\Unit\Notifications;

use App\Models\EventInstance;
use App\Models\Location;
use App\Notifications\Concerns\DescribesEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Local dos eventos nos emails (anúncio e agenda semanal): o espaço, ou "Ver no mapa", com link para
 * o Google Maps no mesmo formato do frontend, seguido do distrito. A morada nunca aparece em texto,
 * só codificada dentro do URL. O texto dos admins não pode criar links extra no Markdown.
 */
class DescribesEventTest extends TestCase
{
    use RefreshDatabase;

    private const MAPS = 'https://www.google.com/maps/search/?api=1&query=';

    private function describer(): object
    {
        return new class
        {
            use DescribesEvent;

            public function place(EventInstance $event): string
            {
                return $this->eventPlace($event);
            }

            public function maps(EventInstance $event): ?string
            {
                return $this->mapsUrl($event);
            }

            public function calendar(EventInstance $event): string
            {
                return $this->calendarUrl($event);
            }
        };
    }

    private function event(array $attributes = []): EventInstance
    {
        return EventInstance::factory()->create([
            'location_id' => Location::query()->where('name', 'Porto')->firstOrFail()->id,
            'venue_name' => 'Salão de eventos',
            'address' => 'Rua de Santa Catarina 100',
            'place_id' => null,
            'latitude' => null,
            'longitude' => null,
            ...$attributes,
        ])->fresh();
    }

    private function renderedLine(string $markdown): string
    {
        return (string) (new MailMessage)->line($markdown)->render();
    }

    #[Test]
    public function venue_links_to_google_maps_with_the_address_and_place_id_then_the_district(): void
    {
        $event = $this->event(['place_id' => 'ChIJ_abc-123']);
        $url = self::MAPS.rawurlencode('Rua de Santa Catarina 100').'&query_place_id='.rawurlencode('ChIJ_abc-123');

        $this->assertSame($url, $this->describer()->maps($event));
        $this->assertSame("[Salão de eventos]({$url}), Porto", $this->describer()->place($event));
    }

    #[Test]
    public function without_place_id_the_url_has_no_query_place_id(): void
    {
        $url = $this->describer()->maps($this->event());

        $this->assertSame(self::MAPS.rawurlencode('Rua de Santa Catarina 100'), $url);
        $this->assertStringNotContainsString('query_place_id', $url);
    }

    #[Test]
    public function coordinates_are_used_first_like_the_frontend(): void
    {
        $event = $this->event(['latitude' => 41.15, 'longitude' => -8.61]);

        $this->assertSame(self::MAPS.'41.15%2C-8.61', $this->describer()->maps($event));
    }

    #[Test]
    public function only_one_coordinate_falls_back_to_the_address(): void
    {
        $event = $this->event(['latitude' => 41.15, 'longitude' => null]);

        $this->assertSame(self::MAPS.rawurlencode('Rua de Santa Catarina 100'), $this->describer()->maps($event));
    }

    #[Test]
    public function without_address_the_venue_name_is_the_query(): void
    {
        $event = $this->event(['address' => null]);

        $this->assertSame(self::MAPS.rawurlencode('Salão de eventos'), $this->describer()->maps($event));
    }

    #[Test]
    public function with_only_a_place_id_it_is_both_the_query_and_the_place_id(): void
    {
        $event = $this->event(['venue_name' => null, 'address' => null, 'place_id' => 'ChIJ_only']);

        $this->assertSame(self::MAPS.'ChIJ_only&query_place_id=ChIJ_only', $this->describer()->maps($event));
    }

    #[Test]
    public function without_venue_name_the_link_text_is_ver_no_mapa(): void
    {
        $event = $this->event(['venue_name' => null]);
        $url = self::MAPS.rawurlencode('Rua de Santa Catarina 100');

        $this->assertSame("[Ver no mapa]({$url}), Porto", $this->describer()->place($event));
    }

    #[Test]
    public function with_nothing_to_search_only_the_district_is_shown(): void
    {
        $event = $this->event(['venue_name' => null, 'address' => null]);

        $this->assertNull($this->describer()->maps($event));
        $this->assertSame('Porto', $this->describer()->place($event));
    }

    #[Test]
    public function online_event_is_online_without_map(): void
    {
        $event = $this->event([
            'is_online' => true,
            'online_url' => 'https://meet.example.com/x',
            'venue_name' => null,
            'address' => null,
        ]);

        $this->assertSame('Online', $this->describer()->place($event));
    }

    #[Test]
    public function the_address_never_appears_as_plain_text(): void
    {
        $html = $this->renderedLine($this->describer()->place($this->event()));

        $this->assertStringNotContainsString('Rua de Santa Catarina 100', $html);
        $this->assertStringContainsString(rawurlencode('Rua de Santa Catarina 100'), $html);
    }

    #[Test]
    public function markdown_in_the_venue_name_cannot_create_another_link(): void
    {
        $event = $this->event(['venue_name' => 'Sala](https://mau.pt) *x*']);

        $html = $this->renderedLine($this->describer()->place($event));

        $this->assertSame(1, preg_match_all('/<a\s[^>]*href="https:\/\/www\.google\.com\/maps\/search\//', $html));
        $this->assertSame(0, preg_match_all('/<a\s[^>]*href="https?:\/\/mau\.pt/', $html));
        $this->assertStringNotContainsString('href="https://mau.pt', $html);
        $this->assertStringNotContainsString('<em>', $html);
        $this->assertStringContainsString('>Sala](https://mau.pt) *x*</a>', $html);
        $this->assertStringContainsString('href="'.e(self::MAPS.rawurlencode('Rua de Santa Catarina 100')).'"', $html);
    }

    #[Test]
    public function markdown_in_the_district_or_venue_without_link_is_escaped_too(): void
    {
        $event = $this->event(['venue_name' => '[Clique](https://mau.pt)']);

        $html = $this->renderedLine($this->describer()->place($event));

        $this->assertStringNotContainsString('href="https://mau.pt', $html);
        $this->assertSame(1, preg_match_all('/<a\s[^>]*href="https:\/\/www\.google\.com\/maps\/search\//', $html));
        $this->assertSame(0, preg_match_all('/<a\s[^>]*href="https?:\/\/mau\.pt/', $html));
    }

    #[Test]
    public function special_characters_in_the_address_are_fully_encoded_in_the_url(): void
    {
        $address = 'Rua (Antiga) [Bloco B] São João 5';
        $url = $this->describer()->maps($this->event(['address' => $address]));

        $this->assertSame(self::MAPS.'Rua%20%28Antiga%29%20%5BBloco%20B%5D%20S%C3%A3o%20Jo%C3%A3o%205', $url);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9%._~\-:\/?=&]+$/', $url);
    }

    #[Test]
    public function calendar_url_is_the_absolute_api_route_on_app_url(): void
    {
        $event = $this->event();

        $this->assertSame(
            route('events.calendar', ['eventInstance' => $event->id]),
            $this->describer()->calendar($event)
        );
        $this->assertSame(
            rtrim(config('app.url'), '/')."/api/events/{$event->id}/calendar.ics",
            $this->describer()->calendar($event)
        );
    }
}
