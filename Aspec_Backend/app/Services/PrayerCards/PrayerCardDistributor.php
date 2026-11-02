<?php

namespace App\Services\PrayerCards;

use App\Models\EventInstance;
use App\Models\PrayerCard;
use App\Notifications\PrayerCardReceivedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Random\Randomizer;

/**
 * Distribuição em cadeia dos cartões de oração de um evento: baralha os cartões e cada autor recebe
 * o cartão do seguinte; o último recebe o do primeiro. É um ciclo único, por isso com 2 ou mais
 * cartões ninguém recebe o seu.
 */
class PrayerCardDistributor
{
    /**
     * O Randomizer vem do contentor: por omissão usa o motor seguro do PHP; nos testes é injetado
     * com semente para o resultado ser reprodutível.
     */
    public function __construct(private readonly Randomizer $randomizer) {}

    /**
     * Distribui os cartões do evento, uma só vez (a marca prayer_cards_distributed_at e os
     * destinatários são gravados na mesma transação, com o evento bloqueado). Entram só os cartões
     * não retirados de autores Active com perfil; com 0 ou 1 cartões o evento fica apenas marcado.
     * Cada destinatário recebe um email sem conteúdo, enviado depois do commit.
     */
    public function distribute(EventInstance $event): PrayerCardDistribution
    {
        return DB::transaction(function () use ($event): PrayerCardDistribution {
            $event = EventInstance::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if ($event->prayer_cards_distributed_at !== null) {
                return PrayerCardDistribution::alreadyDistributed();
            }

            $cards = $event->prayerCards()
                ->whereHas('author', fn (Builder $author) => $author->activeWithProfile())
                ->with('author')
                ->lockForUpdate()
                ->get();

            $now = now();
            $delivered = 0;

            if ($cards->count() >= 2) {
                $chain = $this->chain($cards->all(), $this->previousSessionPairs($event));
                $count = count($chain);

                foreach ($chain as $position => $receiverCard) {
                    $card = $chain[($position + 1) % $count];
                    $card->forceFill(['recipient_user_id' => $receiverCard->author_user_id, 'received_at' => $now])->save();

                    $receiverCard->author->notify((new PrayerCardReceivedNotification($event))->afterCommit());
                    $delivered++;
                }
            }

            $event->forceFill(['prayer_cards_distributed_at' => $now])->save();

            return PrayerCardDistribution::done($delivered, $cards->count() - $delivered);
        });
    }

    /**
     * Ordem da cadeia. Numa série tenta até `distribution_attempts` baralhamentos para não repetir
     * nenhum par (autor → destinatário) da sessão anterior; se não conseguir, fica o último.
     *
     * @param  list<PrayerCard>  $cards
     * @param  list<string>  $previousPairs
     * @return list<PrayerCard>
     */
    private function chain(array $cards, array $previousPairs): array
    {
        $attempts = max(1, (int) config('prayer_cards.distribution_attempts'));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $chain = $this->randomizer->shuffleArray($cards);

            if ($previousPairs === [] || array_intersect($this->pairs($chain), $previousPairs) === []) {
                break;
            }
        }

        return $chain;
    }

    /**
     * Pares "autor>destinatário" da cadeia: o cartão na posição i+1 vai para o autor na posição i.
     *
     * @param  list<PrayerCard>  $chain
     * @return list<string>
     */
    private function pairs(array $chain): array
    {
        $count = count($chain);
        $pairs = [];

        foreach ($chain as $position => $receiverCard) {
            $pairs[] = $chain[($position + 1) % $count]->author_user_id.'>'.$receiverCard->author_user_id;
        }

        return $pairs;
    }

    /**
     * Pares da sessão anterior da mesma série, se essa sessão já foi distribuída. Os cartões
     * retirados ou expirados já não guardam o destinatário, por isso não contam.
     *
     * @return list<string>
     */
    private function previousSessionPairs(EventInstance $event): array
    {
        if ($event->series_id === null) {
            return [];
        }

        $previous = EventInstance::query()
            ->where('series_id', $event->series_id)
            ->whereKeyNot($event->getKey())
            ->whereDate('event_date', '<', $event->event_date->toDateString())
            ->orderByDesc('event_date')
            ->first();

        if ($previous === null || $previous->prayer_cards_distributed_at === null) {
            return [];
        }

        return PrayerCard::query()
            ->where('event_instance_id', $previous->id)
            ->whereNotNull('recipient_user_id')
            ->get(['author_user_id', 'recipient_user_id'])
            ->map(fn (PrayerCard $card) => $card->author_user_id.'>'.$card->recipient_user_id)
            ->all();
    }
}
