<?php

namespace Tests\Concerns;

use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\MemberProfile;
use App\Models\PrayerCard;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Eventos com cartões de oração, membros inscritos e cartões, com as horas sempre em Lisboa.
 *
 * Os testes correm no verão (Lisboa = UTC+1) para apanhar comparações feitas em UTC: o prazo do
 * cartão vai do início do evento às 23:59 desse dia, na hora de Lisboa.
 */
trait BuildsPrayerCards
{
    protected const TZ = 'Europe/Lisbon';

    protected const VERSE = 'Filipenses 4:6 — Não andeis ansiosos de coisa alguma.';

    /** Dia e hora de início do evento usado por omissão (hora de Lisboa). */
    protected const EVENT_STARTS_AT = '2027-06-10 19:00';

    protected function travelToLisbon(string $dateTime): void
    {
        $this->travelTo(Carbon::parse($dateTime, self::TZ)->utc());
    }

    /**
     * Evento com cartões de oração que começa em $startsAt (hora de Lisboa); inscrições até à véspera.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function prayerEvent(string $startsAt = self::EVENT_STARTS_AT, array $attributes = []): EventInstance
    {
        $start = Carbon::createFromFormat('Y-m-d H:i', $startsAt, self::TZ);

        return EventInstance::factory()->create([
            'event_date' => $start->toDateString(),
            'event_start_time' => $start->format('H:i:s'),
            'event_end_time' => $start->copy()->addHours(2)->format('H:i:s'),
            'max_attendees' => 100,
            'registration_deadline' => $start->copy()->subDay()->setTime(23, 59)->format('Y-m-d H:i:s'),
            'prayer_cards_enabled' => true,
            'prayer_verse' => self::VERSE,
            ...$attributes,
        ]);
    }

    /**
     * Membro com perfil (Active por omissão).
     */
    protected function member(?User $user = null, array $profile = []): User
    {
        $user ??= User::factory()->create();
        MemberProfile::factory()->create(['user_id' => $user->id, ...$profile]);

        return $user;
    }

    protected function register(User $user, EventInstance $event): EventRegistration
    {
        return EventRegistration::create(['event_instance_id' => $event->id, 'user_id' => $user->id]);
    }

    /**
     * Membro com perfil, inscrito no evento.
     */
    protected function registeredMember(EventInstance $event, ?User $user = null, array $profile = []): User
    {
        $user = $this->member($user, $profile);
        $this->register($user, $event);

        return $user;
    }

    /**
     * Cartão escrito por $author no evento (sem destinatário, salvo indicação em $attributes).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function cardFor(User $author, EventInstance $event, array $attributes = []): PrayerCard
    {
        return PrayerCard::factory()->create([
            'event_instance_id' => $event->id,
            'author_user_id' => $author->id,
            ...$attributes,
        ]);
    }

    /**
     * Membros inscritos no evento, cada um com o seu cartão.
     *
     * @return list<User>
     */
    protected function membersWithCards(EventInstance $event, int $count): array
    {
        $members = [];

        for ($i = 0; $i < $count; $i++) {
            $member = $this->registeredMember($event);
            $this->cardFor($member, $event);
            $members[] = $member;
        }

        return $members;
    }

    /**
     * Corpo válido para preencher o cartão.
     *
     * @return array<string, mixed>
     */
    protected function cardPayload(array $overrides = []): array
    {
        return [
            'personal_request' => 'Saúde da minha mãe.',
            'work_request' => 'Um novo cliente para a empresa.',
            'salvation_request' => 'O meu vizinho Rui.',
            'consent' => true,
            ...$overrides,
        ];
    }

    protected function cardUrl(EventInstance $event): string
    {
        return "/api/events/{$event->id}/prayer-card";
    }

    /**
     * Valor em bruto de uma coluna do cartão (sem casts nem o filtro do soft delete).
     */
    protected function rawCardColumn(PrayerCard $card, string $column): mixed
    {
        return DB::table('prayer_cards')->where('id', $card->id)->value($column);
    }
}
