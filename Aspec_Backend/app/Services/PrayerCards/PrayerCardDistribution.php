<?php

namespace App\Services\PrayerCards;

/**
 * Resultado de uma distribuição de cartões de oração: se o evento já estava distribuído (nada mudou)
 * ou quantos cartões foram entregues e quantos ficaram sem destinatário.
 */
final readonly class PrayerCardDistribution
{
    private function __construct(
        public bool $alreadyDistributed,
        public int $deliveredCount,
        public int $unpairedCount,
    ) {}

    /**
     * O evento já tinha sido distribuído: a distribuição não volta a correr.
     */
    public static function alreadyDistributed(): self
    {
        return new self(true, 0, 0);
    }

    /**
     * Distribuição feita agora, com o número de cartões entregues e sem par.
     */
    public static function done(int $deliveredCount, int $unpairedCount): self
    {
        return new self(false, $deliveredCount, $unpairedCount);
    }
}
