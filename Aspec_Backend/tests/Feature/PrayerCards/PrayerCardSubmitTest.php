<?php

namespace Tests\Feature\PrayerCards;

use App\Models\EventInstance;
use App\Models\PrayerCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsPrayerCards;
use Tests\TestCase;

/**
 * Preencher, editar e consultar o próprio cartão de oração (GET/PUT /api/events/{id}/prayer-card).
 *
 * O prazo vai do início do evento (19:00) às 23:59 desse dia, na hora de Lisboa; só membros Active,
 * com perfil e inscritos preenchem; um cartão por membro por evento.
 */
class PrayerCardSubmitTest extends TestCase
{
    use BuildsPrayerCards, RefreshDatabase;

    private EventInstance $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelToLisbon('2027-06-10 20:00');
        $this->event = $this->prayerEvent();
    }

    private function actingAsRegisteredMember(): User
    {
        $member = $this->registeredMember($this->event);
        Sanctum::actingAs($member);

        return $member;
    }

    #[Test]
    public function registered_member_submits_the_card_during_the_window(): void
    {
        $member = $this->actingAsRegisteredMember();

        $this->putJson($this->cardUrl($this->event), $this->cardPayload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Cartão de oração enviado com sucesso.');

        $card = PrayerCard::query()->where('author_user_id', $member->id)->sole();
        $this->assertSame($this->event->id, $card->event_instance_id);
        $this->assertSame('Saúde da minha mãe.', $card->personal_request);
        $this->assertSame('Um novo cliente para a empresa.', $card->work_request);
        $this->assertSame('O meu vizinho Rui.', $card->salvation_request);
        $this->assertNotNull($card->consent_given_at);
        $this->assertNull($card->recipient_user_id);
        $this->assertNull($card->received_at);
    }

    #[Test]
    public function window_opens_exactly_at_the_event_start_in_lisbon_time(): void
    {
        $this->actingAsRegisteredMember();
        $this->travelToLisbon('2027-06-10 19:00:00');

        $this->putJson($this->cardUrl($this->event), $this->cardPayload())
            ->assertCreated();
    }

    #[Test]
    public function card_is_still_accepted_at_23_59_lisbon_time(): void
    {
        $this->actingAsRegisteredMember();
        $this->travelToLisbon('2027-06-10 23:59:30');

        $this->putJson($this->cardUrl($this->event), $this->cardPayload())
            ->assertCreated();
    }

    #[Test]
    public function card_is_refused_before_the_event_starts(): void
    {
        $this->actingAsRegisteredMember();
        $this->travelToLisbon('2027-06-10 18:59:00');

        $this->putJson($this->cardUrl($this->event), $this->cardPayload())
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'O preenchimento do cartão abre no início do evento.');

        $this->assertDatabaseCount('prayer_cards', 0);
    }

    #[Test]
    public function card_is_refused_after_23_59_of_the_event_day(): void
    {
        $this->actingAsRegisteredMember();
        $this->travelToLisbon('2027-06-11 00:00:30');

        $this->putJson($this->cardUrl($this->event), $this->cardPayload())
            ->assertStatus(409)
            ->assertJsonPath('message', 'O prazo para preencher o cartão terminou.');

        $this->assertDatabaseCount('prayer_cards', 0);
    }

    #[Test]
    public function card_is_refused_after_the_admin_distributed_the_cards_early(): void
    {
        $member = $this->actingAsRegisteredMember();
        $this->cardFor($member, $this->event, ['personal_request' => 'Texto original.']);
        $this->event->forceFill(['prayer_cards_distributed_at' => now()])->save();

        $this->putJson($this->cardUrl($this->event), $this->cardPayload(['personal_request' => 'Texto novo.']))
            ->assertStatus(409)
            ->assertJsonPath('message', 'O prazo para preencher o cartão terminou.');

        $this->assertSame('Texto original.', PrayerCard::query()->sole()->personal_request);
    }

    #[Test]
    public function second_put_updates_the_same_card(): void
    {
        $member = $this->actingAsRegisteredMember();

        $this->putJson($this->cardUrl($this->event), $this->cardPayload())->assertCreated();

        $this->putJson($this->cardUrl($this->event), $this->cardPayload([
            'personal_request' => 'Pedido revisto.',
            'work_request' => null,
        ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Cartão de oração atualizado com sucesso.');

        $this->assertDatabaseCount('prayer_cards', 1);
        $card = PrayerCard::query()->where('author_user_id', $member->id)->sole();
        $this->assertSame('Pedido revisto.', $card->personal_request);
        $this->assertNull($card->work_request);
    }

    #[Test]
    public function editing_the_card_keeps_the_original_submission_time(): void
    {
        $this->actingAsRegisteredMember();

        $this->putJson($this->cardUrl($this->event), $this->cardPayload())->assertCreated();
        $submittedAt = $this->getJson($this->cardUrl($this->event))->json('data.card.submitted_at');

        $this->travelToLisbon('2027-06-10 21:30');
        $this->putJson($this->cardUrl($this->event), $this->cardPayload(['personal_request' => 'Outro pedido.']))
            ->assertOk();

        $card = $this->getJson($this->cardUrl($this->event))->json('data.card');
        $this->assertNotNull($submittedAt);
        $this->assertTrue(Carbon::parse($card['submitted_at'])->equalTo(Carbon::parse($submittedAt)));
        $this->assertTrue(Carbon::parse($card['updated_at'])->greaterThan(Carbon::parse($submittedAt)));
    }

    #[Test]
    public function one_filled_part_is_enough_and_empty_parts_are_stored_as_null(): void
    {
        $this->actingAsRegisteredMember();

        $this->putJson($this->cardUrl($this->event), $this->cardPayload([
            'work_request' => '',
            'salvation_request' => null,
        ]))->assertCreated();

        $card = PrayerCard::query()->sole();
        $this->assertSame('Saúde da minha mãe.', $card->personal_request);
        $this->assertNull($card->work_request);
        $this->assertNull($card->salvation_request);
    }

    #[Test]
    public function request_parts_are_encrypted_in_the_database(): void
    {
        $this->actingAsRegisteredMember();

        $this->putJson($this->cardUrl($this->event), $this->cardPayload())->assertCreated();

        $card = PrayerCard::query()->sole();
        $raw = $this->rawCardColumn($card, 'personal_request');

        $this->assertNotSame('Saúde da minha mãe.', $raw);
        $this->assertStringNotContainsString('Saúde', (string) $raw);
        $this->assertSame('Saúde da minha mãe.', Crypt::decryptString($raw));
        $this->assertStringNotContainsString('Um novo cliente', (string) $this->rawCardColumn($card, 'work_request'));
        $this->assertStringNotContainsString('Rui', (string) $this->rawCardColumn($card, 'salvation_request'));
    }

    #[Test]
    public function member_not_registered_in_the_event_cannot_submit(): void
    {
        Sanctum::actingAs($this->member());

        $this->putJson($this->cardUrl($this->event), $this->cardPayload())
            ->assertForbidden()
            ->assertJsonPath('message', 'Só os membros inscritos neste evento podem preencher o cartão de oração.');

        $this->assertDatabaseCount('prayer_cards', 0);
    }

    #[Test]
    public function registered_member_without_profile_cannot_submit(): void
    {
        $user = User::factory()->create();
        $this->register($user, $this->event);
        Sanctum::actingAs($user);

        $this->putJson($this->cardUrl($this->event), $this->cardPayload())
            ->assertForbidden()
            ->assertJsonPath('message', 'Só os membros inscritos neste evento podem preencher o cartão de oração.');

        $this->assertDatabaseCount('prayer_cards', 0);
    }

    #[Test]
    public function pending_and_inactive_accounts_cannot_submit(): void
    {
        foreach ([User::factory()->pending()->create(), User::factory()->inactive()->create()] as $user) {
            $this->registeredMember($this->event, $user);
            Sanctum::actingAs($user);

            $this->putJson($this->cardUrl($this->event), $this->cardPayload())
                ->assertForbidden();
        }

        $this->assertDatabaseCount('prayer_cards', 0);
    }

    #[Test]
    public function guest_cannot_submit_or_read_a_card(): void
    {
        $this->putJson($this->cardUrl($this->event), $this->cardPayload())->assertUnauthorized();
        $this->getJson($this->cardUrl($this->event))->assertUnauthorized();

        $this->assertDatabaseCount('prayer_cards', 0);
    }

    #[Test]
    public function event_without_prayer_cards_returns_404(): void
    {
        $event = $this->prayerEvent(attributes: ['prayer_cards_enabled' => false, 'prayer_verse' => null]);
        Sanctum::actingAs($this->registeredMember($event));

        $this->putJson($this->cardUrl($event), $this->cardPayload())
            ->assertNotFound()
            ->assertJsonPath('message', 'Este evento não tem cartões de oração.');

        $this->getJson($this->cardUrl($event))
            ->assertNotFound()
            ->assertJsonPath('message', 'Este evento não tem cartões de oração.');

        $this->assertDatabaseCount('prayer_cards', 0);
    }

    #[Test]
    public function unknown_event_returns_404(): void
    {
        $this->actingAsRegisteredMember();

        $this->putJson('/api/events/'.Str::uuid().'/prayer-card', $this->cardPayload())->assertNotFound();
    }

    #[Test]
    public function consent_is_required(): void
    {
        $this->actingAsRegisteredMember();

        foreach ([['consent' => false], ['consent' => null]] as $override) {
            $this->putJson($this->cardUrl($this->event), $this->cardPayload($override))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['consent']);
        }

        $payload = $this->cardPayload();
        unset($payload['consent']);
        $this->putJson($this->cardUrl($this->event), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['consent']);

        $this->assertDatabaseCount('prayer_cards', 0);
    }

    #[Test]
    public function at_least_one_request_part_is_required(): void
    {
        $this->actingAsRegisteredMember();

        $this->putJson($this->cardUrl($this->event), [
            'personal_request' => '',
            'work_request' => null,
            'consent' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['personal_request', 'work_request', 'salvation_request']);

        $this->assertDatabaseCount('prayer_cards', 0);
    }

    #[Test]
    public function each_request_part_is_limited_to_500_characters(): void
    {
        $this->actingAsRegisteredMember();

        foreach (['personal_request', 'work_request', 'salvation_request'] as $field) {
            $this->putJson($this->cardUrl($this->event), $this->cardPayload([$field => str_repeat('a', 501)]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }

        $this->assertDatabaseCount('prayer_cards', 0);

        $this->putJson($this->cardUrl($this->event), $this->cardPayload(['personal_request' => str_repeat('a', 500)]))
            ->assertCreated();
    }

    #[Test]
    public function registered_admin_with_profile_can_submit_like_any_member(): void
    {
        $admin = $this->registeredMember($this->event, User::factory()->admin()->create());
        Sanctum::actingAs($admin);

        $this->putJson($this->cardUrl($this->event), $this->cardPayload())->assertCreated();

        $this->assertDatabaseHas('prayer_cards', ['author_user_id' => $admin->id]);
    }

    #[Test]
    public function show_returns_the_verse_window_and_no_card_before_submitting(): void
    {
        $this->actingAsRegisteredMember();

        $response = $this->getJson($this->cardUrl($this->event))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Cartão de oração obtido com sucesso.')
            ->assertJsonPath('data.prayer_verse', self::VERSE)
            ->assertJsonPath('data.event_date', '2027-06-10')
            ->assertJsonPath('data.window.status', 'open')
            ->assertJsonPath('data.distribution_status', 'pending')
            ->assertJsonPath('data.card', null);

        $this->assertSame('2027-06-10 19:00', Carbon::parse($response->json('data.window.opens_at'))->setTimezone(self::TZ)->format('Y-m-d H:i'));
        $this->assertSame('2027-06-10 23:59', Carbon::parse($response->json('data.window.closes_at'))->setTimezone(self::TZ)->format('Y-m-d H:i'));
    }

    #[Test]
    public function show_returns_the_members_own_card(): void
    {
        $this->actingAsRegisteredMember();
        $this->putJson($this->cardUrl($this->event), $this->cardPayload(['salvation_request' => null]))->assertCreated();

        $this->getJson($this->cardUrl($this->event))
            ->assertOk()
            ->assertJsonPath('data.card.personal_request', 'Saúde da minha mãe.')
            ->assertJsonPath('data.card.work_request', 'Um novo cliente para a empresa.')
            ->assertJsonPath('data.card.salvation_request', null)
            ->assertJsonStructure(['data' => ['card' => ['submitted_at', 'updated_at']]]);
    }

    #[Test]
    public function show_reports_the_window_status_before_and_after_it(): void
    {
        $this->actingAsRegisteredMember();

        $this->travelToLisbon('2027-06-10 10:00');
        $this->getJson($this->cardUrl($this->event))->assertOk()->assertJsonPath('data.window.status', 'not_open');

        $this->travelToLisbon('2027-06-11 08:00');
        $this->getJson($this->cardUrl($this->event))->assertOk()->assertJsonPath('data.window.status', 'closed');
    }

    #[Test]
    public function window_is_closed_once_the_cards_were_distributed(): void
    {
        $this->actingAsRegisteredMember();
        $this->event->forceFill(['prayer_cards_distributed_at' => now()])->save();

        $this->getJson($this->cardUrl($this->event))->assertOk()->assertJsonPath('data.window.status', 'closed');
    }

    #[Test]
    public function show_is_refused_to_members_not_registered_in_the_event(): void
    {
        Sanctum::actingAs($this->member());

        $this->getJson($this->cardUrl($this->event))
            ->assertForbidden()
            ->assertJsonPath('message', 'Só os membros inscritos neste evento podem preencher o cartão de oração.');
    }

    #[Test]
    public function author_never_sees_who_received_the_card(): void
    {
        $author = $this->actingAsRegisteredMember();
        $recipient = $this->registeredMember($this->event, profile: ['name' => 'Destinatária Secreta']);
        $this->cardFor($author, $this->event, ['recipient_user_id' => $recipient->id, 'received_at' => now()]);
        $this->cardFor($recipient, $this->event, ['recipient_user_id' => $author->id, 'received_at' => now()]);
        $this->event->forceFill(['prayer_cards_distributed_at' => now()])->save();

        $this->getJson($this->cardUrl($this->event))
            ->assertOk()
            ->assertJsonPath('data.distribution_status', 'distributed')
            ->assertDontSee($recipient->id)
            ->assertDontSee($recipient->email)
            ->assertDontSee('Destinatária Secreta');
    }

    /**
     * A conta é verificada outra vez dentro da transação, depois do lock do evento: uma anonimização
     * (ou bloqueio) confirmada entre a verificação inicial e a gravação impede o cartão. Simulado
     * apagando o perfil no momento em que o evento é relido com lock.
     */
    #[Test]
    public function card_is_refused_when_the_author_stops_participating_before_it_is_saved(): void
    {
        $member = $this->actingAsRegisteredMember();
        $baseline = DB::transactionLevel();

        EventInstance::retrieved(function () use ($member, $baseline): void {
            if (DB::transactionLevel() > $baseline) {
                $member->memberProfile()->delete();
            }
        });

        $this->putJson($this->cardUrl($this->event), $this->cardPayload())
            ->assertForbidden()
            ->assertJsonPath('message', 'Só os membros inscritos neste evento podem preencher o cartão de oração.');

        $this->assertDatabaseCount('prayer_cards', 0);
    }
}
