<?php

namespace App\Services\Events;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use OverflowException;

class EventSeriesSchedule
{
    public const MAX_OCCURRENCES = 52;

    /**
     * @return list<string>
     */
    public function generate(string $firstDate, string $endsOn, string $frequency): array
    {
        if (! in_array($frequency, ['weekly', 'biweekly', 'monthly'], true)) {
            throw new InvalidArgumentException('Frequência de recorrência inválida.');
        }

        $timezone = config('app.event_timezone', 'Europe/Lisbon');

        $start = CarbonImmutable::createFromFormat('!Y-m-d', $firstDate, $timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $endsOn, $timezone);

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException('A data final não pode ser anterior à primeira sessão.');
        }

        $dates = [];

        for ($index = 0; ; $index++) {
            $occurrence = match ($frequency) {
                'weekly' => $start->addWeeks($index),
                'biweekly' => $start->addWeeks($index * 2),
                'monthly' => $start->addMonthsNoOverflow($index),
            };

            if ($occurrence->greaterThan($end)) {
                return $dates;
            }

            if (count($dates) === self::MAX_OCCURRENCES) {
                throw new OverflowException('Uma série não pode exceder 52 sessões.');
            }

            $dates[] = $occurrence->toDateString();
        }
    }

    /**
     * Prazo de inscrição de uma sessão da série: N dias antes da data da sessão, às 23:59 (hora de Lisboa).
     *
     * @param  string  $sessionDate  Data da sessão (Y-m-d).
     * @param  int  $daysBefore  Antecedência do prazo, em dias.
     * @return CarbonImmutable Prazo de inscrição na hora de Lisboa.
     */
    public function registrationDeadline(string $sessionDate, int $daysBefore): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $sessionDate, config('app.event_timezone', 'Europe/Lisbon'))
            ->subDays($daysBefore)
            ->setTime(23, 59);
    }
}
