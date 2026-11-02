<?php

namespace Tests\Feature\PrayerCards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsPrayerCards;
use Tests\TestCase;

/**
 * Cartões de oração em User::anonymizeAndDelete().
 *
 * Os cartões escritos pela conta perdem o conteúdo e ficam com soft delete (dados religiosos de
 * quem pediu o apagamento); os recebidos são dados do autor e ficam até expirarem.
 */
class PrayerCardAnonymizationTest extends TestCase
{
    use BuildsPrayerCards, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->travelToLisbon('2027-06-11 10:00');
    }

    #[Test]
    public function cards_written_by_the_account_are_erased_and_soft_deleted(): void
    {
        $event = $this->prayerEvent('2027-06-10 19:00', ['prayer_cards_distributed_at' => now()]);
        $pendingEvent = $this->prayerEvent('2027-06-11 09:00');
        $member = $this->member();
        $recipient = $this->member();

        $distributed = $this->cardFor($member, $event, ['recipient_user_id' => $recipient->id, 'received_at' => now()]);
        $notDistributed = $this->cardFor($member, $pendingEvent);

        $member->anonymizeAndDelete();

        foreach ([$distributed, $notDistributed] as $card) {
            $this->assertSoftDeleted('prayer_cards', ['id' => $card->id]);
            $this->assertNull($this->rawCardColumn($card, 'personal_request'));
            $this->assertNull($this->rawCardColumn($card, 'work_request'));
            $this->assertNull($this->rawCardColumn($card, 'salvation_request'));
        }
    }

    #[Test]
    public function cards_received_by_the_account_are_kept(): void
    {
        $event = $this->prayerEvent('2027-06-10 19:00', ['prayer_cards_distributed_at' => now()]);
        $member = $this->member();
        $author = $this->member();
        $received = $this->cardFor($author, $event, [
            'personal_request' => 'Pedido do autor.',
            'recipient_user_id' => $member->id,
            'received_at' => now(),
        ]);

        $member->anonymizeAndDelete();

        $this->assertDatabaseHas('prayer_cards', [
            'id' => $received->id,
            'recipient_user_id' => $member->id,
            'deleted_at' => null,
        ]);
        $this->assertSame('Pedido do autor.', $received->fresh()->personal_request);
    }

    #[Test]
    public function other_members_cards_are_untouched(): void
    {
        $event = $this->prayerEvent('2027-06-10 19:00');
        $member = $this->member();
        $other = $this->cardFor($this->member(), $event, ['personal_request' => 'Pedido de outro membro.']);
        $this->cardFor($member, $event);

        $member->anonymizeAndDelete();

        $this->assertDatabaseHas('prayer_cards', ['id' => $other->id, 'deleted_at' => null]);
        $this->assertSame('Pedido de outro membro.', $other->fresh()->personal_request);
    }

    #[Test]
    public function erased_written_cards_also_forget_who_received_them(): void
    {
        $event = $this->prayerEvent('2027-06-10 19:00', ['prayer_cards_distributed_at' => now()]);
        $member = $this->member();
        $card = $this->cardFor($member, $event, ['recipient_user_id' => $this->member()->id, 'received_at' => now()]);

        $member->anonymizeAndDelete();

        $this->assertNull($this->rawCardColumn($card, 'recipient_user_id'));
        $this->assertNull($this->rawCardColumn($card, 'received_at'));
        $this->assertSame($member->id, $this->rawCardColumn($card, 'author_user_id'));
    }
}
