<?php

namespace Tests\Feature\PrayerCards;

use App\Models\PrayerCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsPrayerCards;
use Tests\TestCase;

/**
 * "Os meus cartões de oração" (GET /api/me/prayer-cards): cartões que o membro recebeu.
 *
 * O destinatário vê o nome, email e telefone pessoais atuais do autor (lidos ao mostrar, não
 * copiados), a data do evento, as 3 partes e o versículo, durante 6 meses. "Esta semana"
 * (period=current) = pray_until (receção + 7 dias) ainda não passou; "Anteriores" = o resto.
 */
class ReceivedPrayerCardsTest extends TestCase
{
    use BuildsPrayerCards, RefreshDatabase;

    private const URL = '/api/me/prayer-cards';

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelToLisbon('2027-06-20 12:00');
        $this->recipient = $this->member();
    }

    /**
     * Cartão recebido por $this->recipient em $receivedAt (hora de Lisboa), num evento desse dia.
     */
    private function receivedCard(string $receivedAt, ?User $author = null, array $attributes = []): PrayerCard
    {
        $received = Carbon::parse($receivedAt, self::TZ);
        $event = $this->prayerEvent($received->copy()->subDay()->format('Y-m-d').' 19:00');
        $author ??= $this->member();

        return $this->cardFor($author, $event, [
            'recipient_user_id' => $this->recipient->id,
            'received_at' => $received->copy()->utc(),
            ...$attributes,
        ]);
    }

    #[Test]
    public function recipient_sees_the_full_card_with_the_authors_personal_contacts(): void
    {
        $author = $this->member(
            User::factory()->create(['email' => 'ana.pessoal@example.pt', 'phone' => '+351912345678']),
            ['name' => 'Ana Martins', 'commercial_email' => 'geral@empresa.pt', 'commercial_phone' => '+351213456789']
        );
        $card = $this->receivedCard('2027-06-18 10:00', $author, [
            'personal_request' => 'Saúde da família.',
            'work_request' => null,
            'salvation_request' => 'O meu irmão.',
        ]);

        $response = $this->actingAsRecipient()
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Cartões de oração obtidos com sucesso.')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $card->id)
            ->assertJsonPath('data.items.0.author.name', 'Ana Martins')
            ->assertJsonPath('data.items.0.author.email', 'ana.pessoal@example.pt')
            ->assertJsonPath('data.items.0.author.phone', '+351912345678')
            ->assertJsonPath('data.items.0.event.id', $card->event_instance_id)
            ->assertJsonPath('data.items.0.event.name', $card->eventInstance->name)
            ->assertJsonPath('data.items.0.event.event_date', '2027-06-17')
            ->assertJsonPath('data.items.0.personal_request', 'Saúde da família.')
            ->assertJsonPath('data.items.0.work_request', null)
            ->assertJsonPath('data.items.0.salvation_request', 'O meu irmão.')
            ->assertJsonPath('data.items.0.prayer_verse', self::VERSE)
            ->assertJsonStructure(['data' => [
                'items' => ['*' => ['received_at', 'pray_until', 'visible_until']],
                'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
            ]])
            ->assertDontSee('geral@empresa.pt')
            ->assertDontSee('+351213456789');

        $item = $response->json('data.items.0');
        $receivedAt = Carbon::parse('2027-06-18 10:00', self::TZ);
        $this->assertTrue(Carbon::parse($item['received_at'])->equalTo($receivedAt));
        $this->assertTrue(Carbon::parse($item['pray_until'])->equalTo($receivedAt->copy()->addDays(7)));
        $this->assertSame('2027-12-17', Carbon::parse($item['visible_until'])->toDateString());
    }

    #[Test]
    public function author_contacts_are_read_when_shown_so_changes_appear_immediately(): void
    {
        $author = $this->member(User::factory()->create(['phone' => '+351912345678']), ['name' => 'Nome Antigo']);
        $this->receivedCard('2027-06-18 10:00', $author);

        $this->actingAsRecipient()->getJson(self::URL)->assertJsonPath('data.items.0.author.phone', '+351912345678');

        $author->update(['phone' => '+351939876543', 'email' => 'novo.email@example.pt']);
        $author->memberProfile()->update(['name' => 'Nome Novo']);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.items.0.author.phone', '+351939876543')
            ->assertJsonPath('data.items.0.author.email', 'novo.email@example.pt')
            ->assertJsonPath('data.items.0.author.name', 'Nome Novo');
    }

    #[Test]
    public function only_my_received_cards_that_are_not_deleted_are_listed_newest_first(): void
    {
        $older = $this->receivedCard('2027-06-05 10:00');
        $newest = $this->receivedCard('2027-06-19 10:00');
        $middle = $this->receivedCard('2027-06-12 10:00');
        $deleted = $this->receivedCard('2027-06-18 10:00', attributes: ['deleted_at' => now(), 'personal_request' => null, 'work_request' => null, 'salvation_request' => null]);

        $someoneElse = $this->member();
        PrayerCard::factory()->create([
            'author_user_id' => $this->member()->id,
            'recipient_user_id' => $someoneElse->id,
            'received_at' => now(),
        ]);
        $this->cardFor($this->recipient, $this->prayerEvent('2027-06-17 19:00'), [
            'recipient_user_id' => $someoneElse->id,
            'received_at' => now(),
        ]);

        $ids = collect($this->actingAsRecipient()->getJson(self::URL)->assertOk()->json('data.items'))->pluck('id')->all();

        $this->assertSame([$newest->id, $middle->id, $older->id], $ids);
        $this->assertNotContains($deleted->id, $ids);
    }

    #[Test]
    public function list_is_paginated_with_10_per_page_by_default(): void
    {
        for ($day = 1; $day <= 12; $day++) {
            $this->receivedCard(sprintf('2027-06-%02d 10:00', $day));
        }

        $this->actingAsRecipient()->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(10, 'data.items')
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.per_page', 10)
            ->assertJsonPath('data.pagination.total', 12)
            ->assertJsonPath('data.pagination.last_page', 2);

        $this->getJson(self::URL.'?page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.current_page', 2);

        $this->getJson(self::URL.'?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data.items')
            ->assertJsonPath('data.pagination.per_page', 5)
            ->assertJsonPath('data.pagination.last_page', 3);
    }

    #[Test]
    public function per_page_above_50_or_below_1_is_rejected(): void
    {
        $this->actingAsRecipient();

        $this->getJson(self::URL.'?per_page=51')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->getJson(self::URL.'?per_page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->getJson(self::URL.'?per_page=50')->assertOk();
    }

    #[Test]
    public function period_current_lists_only_cards_still_in_their_prayer_week(): void
    {
        $thisWeek = $this->receivedCard('2027-06-18 10:00');
        $exactlySevenDays = $this->receivedCard('2027-06-13 12:00');
        $previous = $this->receivedCard('2027-06-13 11:59');

        $ids = collect($this->actingAsRecipient()->getJson(self::URL.'?period=current')->assertOk()->json('data.items'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$thisWeek->id, $exactlySevenDays->id], $ids);
        $this->assertNotContains($previous->id, $ids);
    }

    #[Test]
    public function period_previous_lists_the_rest(): void
    {
        $thisWeek = $this->receivedCard('2027-06-18 10:00');
        $previous = $this->receivedCard('2027-06-13 11:59');
        $older = $this->receivedCard('2027-05-01 10:00');

        $this->actingAsRecipient()->getJson(self::URL.'?period=previous')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $previous->id)
            ->assertJsonPath('data.items.1.id', $older->id)
            ->assertJsonPath('data.pagination.total', 2);

        $this->getJson(self::URL)->assertJsonPath('data.pagination.total', 3);
        $this->assertNotNull($thisWeek);
    }

    #[Test]
    public function invalid_period_is_rejected(): void
    {
        $this->actingAsRecipient()->getJson(self::URL.'?period=semana')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period']);
    }

    #[Test]
    public function guest_cannot_list_received_cards(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    #[Test]
    public function pending_and_inactive_accounts_cannot_list_received_cards(): void
    {
        foreach ([User::factory()->pending()->create(), User::factory()->inactive()->create()] as $user) {
            Sanctum::actingAs($user);

            $this->getJson(self::URL)->assertForbidden();
        }
    }

    private function actingAsRecipient(): static
    {
        Sanctum::actingAs($this->recipient);

        return $this;
    }
}
