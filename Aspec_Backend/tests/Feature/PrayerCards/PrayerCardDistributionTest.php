<?php

namespace Tests\Feature\PrayerCards;

use App\Models\AccountStatus;
use App\Models\EventInstance;
use App\Models\EventSeries;
use App\Models\PrayerCard;
use App\Models\User;
use App\Notifications\PrayerCardReceivedNotification;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Tests\Concerns\BuildsPrayerCards;
use Tests\TestCase;

/**
 * Distribuição em cadeia pelo comando prayer-cards:distribute (de hora a hora).
 *
 * Baralha os cartões e cada autor recebe o cartão do seguinte; o último recebe o do primeiro.
 * Com 2 ou mais cartões é um ciclo único (ninguém recebe o seu); com 1 o cartão fica sem par;
 * com 0 o evento só fica marcado. O comando só pega em eventos cujo prazo já fechou
 * (event_date antes de hoje em Lisboa) e nunca distribui duas vezes.
 */
class PrayerCardDistributionTest extends TestCase
{
    use BuildsPrayerCards, RefreshDatabase;

    private const COMMAND = 'prayer-cards:distribute';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelToLisbon('2027-06-11 10:00');
    }

    private function distribute(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();
    }

    /**
     * Destinatário do cartão de cada autor, por id do autor.
     *
     * @return array<string, string|null>
     */
    private function recipientsByAuthor(EventInstance $event): array
    {
        return PrayerCard::withTrashed()
            ->where('event_instance_id', $event->id)
            ->pluck('recipient_user_id', 'author_user_id')
            ->all();
    }

    /**
     * Cada autor recebe exatamente um cartão, nunca o seu, e seguir "autor → destinatário" percorre todos num só ciclo.
     *
     * @param  list<User>  $members
     */
    private function assertSingleCycle(EventInstance $event, array $members): void
    {
        $recipientOf = $this->recipientsByAuthor($event);
        $authorIds = array_map(fn (User $member) => $member->id, $members);

        $this->assertEqualsCanonicalizing($authorIds, array_keys($recipientOf));
        $this->assertEqualsCanonicalizing($authorIds, array_values($recipientOf));

        foreach ($recipientOf as $authorId => $recipientId) {
            $this->assertNotSame($authorId, $recipientId, 'Um membro recebeu o próprio cartão.');
        }

        $start = $authorIds[0];
        $current = $start;
        $steps = 0;

        do {
            $current = $recipientOf[$current];
            $steps++;
        } while ($current !== $start && $steps <= count($authorIds));

        $this->assertSame(count($authorIds), $steps, 'A distribuição não formou um ciclo único.');
    }

    #[Test]
    public function five_cards_form_a_single_cycle_without_anyone_receiving_their_own_card(): void
    {
        $members = [];
        for ($i = 0; $i < 5; $i++) {
            $members[] = $this->member();
        }

        $events = [];
        for ($run = 0; $run < 50; $run++) {
            $event = $this->prayerEvent('2027-06-10 19:00');
            foreach ($members as $member) {
                $this->register($member, $event);
                $this->cardFor($member, $event);
            }
            $events[] = $event;
        }

        $this->distribute();

        foreach ($events as $event) {
            $this->assertSingleCycle($event, $members);
            $this->assertNotNull($event->fresh()->prayer_cards_distributed_at);
            $this->assertSame(0, PrayerCard::query()
                ->where('event_instance_id', $event->id)
                ->whereNull('received_at')
                ->count());
        }
    }

    #[Test]
    public function two_cards_are_swapped(): void
    {
        $event = $this->prayerEvent('2027-06-10 19:00');
        [$first, $second] = $this->membersWithCards($event, 2);

        $this->distribute();

        $recipientOf = $this->recipientsByAuthor($event);
        $this->assertSame($second->id, $recipientOf[$first->id]);
        $this->assertSame($first->id, $recipientOf[$second->id]);
    }

    #[Test]
    public function received_at_is_the_distribution_moment(): void
    {
        $event = $this->prayerEvent('2027-06-10 19:00');
        $this->membersWithCards($event, 3);

        $this->distribute();

        foreach (PrayerCard::query()->where('event_instance_id', $event->id)->get() as $card) {
            $this->assertNotNull($card->received_at);
            $this->assertLessThanOrEqual(1, abs($card->received_at->diffInSeconds(now())));
        }
        $this->assertNotNull($event->fresh()->prayer_cards_distributed_at);
    }

    #[Test]
    public function a_single_card_stays_unpaired_without_email(): void
    {
        $event = $this->prayerEvent('2027-06-10 19:00');
        [$author] = $this->membersWithCards($event, 1);

        $this->distribute();

        $card = PrayerCard::query()->where('event_instance_id', $event->id)->sole();
        $this->assertNull($card->recipient_user_id);
        $this->assertNull($card->received_at);
        $this->assertNotNull($event->fresh()->prayer_cards_distributed_at);
        Notification::assertNothingSent();

        Sanctum::actingAs($author);
        $this->getJson($this->cardUrl($event))
            ->assertOk()
            ->assertJsonPath('data.distribution_status', 'unpaired');
    }

    #[Test]
    public function event_without_cards_is_only_marked_as_distributed(): void
    {
        $event = $this->prayerEvent('2027-06-10 19:00');
        $this->registeredMember($event);

        $this->distribute();

        $this->assertNotNull($event->fresh()->prayer_cards_distributed_at);
        Notification::assertNothingSent();
    }

    #[Test]
    public function cards_of_inactive_authors_or_authors_without_profile_are_left_out(): void
    {
        $event = $this->prayerEvent('2027-06-10 19:00');
        [$first, $second] = $this->membersWithCards($event, 2);

        $inactive = $this->registeredMember($event, User::factory()->inactive()->create());
        $withoutProfile = User::factory()->create();
        $this->register($withoutProfile, $event);
        $this->cardFor($inactive, $event);
        $this->cardFor($withoutProfile, $event);

        $this->distribute();

        $recipientOf = $this->recipientsByAuthor($event);
        $this->assertSame($second->id, $recipientOf[$first->id]);
        $this->assertSame($first->id, $recipientOf[$second->id]);
        $this->assertNull($recipientOf[$inactive->id]);
        $this->assertNull($recipientOf[$withoutProfile->id]);
        $this->assertNotContains($inactive->id, $recipientOf);
        $this->assertNotContains($withoutProfile->id, $recipientOf);
    }

    #[Test]
    public function withdrawn_cards_are_not_distributed(): void
    {
        $event = $this->prayerEvent('2027-06-10 19:00');
        [$first, $second] = $this->membersWithCards($event, 2);
        $withdrawn = $this->registeredMember($event);
        $this->cardFor($withdrawn, $event, ['deleted_at' => now()]);

        $this->distribute();

        $recipientOf = $this->recipientsByAuthor($event);
        $this->assertSame($second->id, $recipientOf[$first->id]);
        $this->assertNull($recipientOf[$withdrawn->id]);
        $this->assertNotContains($withdrawn->id, $recipientOf);
    }

    #[Test]
    public function running_the_command_again_changes_nothing(): void
    {
        $event = $this->prayerEvent('2027-06-10 19:00');
        $this->membersWithCards($event, 4);

        $this->distribute();
        $before = PrayerCard::query()->where('event_instance_id', $event->id)
            ->get(['id', 'recipient_user_id', 'received_at'])->keyBy('id')->toArray();
        $distributedAt = $event->fresh()->prayer_cards_distributed_at;

        $this->travelToLisbon('2027-06-11 11:00');
        $this->distribute();

        $after = PrayerCard::query()->where('event_instance_id', $event->id)
            ->get(['id', 'recipient_user_id', 'received_at'])->keyBy('id')->toArray();
        $this->assertEquals($before, $after);
        $this->assertTrue($distributedAt->equalTo($event->fresh()->prayer_cards_distributed_at));
        Notification::assertSentTimes(PrayerCardReceivedNotification::class, 4);
    }

    #[Test]
    public function command_only_distributes_events_whose_window_has_closed(): void
    {
        $yesterday = $this->prayerEvent('2027-06-10 19:00');
        $this->membersWithCards($yesterday, 2);

        $today = $this->prayerEvent('2027-06-11 09:00');
        $this->membersWithCards($today, 2);

        $withoutCards = $this->prayerEvent('2027-06-10 19:00', ['prayer_cards_enabled' => false, 'prayer_verse' => null]);

        $this->distribute();

        $this->assertNotNull($yesterday->fresh()->prayer_cards_distributed_at);
        $this->assertNull($today->fresh()->prayer_cards_distributed_at);
        $this->assertSame(0, PrayerCard::query()->where('event_instance_id', $today->id)->whereNotNull('recipient_user_id')->count());
        $this->assertNull($withoutCards->fresh()->prayer_cards_distributed_at);
    }

    #[Test]
    public function command_catches_up_on_events_missed_while_the_server_was_down(): void
    {
        $event = $this->prayerEvent('2027-06-01 19:00');
        $this->membersWithCards($event, 3);

        $this->distribute();

        $this->assertNotNull($event->fresh()->prayer_cards_distributed_at);
    }

    #[Test]
    public function each_recipient_gets_an_email_without_content_or_author_name(): void
    {
        $event = $this->prayerEvent('2027-06-10 19:00');
        $first = $this->registeredMember($event, profile: ['name' => 'Autora Ana Sigilosa']);
        $second = $this->registeredMember($event, profile: ['name' => 'Autor Bruno Discreto']);
        $this->cardFor($first, $event, [
            'personal_request' => 'Pedido pessoal da Ana.',
            'work_request' => 'Pedido de emprego da Ana.',
            'salvation_request' => 'Pedido de salvação da Ana.',
        ]);
        $this->cardFor($second, $event, [
            'personal_request' => 'Pedido pessoal do Bruno.',
            'work_request' => 'Pedido de emprego do Bruno.',
            'salvation_request' => 'Pedido de salvação do Bruno.',
        ]);

        $this->distribute();

        Notification::assertSentTimes(PrayerCardReceivedNotification::class, 2);

        foreach ([$first, $second] as $recipient) {
            Notification::assertSentTo($recipient, PrayerCardReceivedNotification::class);
            $mail = (string) Notification::sent($recipient, PrayerCardReceivedNotification::class)
                ->first()
                ->toMail($recipient)
                ->render();

            foreach (['Ana Sigilosa', 'Bruno Discreto', 'Pedido pessoal', 'Pedido de emprego', 'Pedido de salvação', $first->email, $second->email, $first->phone, $second->phone] as $secret) {
                $this->assertStringNotContainsString($secret, $mail);
            }
        }
    }

    #[Test]
    public function queued_email_is_not_sent_when_the_recipient_is_no_longer_active(): void
    {
        $event = $this->prayerEvent('2027-06-10 19:00');
        [$first] = $this->membersWithCards($event, 2);

        $this->distribute();

        $notification = Notification::sent($first, PrayerCardReceivedNotification::class)->first();
        $this->assertTrue($notification->shouldSend($first->fresh(), 'mail'));

        $first->forceFill(['account_status_id' => AccountStatus::where('name', 'Inactive')->value('id')])->save();

        $this->assertFalse($notification->shouldSend($first->fresh(), 'mail'));
    }

    /**
     * Sessões seguidas de uma série com os mesmos 4 membros: a distribuição tenta evitar os pares
     * (autor → destinatário) da sessão anterior. Com 4 cartões só 1 das 6 cadeias possíveis não repete
     * nenhum par, por isso sem esta regra quase todas as sessões repetiriam (≈ 83 %); com até 10
     * tentativas a repetição é rara (≈ 16 %). O Randomizer é injetado com semente pelo contentor para
     * o resultado ser reprodutível; o teste verifica a proporção, que não depende da semente.
     */
    #[Test]
    public function series_session_avoids_the_pairs_of_the_previous_session(): void
    {
        $members = [];
        for ($i = 0; $i < 4; $i++) {
            $members[] = $this->member();
        }

        $sessionsWithRepeatedPairs = 0;

        for ($seed = 1; $seed <= 50; $seed++) {
            $series = EventSeries::create([
                'frequency' => 'weekly',
                'ends_on' => '2027-12-31',
                'registration_deadline_days_before' => 1,
            ]);

            $previous = $this->prayerEvent('2027-06-03 19:00', ['series_id' => $series->id, 'prayer_cards_distributed_at' => now()->subWeek()]);
            $current = $this->prayerEvent('2027-06-10 19:00', ['series_id' => $series->id]);

            $previousPairs = [];
            foreach ($members as $index => $member) {
                $recipient = $members[($index + 1) % 4];
                $this->register($member, $previous);
                $this->cardFor($member, $previous, ['recipient_user_id' => $recipient->id, 'received_at' => now()->subWeek()]);
                $previousPairs[] = $member->id.'>'.$recipient->id;

                $this->register($member, $current);
                $this->cardFor($member, $current);
            }

            $this->app->instance(Randomizer::class, new Randomizer(new Xoshiro256StarStar($seed)));
            $this->distribute();

            $this->assertSingleCycle($current, $members);

            $currentPairs = collect($this->recipientsByAuthor($current))
                ->map(fn ($recipientId, $authorId) => $authorId.'>'.$recipientId)
                ->values()
                ->all();

            if (array_intersect($currentPairs, $previousPairs) !== []) {
                $sessionsWithRepeatedPairs++;
            }
        }

        $this->assertLessThanOrEqual(20, $sessionsWithRepeatedPairs);
    }

    #[Test]
    public function command_is_scheduled_hourly_without_overlapping(): void
    {
        $this->app->make(Kernel::class)->bootstrap();

        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event) => str_contains((string) $event->command, self::COMMAND));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(55, $event->expiresAt);
    }
}
