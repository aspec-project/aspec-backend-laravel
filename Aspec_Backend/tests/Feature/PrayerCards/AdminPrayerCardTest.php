<?php

namespace Tests\Feature\PrayerCards;

use App\Models\EventInstance;
use App\Models\PrayerCard;
use App\Models\User;
use App\Notifications\PrayerCardReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsPrayerCards;
use Tests\TestCase;

/**
 * Estado dos cartões no backoffice e botão "Distribuir agora".
 *
 * O admin vê contagens e quem entregou o cartão (presença), nunca o conteúdo nem quem recebeu de
 * quem. O botão funciona a partir do início do evento e só uma vez.
 */
class AdminPrayerCardTest extends TestCase
{
    use BuildsPrayerCards, RefreshDatabase;

    private EventInstance $event;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelToLisbon('2027-06-10 21:00');
        $this->event = $this->prayerEvent();
    }

    private function statusUrl(EventInstance $event): string
    {
        return "/api/admin/events/{$event->id}/prayer-cards";
    }

    private function distributeUrl(EventInstance $event): string
    {
        return "/api/admin/events/{$event->id}/prayer-cards/distribute";
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    #[Test]
    public function admin_sees_counts_and_who_delivered_without_content(): void
    {
        $ana = $this->registeredMember($this->event, profile: ['name' => 'Ana Lopes', 'business_name' => 'Lopes Lda']);
        $rui = $this->registeredMember($this->event, profile: ['name' => 'Rui Sá', 'business_name' => 'Sá & Filhos']);
        $this->registeredMember($this->event);
        $this->cardFor($rui, $this->event, ['personal_request' => 'Segredo do Rui.', 'created_at' => Carbon::parse('2027-06-10 20:30', self::TZ)->utc()]);
        $this->cardFor($ana, $this->event, ['personal_request' => 'Segredo da Ana.', 'work_request' => 'Trabalho da Ana.', 'created_at' => Carbon::parse('2027-06-10 19:15', self::TZ)->utc()]);
        $this->actingAsAdmin();

        $response = $this->getJson($this->statusUrl($this->event))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Estado dos cartões de oração obtido com sucesso.')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.prayer_verse', self::VERSE)
            ->assertJsonPath('data.window.status', 'open')
            ->assertJsonPath('data.registrations_count', 3)
            ->assertJsonPath('data.cards_count', 2)
            ->assertJsonPath('data.distributed_count', 0)
            ->assertJsonPath('data.unpaired_count', 0)
            ->assertJsonPath('data.distributed_at', null)
            ->assertJsonCount(2, 'data.participants')
            ->assertJsonPath('data.participants.0.name', 'Ana Lopes')
            ->assertJsonPath('data.participants.0.business_name', 'Lopes Lda')
            ->assertJsonPath('data.participants.1.name', 'Rui Sá')
            ->assertJsonPath('data.participants.1.business_name', 'Sá & Filhos')
            ->assertDontSee('Segredo')
            ->assertDontSee('Trabalho da Ana.');

        foreach ($response->json('data.participants') as $participant) {
            $this->assertEqualsCanonicalizing(['name', 'business_name', 'submitted_at'], array_keys($participant));
        }
        $this->assertTrue(Carbon::parse($response->json('data.participants.0.submitted_at'))->equalTo(Carbon::parse('2027-06-10 19:15', self::TZ)));
    }

    #[Test]
    public function admin_status_after_distribution_never_reveals_pairs(): void
    {
        $members = $this->membersWithCards($this->event, 3);
        $this->actingAsAdmin();

        $this->postJson($this->distributeUrl($this->event))->assertOk();

        $response = $this->getJson($this->statusUrl($this->event))
            ->assertOk()
            ->assertJsonPath('data.cards_count', 3)
            ->assertJsonPath('data.distributed_count', 3)
            ->assertJsonPath('data.unpaired_count', 0)
            ->assertJsonPath('data.window.status', 'closed');

        $this->assertNotNull($response->json('data.distributed_at'));
        $this->assertStringNotContainsString('recipient', $response->getContent());
        foreach ($members as $member) {
            $response->assertDontSee($member->id);
            $response->assertDontSee($member->email);
        }
        foreach (PrayerCard::all() as $card) {
            $response->assertDontSee($card->personal_request);
            $response->assertDontSee($card->id);
        }
    }

    #[Test]
    public function withdrawn_cards_do_not_count_as_delivered(): void
    {
        $this->membersWithCards($this->event, 1);
        $this->cardFor($this->registeredMember($this->event, profile: ['name' => 'Retirou']), $this->event, ['deleted_at' => now()]);
        $this->actingAsAdmin();

        $this->getJson($this->statusUrl($this->event))
            ->assertOk()
            ->assertJsonPath('data.cards_count', 1)
            ->assertJsonCount(1, 'data.participants')
            ->assertDontSee('Retirou');
    }

    #[Test]
    public function status_of_an_event_without_prayer_cards_returns_404(): void
    {
        $event = $this->prayerEvent(attributes: ['prayer_cards_enabled' => false, 'prayer_verse' => null]);
        $this->actingAsAdmin();

        $this->getJson($this->statusUrl($event))->assertNotFound();
        $this->postJson($this->distributeUrl($event))->assertNotFound();

        $this->assertNull($event->fresh()->prayer_cards_distributed_at);
    }

    #[Test]
    public function members_cannot_see_the_status_nor_distribute(): void
    {
        $this->membersWithCards($this->event, 2);
        Sanctum::actingAs($this->registeredMember($this->event));

        $this->getJson($this->statusUrl($this->event))->assertForbidden();
        $this->postJson($this->distributeUrl($this->event))->assertForbidden();

        $this->assertNull($this->event->fresh()->prayer_cards_distributed_at);
    }

    #[Test]
    public function guests_cannot_see_the_status_nor_distribute(): void
    {
        $this->getJson($this->statusUrl($this->event))->assertUnauthorized();
        $this->postJson($this->distributeUrl($this->event))->assertUnauthorized();
    }

    #[Test]
    public function admin_distributes_early_after_the_event_started(): void
    {
        [$first, $second] = $this->membersWithCards($this->event, 2);
        $this->actingAsAdmin();

        $this->postJson($this->distributeUrl($this->event))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Cartões de oração distribuídos.')
            ->assertJsonPath('data.distributed_count', 2)
            ->assertJsonPath('data.unpaired_count', 0);

        $this->assertNotNull($this->event->fresh()->prayer_cards_distributed_at);
        $this->assertDatabaseHas('prayer_cards', ['author_user_id' => $first->id, 'recipient_user_id' => $second->id]);
        $this->assertDatabaseHas('prayer_cards', ['author_user_id' => $second->id, 'recipient_user_id' => $first->id]);
        Notification::assertSentTo($first, PrayerCardReceivedNotification::class);
        Notification::assertSentTo($second, PrayerCardReceivedNotification::class);
    }

    #[Test]
    public function distributing_before_the_event_starts_is_refused(): void
    {
        $this->membersWithCards($this->event, 2);
        $this->travelToLisbon('2027-06-10 18:59');
        $this->actingAsAdmin();

        $this->postJson($this->distributeUrl($this->event))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Ainda não é possível distribuir: o evento ainda não começou.');

        $this->assertNull($this->event->fresh()->prayer_cards_distributed_at);
        $this->assertSame(0, PrayerCard::query()->whereNotNull('recipient_user_id')->count());
    }

    #[Test]
    public function second_click_is_refused_and_never_redistributes(): void
    {
        $this->membersWithCards($this->event, 3);
        $this->actingAsAdmin();

        $this->postJson($this->distributeUrl($this->event))->assertOk();
        $pairs = PrayerCard::query()->pluck('recipient_user_id', 'author_user_id')->all();

        $this->postJson($this->distributeUrl($this->event))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Os cartões deste evento já foram distribuídos.');

        $this->assertSame($pairs, PrayerCard::query()->pluck('recipient_user_id', 'author_user_id')->all());
        Notification::assertSentTimes(PrayerCardReceivedNotification::class, 3);
    }

    #[Test]
    public function distributing_without_cards_only_marks_the_event(): void
    {
        $this->registeredMember($this->event);
        $this->actingAsAdmin();

        $this->postJson($this->distributeUrl($this->event))
            ->assertOk()
            ->assertJsonPath('data.cards_count', 0)
            ->assertJsonPath('data.distributed_count', 0);

        $this->assertNotNull($this->event->fresh()->prayer_cards_distributed_at);
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_single_card_is_reported_as_unpaired(): void
    {
        $this->membersWithCards($this->event, 1);
        $this->actingAsAdmin();

        $this->postJson($this->distributeUrl($this->event))
            ->assertOk()
            ->assertJsonPath('data.cards_count', 1)
            ->assertJsonPath('data.distributed_count', 0)
            ->assertJsonPath('data.unpaired_count', 1);

        Notification::assertNothingSent();
    }

    #[Test]
    public function distribution_is_limited_to_10_per_minute_per_admin(): void
    {
        $this->actingAsAdmin();
        $this->event->forceFill(['prayer_cards_distributed_at' => now()])->save();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson($this->distributeUrl($this->event))->assertStatus(409);
        }

        $this->postJson($this->distributeUrl($this->event))
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Demasiados pedidos. Tente novamente mais tarde.');
    }
}
