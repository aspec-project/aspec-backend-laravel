<?php

namespace Tests\Unit\Notifications;

use App\Models\EventInstance;
use App\Models\Location;
use App\Models\User;
use App\Notifications\EventCancelledNotification;
use App\Notifications\EventUpdatedNotification;
use App\Notifications\NewEventAnnouncementNotification;
use App\Notifications\UserBlockedNotification;
use App\Notifications\UserRejectedNotification;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsEventEmailLinks;
use Tests\TestCase;

/**
 * O motivo da rejeição ou do bloqueio, a justificação de um evento alterado ou cancelado e o nome do
 * evento são texto livre de um admin. Num email oficial da ASPEC esse texto não pode virar link nem
 * formatação Markdown (phishing com uma conta de admin comprometida); os links que a própria
 * plataforma constrói (mapa, rodapé das preferências) continuam a ser links.
 */
class AdminTextMarkdownEscapingTest extends TestCase
{
    use AssertsEventEmailLinks, RefreshDatabase;

    private const MALICIOUS = 'Veja [clique aqui](https://phish.pt) e **isto**';

    private const MALICIOUS_EVENT_NAME = '[x](https://phish.pt)';

    /**
     * Notificações com texto livre do admin: recebem o texto e devolvem a notificação.
     *
     * @return array<string, array{0: Closure(string): object, 1: string}>
     */
    public static function adminTextNotifications(): array
    {
        return [
            'candidatura rejeitada' => [
                fn (string $text) => new UserRejectedNotification($text),
                'Motivo: ',
            ],
            'conta bloqueada' => [
                fn (string $text) => new UserBlockedNotification($text),
                'Motivo: ',
            ],
            'evento atualizado' => [
                fn (string $text) => new EventUpdatedNotification('Workshop de Liderança', $text),
                'Justificação: ',
            ],
            'evento cancelado' => [
                fn (string $text) => new EventCancelledNotification('Workshop de Liderança', '25/11/2026', '10:00', $text),
                'Justificação: ',
            ],
        ];
    }

    /**
     * Notificações de eventos alterados ou cancelados, a partir do nome do evento.
     *
     * @return array<string, array{0: Closure(string): object, 1: string}>
     */
    public static function eventNameNotifications(): array
    {
        return [
            'evento atualizado' => [
                fn (string $name) => new EventUpdatedNotification($name, 'Mudança de sala.'),
                'Evento atualizado: ',
            ],
            'evento cancelado' => [
                fn (string $name) => new EventCancelledNotification($name, '25/11/2026', '10:00', 'Falta de inscrições.'),
                'Evento cancelado: ',
            ],
        ];
    }

    private function mail(object $notification): MailMessage
    {
        return $notification->toMail(new AnonymousNotifiable);
    }

    private function html(object $notification): string
    {
        return (string) $this->mail($notification)->render();
    }

    #[Test]
    #[DataProvider('adminTextNotifications')]
    public function markdown_in_the_admin_text_does_not_become_a_link_or_bold(Closure $make, string $prefix): void
    {
        $html = $this->html($make(self::MALICIOUS));

        $this->assertStringNotContainsString('href="https://phish.pt"', $html);
        $this->assertStringNotContainsString('<strong>', $html);
        $this->assertStringContainsString($prefix.self::MALICIOUS, $html);
    }

    #[Test]
    #[DataProvider('adminTextNotifications')]
    public function plain_admin_text_is_shown_unchanged(Closure $make, string $prefix): void
    {
        $html = $this->html($make('Documentação em falta.'));

        $this->assertStringContainsString($prefix.'Documentação em falta.', $html);
        $this->assertStringNotContainsString('\\', $html);
    }

    #[Test]
    #[DataProvider('eventNameNotifications')]
    public function markdown_in_the_event_name_does_not_become_a_link(Closure $make, string $subjectPrefix): void
    {
        $html = $this->html($make(self::MALICIOUS_EVENT_NAME));

        $this->assertStringNotContainsString('href="https://phish.pt"', $html);
        $this->assertStringContainsString('O evento '.self::MALICIOUS_EVENT_NAME, $html);
    }

    #[Test]
    #[DataProvider('eventNameNotifications')]
    public function subject_keeps_the_event_name_without_backslashes(Closure $make, string $subjectPrefix): void
    {
        $mail = $this->mail($make(self::MALICIOUS_EVENT_NAME));

        $this->assertSame($subjectPrefix.self::MALICIOUS_EVENT_NAME, $mail->subject);
    }

    #[Test]
    public function event_announcement_keeps_the_maps_link_and_the_preferences_footer_link(): void
    {
        $event = EventInstance::factory()->create([
            'location_id' => Location::query()->where('name', 'Porto')->firstOrFail()->id,
            'venue_name' => 'Salão de eventos',
            'address' => 'Rua de Santa Catarina 100',
            'place_id' => null,
            'latitude' => null,
            'longitude' => null,
        ])->fresh();
        $mapsUrl = 'https://www.google.com/maps/search/?api=1&query='.rawurlencode('Rua de Santa Catarina 100');
        $preferencesUrl = rtrim(config('app.frontend_url'), '/').'/perfil/conta';

        $html = (string) (new NewEventAnnouncementNotification($event))
            ->toMail(User::factory()->create())
            ->render();

        $this->assertNotNull($this->anchorTagFor($html, $mapsUrl), 'O link do mapa deixou de ser um link.');
        $this->assertNotNull($this->anchorTagFor($html, $preferencesUrl), 'O rodapé das preferências deixou de ter link.');
    }
}
