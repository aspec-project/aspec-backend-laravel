<?php

namespace Tests\Feature\Models;

use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventInstanceModelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function event_instances_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('event_instances', [
            'id',
            'category_id',
            'location_id',
            'name',
            'event_date',
            'event_start_time',
            'event_end_time',
            'description',
            'address',
            'latitude',
            'longitude',
            'max_attendees',
            'registration_deadline',
            'organizer_notes',
            'created_at',
            'updated_at',
        ]));
    }

    #[Test]
    public function event_instance_has_uuid_and_category_and_location_relations(): void
    {
        $event = EventInstance::factory()->create();

        $this->assertTrue(Str::isUuid($event->id));
        $this->assertInstanceOf(EventCategory::class, $event->category);
        $this->assertInstanceOf(Location::class, $event->location);
        $this->assertTrue($event->category->hasEvents());
        $this->assertSame(
            $event->getRawOriginal('event_date'),
            $event->event_date->format('Y-m-d')
        );
    }
}