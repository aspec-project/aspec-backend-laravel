<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * Periodicidade da quota (SUBSCRIPTION_INTERVAL). Os valores são os do recurring.interval do
 * Price do Stripe, para a configuração bater 1:1 com o Price. Único sítio que traduz o intervalo
 * para textos e datas.
 */
enum SubscriptionInterval: string
{
    case Month = 'month';

    case Year = 'year';

    /**
     * Periodicidade configurada. Um valor inválido lança ValueError: uma configuração errada tem
     * de falhar logo, e não cair em silêncio para o mensal.
     */
    public static function configured(): self
    {
        return self::from((string) config('subscription.interval'));
    }

    /**
     * Adjetivo em português ("mensal"/"anual"), ex.: "a quota anual de 20,00 €".
     */
    public function adjective(): string
    {
        return match ($this) {
            self::Month => 'mensal',
            self::Year => 'anual',
        };
    }

    /**
     * Valor por período em português ("por mês"/"por ano"), ex.: "20,00 € por ano".
     */
    public function perPeriod(): string
    {
        return match ($this) {
            self::Month => 'por mês',
            self::Year => 'por ano',
        };
    }

    /**
     * Soma um período à data, sem transbordar para o mês seguinte (31/01 + 1 mês = 28/02;
     * 29/02 + 1 ano = 28/02). Devolve uma cópia: a data recebida não muda.
     */
    public function addTo(CarbonInterface $date): CarbonInterface
    {
        return match ($this) {
            self::Month => $date->copy()->addMonthNoOverflow(),
            self::Year => $date->copy()->addYearNoOverflow(),
        };
    }
}
