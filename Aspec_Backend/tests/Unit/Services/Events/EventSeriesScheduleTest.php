<?php

namespace Tests\Unit\Services\Events;

use App\Services\Events\EventSeriesSchedule;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventSeriesScheduleTest extends TestCase
{
    private EventSeriesSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schedule = new EventSeriesSchedule;
    }

    #[Test]
    public function it_generates_weekly_occurrences_including_the_end_date(): void
    {
        $dates = $this->schedule->generate(
            '2027-01-04',
            '2027-01-18',
            'weekly'
        );

        $this->assertSame([
            '2027-01-04',
            '2027-01-11',
            '2027-01-18',
        ], $dates);
    }

    #[Test]
    public function it_generates_biweekly_occurrences(): void
    {
        $dates = $this->schedule->generate(
            '2027-01-01',
            '2027-01-29',
            'biweekly'
        );

        $this->assertSame([
            '2027-01-01',
            '2027-01-15',
            '2027-01-29',
        ], $dates);
    }

    #[Test]
    public function monthly_occurrences_use_the_last_day_of_shorter_months_and_restore_the_original_day(): void
    {
        $dates = $this->schedule->generate(
            '2027-01-31',
            '2027-04-30',
            'monthly'
        );

        $this->assertSame([
            '2027-01-31',
            '2027-02-28',
            '2027-03-31',
            '2027-04-30',
        ], $dates);
    }

    #[Test]
    public function it_rejects_an_end_date_before_the_first_occurrence(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->schedule->generate(
            '2027-01-11',
            '2027-01-10',
            'weekly'
        );
    }

    #[Test]
    public function it_rejects_an_unsupported_frequency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->schedule->generate(
            '2027-01-04',
            '2027-01-18',
            'daily'
        );
    }

    #[Test]
    public function it_allows_exactly_52_occurrences(): void
    {
        $dates = $this->schedule->generate(
            '2027-01-04',
            '2027-12-27',
            'weekly'
        );

        $this->assertCount(EventSeriesSchedule::MAX_OCCURRENCES, $dates);
        $this->assertSame('2027-12-27', $dates[51]);
    }

    #[Test]
    public function it_rejects_more_than_52_occurrences(): void
    {
        $this->expectException(OverflowException::class);

        $this->schedule->generate(
            '2027-01-04',
            '2028-01-03',
            'weekly'
        );
    }
}
