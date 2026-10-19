<?php

namespace Tests\Feature\Database;

use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventRegistrationTableTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function event_registrations_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('event_registrations', [
            'id',
            'event_instance_id',
            'user_id',
            'created_at',
        ]));
    }

    #[Test]
    public function each_user_can_register_only_once_for_an_event(): void
    {
        $event = EventInstance::factory()->create();
        $user = User::factory()->create();

        EventRegistration::create([
            'event_instance_id' => $event->id,
            'user_id' => $user->id,
        ]);

        $this->expectException(QueryException::class);

        EventRegistration::create([
            'event_instance_id' => $event->id,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function registration_has_a_uuid_and_creation_timestamp(): void
    {
        $event = EventInstance::factory()->create();
        $user = User::factory()->create();

        $registration = EventRegistration::create([
            'event_instance_id' => $event->id,
            'user_id' => $user->id,
        ]);

        $this->assertTrue(Str::isUuid($registration->id));
        $this->assertNotNull($registration->created_at);
    }

    #[Test]
    public function event_cannot_be_deleted_while_it_has_registrations(): void
    {
        $event = EventInstance::factory()->create();
        $user = User::factory()->create();

        EventRegistration::create([
            'event_instance_id' => $event->id,
            'user_id' => $user->id,
        ]);

        $this->expectException(QueryException::class);

        $event->delete();
    }
}
