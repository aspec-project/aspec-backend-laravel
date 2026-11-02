<?php

namespace Tests\Feature\PrayerCards;

use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsPrayerCards;
use Tests\TestCase;

/**
 * Opções de cartões no evento (backoffice) e o que o público vê.
 *
 * O admin ativa os cartões e define o versículo. A partir do 1.º cartão preenchido não pode mudar a
 * ativação, o versículo, a data nem a hora de início: o que o autor viu é o que o destinatário vê.
 */
class PrayerCardEventOptionsTest extends TestCase
{
    use BuildsPrayerCards, RefreshDatabase;

    private const URL = '/api/admin/events';

    private const LOCK_MESSAGE = 'Não é possível alterar os cartões de oração, a data ou a hora depois de existirem cartões preenchidos.';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelToLisbon('2027-06-01 12:00');
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(array $overrides = []): array
    {
        return [
            'category_id' => EventCategory::query()->firstOrFail()->id,
            'location_id' => Location::query()->firstOrFail()->id,
            'name' => 'Pequeno-almoço de oração',
            'event_date' => '2027-06-20',
            'event_start_time' => '08:00',
            'event_end_time' => '10:00',
            'description' => 'Pequeno-almoço com momento de oração.',
            'address' => 'Rua de teste, 10',
            'latitude' => 38.7223,
            'longitude' => -9.1393,
            'max_attendees' => 30,
            'registration_deadline' => '2027-06-18',
            'is_online' => false,
            'prayer_cards_enabled' => true,
            'prayer_verse' => self::VERSE,
            ...$overrides,
        ];
    }

    /**
     * Corpo de atualização com os valores atuais do evento.
     *
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
            'prayer_cards_enabled' => (bool) $event->prayer_cards_enabled,
            'prayer_verse' => $event->prayer_verse,
            ...$overrides,
        ];
    }

    private function eventWithACard(): EventInstance
    {
        $event = $this->prayerEvent('2027-06-10 19:00');
        $this->cardFor($this->registeredMember($event), $event);

        return $event;
    }

    #[Test]
    public function admin_creates_an_event_with_prayer_cards_and_verse(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson(self::URL, $this->createPayload())
            ->assertCreated()
            ->assertJsonPath('data.prayer_cards_enabled', true)
            ->assertJsonPath('data.prayer_verse', self::VERSE);

        $this->assertDatabaseHas('event_instances', [
            'id' => $response->json('data.id'),
            'prayer_cards_enabled' => true,
            'prayer_verse' => self::VERSE,
            'prayer_cards_distributed_at' => null,
        ]);
    }

    #[Test]
    public function events_without_the_option_have_prayer_cards_disabled(): void
    {
        $this->actingAsAdmin();
        $payload = $this->createPayload();
        unset($payload['prayer_cards_enabled'], $payload['prayer_verse']);

        $response = $this->postJson(self::URL, $payload)->assertCreated();

        $this->assertDatabaseHas('event_instances', [
            'id' => $response->json('data.id'),
            'prayer_cards_enabled' => false,
            'prayer_verse' => null,
        ]);
    }

    #[Test]
    public function enabling_prayer_cards_requires_a_verse(): void
    {
        $this->actingAsAdmin();

        foreach ([['prayer_verse' => null], ['prayer_verse' => '']] as $override) {
            $this->postJson(self::URL, $this->createPayload($override))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['prayer_verse']);
        }

        $this->assertDatabaseCount('event_instances', 0);
    }

    #[Test]
    public function verse_is_limited_to_500_characters_and_the_flag_must_be_boolean(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->createPayload(['prayer_verse' => str_repeat('v', 501)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prayer_verse']);

        $this->postJson(self::URL, $this->createPayload(['prayer_cards_enabled' => 'talvez']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prayer_cards_enabled']);

        $this->assertDatabaseCount('event_instances', 0);
    }

    #[Test]
    public function verse_is_stored_as_null_when_prayer_cards_are_disabled(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson(self::URL, $this->createPayload([
            'prayer_cards_enabled' => false,
            'prayer_verse' => 'Versículo que não deve ficar.',
        ]))->assertCreated();

        $this->assertDatabaseHas('event_instances', [
            'id' => $response->json('data.id'),
            'prayer_cards_enabled' => false,
            'prayer_verse' => null,
        ]);
    }

    #[Test]
    public function series_copies_the_prayer_card_options_to_every_session(): void
    {
        $this->actingAsAdmin();
        $payload = $this->createPayload([
            'recurrence' => [
                'frequency' => 'weekly',
                'ends_on' => '2027-07-04',
                'registration_deadline_days_before' => 2,
            ],
        ]);
        unset($payload['registration_deadline']);

        $this->postJson(self::URL, $payload)
            ->assertCreated()
            ->assertJsonCount(3, 'data.sessions');

        $sessions = EventInstance::query()->whereNotNull('series_id')->get();
        $this->assertCount(3, $sessions);
        foreach ($sessions as $session) {
            $this->assertTrue((bool) $session->prayer_cards_enabled);
            $this->assertSame(self::VERSE, $session->prayer_verse);
        }
    }

    #[Test]
    public function without_cards_the_admin_can_change_the_verse_and_disable_the_cards(): void
    {
        $this->actingAsAdmin();
        $event = $this->prayerEvent('2027-06-10 19:00');

        $this->putJson(self::URL."/{$event->id}", $this->updatePayload($event, ['prayer_verse' => 'Salmo 23']))
            ->assertOk();
        $this->assertDatabaseHas('event_instances', ['id' => $event->id, 'prayer_verse' => 'Salmo 23']);

        $this->putJson(self::URL."/{$event->id}", $this->updatePayload($event->fresh(), ['prayer_cards_enabled' => false]))
            ->assertOk();
        $this->assertDatabaseHas('event_instances', ['id' => $event->id, 'prayer_cards_enabled' => false, 'prayer_verse' => null]);
    }

    #[Test]
    public function with_cards_the_verse_cannot_change(): void
    {
        $this->actingAsAdmin();
        $event = $this->eventWithACard();

        $this->putJson(self::URL."/{$event->id}", $this->updatePayload($event, ['prayer_verse' => 'Salmo 23']))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Os dados enviados são inválidos.')
            ->assertJsonPath('errors.prayer_verse.0', self::LOCK_MESSAGE);

        $this->assertDatabaseHas('event_instances', ['id' => $event->id, 'prayer_verse' => self::VERSE]);
    }

    #[Test]
    public function with_cards_the_cards_cannot_be_disabled(): void
    {
        $this->actingAsAdmin();
        $event = $this->eventWithACard();

        $this->putJson(self::URL."/{$event->id}", $this->updatePayload($event, ['prayer_cards_enabled' => false]))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Os dados enviados são inválidos.')
            ->assertJsonPath('errors.prayer_verse.0', self::LOCK_MESSAGE);

        $this->assertDatabaseHas('event_instances', ['id' => $event->id, 'prayer_cards_enabled' => true]);
    }

    #[Test]
    public function with_cards_the_date_cannot_change(): void
    {
        $this->actingAsAdmin();
        $event = $this->eventWithACard();

        $this->putJson(self::URL."/{$event->id}", $this->updatePayload($event, ['event_date' => '2027-06-11']))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Os dados enviados são inválidos.')
            ->assertJsonPath('errors.prayer_verse.0', self::LOCK_MESSAGE);

        $this->assertSame('2027-06-10', $event->fresh()->event_date->format('Y-m-d'));
    }

    #[Test]
    public function with_cards_the_start_time_cannot_change(): void
    {
        $this->actingAsAdmin();
        $event = $this->eventWithACard();

        $this->putJson(self::URL."/{$event->id}", $this->updatePayload($event, ['event_start_time' => '18:00']))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Os dados enviados são inválidos.')
            ->assertJsonPath('errors.prayer_verse.0', self::LOCK_MESSAGE);

        $this->assertSame('19:00', substr((string) $event->fresh()->event_start_time, 0, 5));
    }

    #[Test]
    public function with_cards_other_fields_can_still_change(): void
    {
        $this->actingAsAdmin();
        $event = $this->eventWithACard();

        $this->putJson(self::URL."/{$event->id}", $this->updatePayload($event, [
            'name' => 'Nome corrigido',
            'event_end_time' => '22:00',
        ]))->assertOk();

        $this->assertDatabaseHas('event_instances', ['id' => $event->id, 'name' => 'Nome corrigido', 'prayer_verse' => self::VERSE]);
    }

    #[Test]
    public function deleting_an_event_on_its_own_day_deletes_its_cards(): void
    {
        $this->travelToLisbon('2027-06-10 20:00');
        $this->actingAsAdmin();
        $event = $this->eventWithACard();
        $other = $this->prayerEvent('2027-06-12 19:00');
        $otherCard = $this->cardFor($this->registeredMember($other), $other);

        $this->deleteJson(self::URL."/{$event->id}", ['justification' => 'Evento cancelado por motivos de saúde.'])
            ->assertOk();

        $this->assertDatabaseMissing('event_instances', ['id' => $event->id]);
        $this->assertDatabaseMissing('prayer_cards', ['event_instance_id' => $event->id]);
        $this->assertDatabaseHas('prayer_cards', ['id' => $otherCard->id]);
    }

    #[Test]
    public function public_event_shows_whether_it_has_prayer_cards_and_the_verse_only(): void
    {
        $withCards = $this->eventWithACard();
        $withoutCards = $this->prayerEvent('2027-06-12 19:00', ['prayer_cards_enabled' => false, 'prayer_verse' => null]);

        $this->getJson("/api/events/{$withCards->id}")
            ->assertOk()
            ->assertJsonPath('data.prayer_cards_enabled', true)
            ->assertJsonPath('data.prayer_verse', self::VERSE)
            ->assertJsonMissingPath('data.prayer_cards_count')
            ->assertJsonMissingPath('data.prayer_cards_locked');

        $this->getJson("/api/events/{$withoutCards->id}")
            ->assertOk()
            ->assertJsonPath('data.prayer_cards_enabled', false)
            ->assertJsonPath('data.prayer_verse', null);
    }

    #[Test]
    public function admin_show_reports_whether_the_prayer_card_options_are_locked(): void
    {
        $this->actingAsAdmin();
        $withCard = $this->eventWithACard();
        $withoutCards = $this->prayerEvent('2027-06-12 19:00');

        $this->getJson(self::URL."/{$withCard->id}")
            ->assertOk()
            ->assertJsonPath('data.prayer_cards_count', 1)
            ->assertJsonPath('data.prayer_cards_locked', true);

        $this->getJson(self::URL."/{$withoutCards->id}")
            ->assertOk()
            ->assertJsonPath('data.prayer_cards_count', 0)
            ->assertJsonPath('data.prayer_cards_locked', false);
    }

    #[Test]
    public function admin_index_reports_whether_the_prayer_card_options_are_locked(): void
    {
        $this->actingAsAdmin();
        $withCard = $this->eventWithACard();
        $withoutCards = $this->prayerEvent('2027-06-12 19:00');

        $items = collect($this->getJson(self::URL)->assertOk()->json('data.items'))->keyBy('id');

        $this->assertEquals(1, $items[$withCard->id]['prayer_cards_count']);
        $this->assertTrue($items[$withCard->id]['prayer_cards_locked']);
        $this->assertEquals(0, $items[$withoutCards->id]['prayer_cards_count']);
        $this->assertFalse($items[$withoutCards->id]['prayer_cards_locked']);
    }

    #[Test]
    public function withdrawn_or_expired_cards_do_not_lock_the_options(): void
    {
        $this->actingAsAdmin();
        $event = $this->prayerEvent('2027-06-10 19:00');
        $this->cardFor($this->registeredMember($event), $event, [
            'personal_request' => null,
            'work_request' => null,
            'salvation_request' => null,
            'deleted_at' => now(),
        ]);

        $this->getJson(self::URL."/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.prayer_cards_count', 0)
            ->assertJsonPath('data.prayer_cards_locked', false);
    }

    #[Test]
    public function admin_show_reports_the_registrations_count_and_available_spots(): void
    {
        $this->actingAsAdmin();
        $event = $this->prayerEvent('2027-06-10 19:00', ['max_attendees' => 20]);
        $this->registeredMember($event);
        $this->registeredMember($event);

        $this->getJson(self::URL."/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.registrations_count', 2)
            ->assertJsonPath('data.available_spots', 18);
    }

    #[Test]
    public function update_response_reports_the_counts_and_the_prayer_card_lock(): void
    {
        $this->actingAsAdmin();
        $event = $this->eventWithACard();

        $this->putJson(self::URL."/{$event->id}", $this->updatePayload($event, ['name' => 'Nome corrigido']))
            ->assertOk()
            ->assertJsonPath('data.registrations_count', 1)
            ->assertJsonPath('data.available_spots', $event->max_attendees - 1)
            ->assertJsonPath('data.prayer_cards_count', 1)
            ->assertJsonPath('data.prayer_cards_locked', true);
    }
}
