<?php

namespace Tests\Feature\PrayerCards;

use App\Models\EventInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsPrayerCards;
use Tests\TestCase;

/**
 * Retirar o cartão (DELETE /api/events/{id}/prayer-card), em qualquer momento (RGPD art. 7.º, n.º 3).
 *
 * Antes da distribuição a linha é apagada (deixa de contar como presença); depois, o conteúdo fica
 * null com soft delete e o destinatário deixa de o ver.
 */
class PrayerCardWithdrawTest extends TestCase
{
    use BuildsPrayerCards, RefreshDatabase;

    private EventInstance $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelToLisbon('2027-06-10 20:00');
        $this->event = $this->prayerEvent();
    }

    #[Test]
    public function withdrawing_before_the_distribution_deletes_the_row(): void
    {
        $author = $this->registeredMember($this->event);
        $card = $this->cardFor($author, $this->event);
        $other = $this->cardFor($this->registeredMember($this->event), $this->event);
        Sanctum::actingAs($author);

        $this->deleteJson($this->cardUrl($this->event))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Cartão de oração retirado.');

        $this->assertDatabaseMissing('prayer_cards', ['id' => $card->id]);
        $this->assertDatabaseHas('prayer_cards', ['id' => $other->id, 'deleted_at' => null]);

        $this->getJson($this->cardUrl($this->event))
            ->assertOk()
            ->assertJsonPath('data.card', null);
    }

    #[Test]
    public function withdrawing_after_the_deadline_but_before_the_distribution_also_deletes_the_row(): void
    {
        $author = $this->registeredMember($this->event);
        $card = $this->cardFor($author, $this->event);
        Sanctum::actingAs($author);
        $this->travelToLisbon('2027-06-11 00:20');

        $this->deleteJson($this->cardUrl($this->event))->assertOk();

        $this->assertDatabaseMissing('prayer_cards', ['id' => $card->id]);
    }

    #[Test]
    public function withdrawing_after_the_distribution_erases_the_content_and_hides_it_from_the_recipient(): void
    {
        $author = $this->registeredMember($this->event);
        $recipient = $this->registeredMember($this->event);
        $card = $this->cardFor($author, $this->event, [
            'personal_request' => 'Pedido muito pessoal.',
            'recipient_user_id' => $recipient->id,
            'received_at' => now(),
        ]);
        $this->cardFor($recipient, $this->event, ['recipient_user_id' => $author->id, 'received_at' => now()]);
        $this->event->forceFill(['prayer_cards_distributed_at' => now()])->save();

        Sanctum::actingAs($recipient);
        $this->getJson('/api/me/prayer-cards')->assertOk()->assertJsonCount(1, 'data.items');

        Sanctum::actingAs($author);
        $this->deleteJson($this->cardUrl($this->event))
            ->assertOk()
            ->assertJsonPath('message', 'Cartão de oração retirado.');

        $this->assertSoftDeleted('prayer_cards', ['id' => $card->id]);
        $this->assertNull($this->rawCardColumn($card, 'personal_request'));
        $this->assertNull($this->rawCardColumn($card, 'work_request'));
        $this->assertNull($this->rawCardColumn($card, 'salvation_request'));

        Sanctum::actingAs($recipient);
        $this->getJson('/api/me/prayer-cards')
            ->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertDontSee('Pedido muito pessoal.');
    }

    #[Test]
    public function withdrawing_without_a_card_returns_404(): void
    {
        Sanctum::actingAs($this->registeredMember($this->event));

        $this->deleteJson($this->cardUrl($this->event))
            ->assertNotFound()
            ->assertJsonPath('message', 'Não tem cartão de oração neste evento.');
    }

    #[Test]
    public function an_already_withdrawn_card_cannot_be_withdrawn_again(): void
    {
        $author = $this->registeredMember($this->event);
        $card = $this->cardFor($author, $this->event, ['recipient_user_id' => $this->member()->id, 'received_at' => now()]);
        $this->event->forceFill(['prayer_cards_distributed_at' => now()])->save();
        Sanctum::actingAs($author);

        $this->deleteJson($this->cardUrl($this->event))->assertOk();
        $deletedAt = $this->rawCardColumn($card, 'deleted_at');

        $this->deleteJson($this->cardUrl($this->event))
            ->assertNotFound()
            ->assertJsonPath('message', 'Não tem cartão de oração neste evento.');

        $this->assertEquals($deletedAt, $this->rawCardColumn($card, 'deleted_at'));
    }

    #[Test]
    public function member_cannot_withdraw_another_members_card(): void
    {
        $author = $this->registeredMember($this->event);
        $card = $this->cardFor($author, $this->event);
        Sanctum::actingAs($this->registeredMember($this->event));

        $this->deleteJson($this->cardUrl($this->event))->assertNotFound();

        $this->assertDatabaseHas('prayer_cards', ['id' => $card->id, 'deleted_at' => null]);
    }

    #[Test]
    public function guest_cannot_withdraw(): void
    {
        $card = $this->cardFor($this->registeredMember($this->event), $this->event);

        $this->deleteJson($this->cardUrl($this->event))->assertUnauthorized();

        $this->assertDatabaseHas('prayer_cards', ['id' => $card->id]);
    }

    #[Test]
    public function withdrawing_after_the_distribution_also_forgets_the_recipient(): void
    {
        $author = $this->registeredMember($this->event);
        $recipient = $this->registeredMember($this->event);
        $card = $this->cardFor($author, $this->event, ['recipient_user_id' => $recipient->id, 'received_at' => now()]);
        $this->cardFor($recipient, $this->event, ['recipient_user_id' => $author->id, 'received_at' => now()]);
        $this->event->forceFill(['prayer_cards_distributed_at' => now()])->save();
        Sanctum::actingAs($author);

        $this->deleteJson($this->cardUrl($this->event))->assertOk();

        $this->assertNull($this->rawCardColumn($card, 'recipient_user_id'));
        $this->assertNull($this->rawCardColumn($card, 'received_at'));
        $this->assertSame($author->id, $this->rawCardColumn($card, 'author_user_id'));
        $this->assertSame($this->event->id, $this->rawCardColumn($card, 'event_instance_id'));

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson("/api/admin/events/{$this->event->id}/prayer-cards")
            ->assertOk()
            ->assertJsonPath('data.cards_count', 1)
            ->assertJsonPath('data.distributed_count', 1)
            ->assertJsonPath('data.unpaired_count', 0);
    }
}
