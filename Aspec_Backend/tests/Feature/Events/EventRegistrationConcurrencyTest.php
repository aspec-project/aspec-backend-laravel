<?php

namespace Tests\Feature\Events;

use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventRegistrationConcurrencyTest extends TestCase
{
    #[Test]
    public function only_one_concurrent_registration_can_take_the_last_spot(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Este teste de concorrência requer MySQL/MariaDB.'
            );
        }

        $userIds = [];
        $categoryId = null;
        $eventId = null;

        try {
            $users = User::factory()->count(2)->create();
            $userIds = $users->modelKeys();

            $category = EventCategory::factory()->create();
            $categoryId = $category->getKey();
            $location = Location::query()->firstOrFail();

            $event = EventInstance::factory()->create([
                'category_id' => $categoryId,
                'location_id' => $location->getKey(),
                'max_attendees' => 1,
                'registration_deadline' => now(
                    config('app.event_timezone', 'Europe/Lisbon')
                )->addMinutes(10),
            ]);

            $eventId = (string) $event->getKey();

            $tasks = $users->map(static function (User $user) use ($eventId): \Closure {
                $token = $user->createToken('aspec-209-concurrency')->plainTextToken;

                return static function () use ($eventId, $token): int {
                    $request = Request::create(
                        "/api/events/{$eventId}/registrations",
                        'POST',
                        [],
                        [],
                        [],
                        [
                            'HTTP_ACCEPT' => 'application/json',
                            'HTTP_AUTHORIZATION' => "Bearer {$token}",
                        ]
                    );

                    $kernel = app(Kernel::class);
                    $response = $kernel->handle($request);
                    $status = $response->getStatusCode();
                    $kernel->terminate($request, $response);

                    return $status;
                };
            })->all();

            $statuses = Concurrency::run($tasks);
            sort($statuses);

            $this->assertSame([201, 409], $statuses);
            $this->assertSame(
                1,
                DB::table('event_registrations')
                    ->where('event_instance_id', $eventId)
                    ->count()
            );
        } finally {
            if ($eventId !== null) {
                DB::table('event_registrations')
                    ->where('event_instance_id', $eventId)
                    ->delete();

                DB::table('event_instances')
                    ->where('id', $eventId)
                    ->delete();
            }

            if ($categoryId !== null) {
                DB::table('event_categories')
                    ->where('id', $categoryId)
                    ->delete();
            }

            if ($userIds !== []) {
                DB::table('personal_access_tokens')
                    ->whereIn('tokenable_id', $userIds)
                    ->delete();

                DB::table('users')
                    ->whereIn('id', $userIds)
                    ->delete();
            }
        }
    }
}
