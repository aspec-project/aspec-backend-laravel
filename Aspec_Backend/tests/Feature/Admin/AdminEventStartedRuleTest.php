<?php

namespace Tests\Feature\Admin;

use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsPrayerCards;
use Tests\TestCase;

/**
 * Regra "o evento já começou" (data + hora de início, hora de Lisboa) no backoffice: editar e apagar
 * dão 409 depois do início, e o separador Agendados mostra o mesmo que a agenda pública.
 */
class AdminEventStartedRuleTest extends TestCase
{
    use BuildsPrayerCards, RefreshDatabase;

    private const URL = '/api/admin/events';

    private const STARTED_MESSAGE = 'Não é possível alterar um evento que já começou.';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Evento sem cartões de oração que começa em $startsAt (hora de Lisboa) e dura 2 horas.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function eventAt(string $startsAt, array $attributes = []): EventInstance
    {
        return $this->prayerEvent($startsAt, [
            'prayer_cards_enabled' => false,
            'prayer_verse' => null,
            ...$attributes,
        ]);
    }

    /**
     * Corpo de atualização com os valores atuais do evento (data e hora de início iguais às guardadas).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(EventInstance $event, array $overrides = []): array
    {
        return [
            'category_id' => $event->category_id,
            'location_id' => $event->location_id,
            'name' => $event->name,
            'event_date' => $event->event_date->format('Y-m-d'),
            'event_start_time' => substr((string) $event->event_start_time, 0, 5),
            'event_end_time' => substr((string) $event->event_end_time, 0, 5),
            'description' => $event->description,
            'address' => $event->address,
            'latitude' => 38.7223,
            'longitude' => -9.1393,
            'max_attendees' => $event->max_attendees,
            'registration_deadline' => Carbon::parse($event->getRawOriginal('registration_deadline'))->toDateString(),
            'is_online' => false,
            ...$overrides,
        ];
    }

    private function registerActiveMember(EventInstance $event): EventRegistration
    {
        return EventRegistration::query()->create([
            'event_instance_id' => $event->id,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * @return list<string>
     */
    private function itemIds(string $url): array
    {
        return array_column($this->getJson($url)->assertOk()->json('data.items'), 'id');
    }

    #[Test]
    public function updating_an_event_that_started_today_returns_conflict(): void
    {
        $this->travelToLisbon('2026-11-10 22:00');
        $this->actingAsAdmin();
        $event = $this->eventAt('2026-11-10 19:00', ['name' => 'Jantar de networking']);
        $this->registerActiveMember($event);

        $this->putJson(self::URL."/{$event->id}", $this->updatePayload($event, ['name' => 'Nome novo']))
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', self::STARTED_MESSAGE)
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('event_instances', ['id' => $event->id, 'name' => 'Jantar de networking']);
        Notification::assertNothingSent();
    }

    #[Test]
    public function deleting_an_event_that_started_today_returns_conflict_and_keeps_registrations(): void
    {
        $this->travelToLisbon('2026-11-10 19:30');
        $this->actingAsAdmin();
        $event = $this->eventAt('2026-11-10 19:00');
        $registration = $this->registerActiveMember($event);

        $this->deleteJson(self::URL."/{$event->id}", ['justification' => 'Evento cancelado pela organização.'])
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', self::STARTED_MESSAGE);

        $this->assertDatabaseHas('event_instances', ['id' => $event->id]);
        $this->assertDatabaseHas('event_registrations', ['id' => $registration->id]);
        Notification::assertNothingSent();
    }

    #[Test]
    public function event_today_that_has_not_started_can_still_be_updated_and_deleted(): void
    {
        $this->travelToLisbon('2026-11-10 18:59');
        $this->actingAsAdmin();
        $toUpdate = $this->eventAt('2026-11-10 19:00');
        $toDelete = $this->eventAt('2026-11-10 19:00');

        $this->putJson(self::URL."/{$toUpdate->id}", $this->updatePayload($toUpdate, ['name' => 'Nome novo']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Nome novo');

        $this->deleteJson(self::URL."/{$toDelete->id}")->assertOk();

        $this->assertDatabaseMissing('event_instances', ['id' => $toDelete->id]);
    }

    #[Test]
    public function event_starting_exactly_now_is_not_started_yet(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 19:00:00', self::TZ));
        $this->actingAsAdmin();
        $event = $this->eventAt('2026-11-10 19:00');

        $this->putJson(self::URL."/{$event->id}", $this->updatePayload($event, ['name' => 'Às 19:00 em ponto']))
            ->assertOk();

        $this->travelTo(Carbon::parse('2026-11-10 19:00:01', self::TZ));

        $this->putJson(self::URL."/{$event->id}", $this->updatePayload($event->fresh(), ['name' => 'Um segundo depois']))
            ->assertConflict()
            ->assertJsonPath('message', self::STARTED_MESSAGE);

        $this->assertDatabaseHas('event_instances', ['id' => $event->id, 'name' => 'Às 19:00 em ponto']);
    }

    #[Test]
    public function started_rule_uses_lisbon_time_in_summer(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 17:30:00', 'UTC'));
        $this->actingAsAdmin();
        $event = $this->eventAt('2026-07-15 18:00');

        $this->putJson(self::URL."/{$event->id}", $this->updatePayload($event, ['name' => 'Nome novo']))
            ->assertConflict()
            ->assertJsonPath('message', self::STARTED_MESSAGE);

        $this->deleteJson(self::URL."/{$event->id}")
            ->assertConflict()
            ->assertJsonPath('message', self::STARTED_MESSAGE);

        $this->assertDatabaseHas('event_instances', ['id' => $event->id, 'name' => $event->name]);
    }

    #[Test]
    public function has_started_matches_the_not_started_scope(): void
    {
        $event = $this->eventAt('2026-11-10 19:00');

        $moments = [
            '2026-11-10 18:59:59' => false,
            '2026-11-10 19:00:00' => false,
            '2026-11-10 19:00:01' => true,
        ];

        foreach ($moments as $moment => $expectedStarted) {
            $this->travelTo(Carbon::parse($moment, self::TZ));

            $notStarted = EventInstance::query()->notStarted()->whereKey($event->id)->exists();
            $hasStarted = $event->fresh()->hasStarted();

            $this->assertSame(! $hasStarted, $notStarted, "Scope e método diferem às {$moment}.");
            $this->assertSame($expectedStarted, $hasStarted, "Resultado inesperado às {$moment}.");
        }
    }

    #[Test]
    public function admin_index_moves_started_events_to_past(): void
    {
        $this->travelToLisbon('2026-11-10 15:00');
        $this->actingAsAdmin();
        $yesterday = $this->eventAt('2026-11-09 19:00');
        $todayMorning = $this->eventAt('2026-11-10 10:00');
        $todayEvening = $this->eventAt('2026-11-10 18:00');
        $tomorrow = $this->eventAt('2026-11-11 09:00');

        $this->getJson(self::URL.'?period=upcoming')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $todayEvening->id)
            ->assertJsonPath('data.items.1.id', $tomorrow->id)
            ->assertJsonPath('data.counts.upcoming', 2)
            ->assertJsonPath('data.counts.past', 2);

        $this->getJson(self::URL.'?period=past')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $todayMorning->id)
            ->assertJsonPath('data.items.1.id', $yesterday->id)
            ->assertJsonPath('data.counts.upcoming', 2)
            ->assertJsonPath('data.counts.past', 2);
    }

    #[Test]
    public function admin_upcoming_tab_matches_the_public_agenda(): void
    {
        $this->travelToLisbon('2026-11-10 15:00');
        $this->actingAsAdmin();
        $this->eventAt('2026-11-09 19:00');
        $this->eventAt('2026-11-10 10:00');
        $this->eventAt('2026-11-10 15:00');
        $this->eventAt('2026-11-10 18:00');
        $this->eventAt('2026-11-11 09:00');

        $adminIds = $this->itemIds(self::URL.'?period=upcoming&per_page=50');
        $publicIds = $this->itemIds('/api/events?per_page=100');

        $this->assertCount(3, $publicIds);
        $this->assertSame($publicIds, $adminIds);
    }

    #[Test]
    public function started_event_still_shows_and_distributes_prayer_cards_for_the_admin(): void
    {
        $this->travelToLisbon('2026-11-10 20:00');
        $event = $this->prayerEvent('2026-11-10 19:00');
        $this->membersWithCards($event, 2);
        $this->actingAsAdmin();

        $this->getJson(self::URL."/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $event->id);

        $this->getJson(self::URL."/{$event->id}/prayer-cards")
            ->assertOk()
            ->assertJsonPath('data.cards_count', 2);

        $this->postJson(self::URL."/{$event->id}/prayer-cards/distribute")
            ->assertOk()
            ->assertJsonPath('data.distributed_count', 2);
    }
}
