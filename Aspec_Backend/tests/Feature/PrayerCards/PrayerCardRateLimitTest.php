<?php

namespace Tests\Feature\PrayerCards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsPrayerCards;
use Tests\Concerns\FillsRateLimits;
use Tests\TestCase;

/**
 * Limitador prayer-cards (120/min e 2000/hora por utilizador), partilhado pelas rotas do membro.
 */
class PrayerCardRateLimitTest extends TestCase
{
    use BuildsPrayerCards, FillsRateLimits, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelToLisbon('2027-06-10 20:00');
    }

    #[Test]
    public function the_121st_request_in_a_minute_is_throttled(): void
    {
        $event = $this->prayerEvent();
        $member = $this->registeredMember($event);
        Sanctum::actingAs($member);
        $this->fillRateLimit('prayer-cards', 'minute:'.$member->id, 119);

        $this->getJson($this->cardUrl($event))->assertOk();

        $this->putJson($this->cardUrl($event), $this->cardPayload())
            ->assertTooManyRequests()
            ->assertExactJson([
                'success' => false,
                'message' => 'Demasiados pedidos. Tente novamente mais tarde.',
            ]);

        $this->assertDatabaseCount('prayer_cards', 0);
    }

    #[Test]
    public function received_list_shares_the_prayer_cards_limit(): void
    {
        $member = $this->member();
        Sanctum::actingAs($member);
        $this->fillRateLimit('prayer-cards', 'minute:'.$member->id, 120);

        $this->getJson('/api/me/prayer-cards')->assertTooManyRequests();
    }

    #[Test]
    public function the_limit_is_per_member(): void
    {
        $event = $this->prayerEvent();
        $throttled = $this->registeredMember($event);
        $other = $this->registeredMember($event);
        $this->fillRateLimit('prayer-cards', 'minute:'.$throttled->id, 120);

        Sanctum::actingAs($other);
        $this->getJson($this->cardUrl($event))->assertOk();
    }
}
