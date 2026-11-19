<?php

namespace Tests\Feature\PrayerCards;

use App\Models\EventInstance;
use App\Models\User;
use App\Notifications\PrayerCardReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O nome do evento é escrito pelo administrador e entra numa linha Markdown do email "Recebeu um cartão
 * de oração": tem de aparecer como texto, sem criar links nem formatação (observação A7-4).
 */
class PrayerCardReceivedEmailTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function markdown_in_the_event_name_does_not_create_a_link_in_the_email(): void
    {
        $member = User::factory()->create();
        $event = EventInstance::factory()->create(['name' => 'Jantar [Confirme aqui](https://phish.pt)']);

        $html = (string) (new PrayerCardReceivedNotification($event))->toMail($member)->render();

        $this->assertStringNotContainsString('href="https://phish.pt', $html);
        $this->assertMatchesRegularExpression('~<strong[^>]*>Jantar \[Confirme aqui\]\(https://phish\.pt\)</strong>~', $html);
    }

    #[Test]
    public function an_ordinary_event_name_is_shown_in_bold(): void
    {
        $member = User::factory()->create();
        $event = EventInstance::factory()->create(['name' => 'Jantar de Networking']);

        $html = (string) (new PrayerCardReceivedNotification($event))->toMail($member)->render();

        $this->assertMatchesRegularExpression('~<strong[^>]*>Jantar de Networking</strong>~', $html);
    }
}
