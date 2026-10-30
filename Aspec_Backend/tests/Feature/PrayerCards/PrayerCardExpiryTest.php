<?php

namespace Tests\Feature\PrayerCards;

use App\Models\PrayerCard;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsPrayerCards;
use Tests\TestCase;

/**
 * Expiração (prayer-cards:expire, diário às 03:00 de Lisboa).
 *
 * Cartões de eventos com data há mais de 6 meses ficam com o conteúdo a null e soft delete; a linha
 * fica só como prova de presença. Hoje (Lisboa) = 2027-06-20, por isso o limite é 2026-12-20.
 */
class PrayerCardExpiryTest extends TestCase
{
    use BuildsPrayerCards, RefreshDatabase;

    private const COMMAND = 'prayer-cards:expire';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelToLisbon('2027-06-20 03:00');
    }

    private function receivedCardOnEventDay(string $eventDate): PrayerCard
    {
        $event = $this->prayerEvent($eventDate.' 19:00', ['prayer_cards_distributed_at' => now()]);

        return $this->cardFor($this->member(), $event, [
            'recipient_user_id' => $this->member()->id,
            'received_at' => now(),
        ]);
    }

    #[Test]
    public function cards_of_events_more_than_six_months_old_are_erased_and_soft_deleted(): void
    {
        $old = $this->receivedCardOnEventDay('2026-12-19');

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSoftDeleted('prayer_cards', ['id' => $old->id]);
        $this->assertNull($this->rawCardColumn($old, 'personal_request'));
        $this->assertNull($this->rawCardColumn($old, 'work_request'));
        $this->assertNull($this->rawCardColumn($old, 'salvation_request'));
        $this->assertDatabaseHas('prayer_cards', [
            'id' => $old->id,
            'event_instance_id' => $old->event_instance_id,
            'author_user_id' => $old->author_user_id,
        ]);
    }

    #[Test]
    public function cards_of_events_less_than_six_months_old_are_kept(): void
    {
        $recent = $this->receivedCardOnEventDay('2026-12-21');

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertDatabaseHas('prayer_cards', ['id' => $recent->id, 'deleted_at' => null]);
        $this->assertSame($recent->personal_request, $recent->fresh()->personal_request);
    }

    #[Test]
    public function cards_never_distributed_also_expire(): void
    {
        $event = $this->prayerEvent('2026-12-01 19:00', ['prayer_cards_distributed_at' => now()]);
        $unpaired = $this->cardFor($this->member(), $event);

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSoftDeleted('prayer_cards', ['id' => $unpaired->id]);
        $this->assertNull($this->rawCardColumn($unpaired, 'personal_request'));
    }

    #[Test]
    public function running_twice_changes_nothing_more(): void
    {
        $old = $this->receivedCardOnEventDay('2026-12-19');
        $recent = $this->receivedCardOnEventDay('2026-12-21');

        $this->artisan(self::COMMAND)->assertSuccessful();
        $deletedAt = $this->rawCardColumn($old, 'deleted_at');

        $this->travelToLisbon('2027-06-20 05:00');
        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertEquals($deletedAt, $this->rawCardColumn($old, 'deleted_at'));
        $this->assertNull($this->rawCardColumn($old, 'personal_request'));
        $this->assertDatabaseHas('prayer_cards', ['id' => $recent->id, 'deleted_at' => null]);
        $this->assertSame(2, PrayerCard::withTrashed()->count());
    }

    #[Test]
    public function expired_cards_leave_the_recipients_list(): void
    {
        $old = $this->receivedCardOnEventDay('2026-12-19');
        $recipient = $old->recipient;

        $this->artisan(self::COMMAND)->assertSuccessful();

        Sanctum::actingAs($recipient);
        $this->getJson('/api/me/prayer-cards')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    #[Test]
    public function command_is_scheduled_daily_at_three_lisbon_time(): void
    {
        $this->app->make(Kernel::class)->bootstrap();

        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event) => str_contains((string) $event->command, self::COMMAND));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertSame('Europe/Lisbon', (string) $event->timezone);
    }

    #[Test]
    public function expired_cards_also_forget_who_received_them(): void
    {
        $old = $this->receivedCardOnEventDay('2026-12-19');

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertNull($this->rawCardColumn($old, 'recipient_user_id'));
        $this->assertNull($this->rawCardColumn($old, 'received_at'));
        $this->assertSame($old->author_user_id, $this->rawCardColumn($old, 'author_user_id'));
        $this->assertNotNull($this->rawCardColumn($old, 'created_at'));
    }
}
