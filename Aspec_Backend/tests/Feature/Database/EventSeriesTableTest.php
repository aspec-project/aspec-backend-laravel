<?php

namespace Tests\Feature\Database;

use App\Models\EventInstance;
use App\Models\EventSeries;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventSeriesTableTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function event_series_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('event_series'));
        $this->assertTrue(Schema::hasColumns('event_series', [
            'id',
            'frequency',
            'ends_on',
            'registration_deadline_days_before',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumn('event_instances', 'series_id'));
    }

    #[Test]
    public function event_instances_can_belong_to_a_series_or_be_standalone(): void
    {
        $series = EventSeries::create([
            'frequency' => 'weekly',
            'ends_on' => '2026-12-31',
            'registration_deadline_days_before' => 2,
        ]);

        $event = EventInstance::factory()->create([
            'series_id' => $series->id,
        ]);

        $standaloneEvent = EventInstance::factory()->create();

        $this->assertTrue(Str::isUuid($series->id));
        $this->assertSame($series->id, $event->series->id);
        $this->assertSame(1, $series->eventInstances()->count());
        $this->assertNull($standaloneEvent->series_id);
    }

    #[Test]
    public function a_series_cannot_be_deleted_while_it_has_event_instances(): void
    {
        $series = EventSeries::create([
            'frequency' => 'weekly',
            'ends_on' => '2026-12-31',
            'registration_deadline_days_before' => 2,
        ]);

        EventInstance::factory()->create([
            'series_id' => $series->id,
        ]);

        $this->expectException(QueryException::class);

        $series->delete();
    }
}
