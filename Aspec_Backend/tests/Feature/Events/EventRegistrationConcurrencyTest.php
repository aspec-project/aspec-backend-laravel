<?php

namespace Tests\Feature\Events;

use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\EventSeries;
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

    #[Test]
    public function concurrent_this_and_following_registrations_do_not_exceed_series_capacity(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Este teste de concorrência requer MySQL/MariaDB.'
            );
        }

        $userIds = [];
        $categoryId = null;
        $seriesId = null;
        $eventIds = [];

        try {
            $users = User::factory()->count(2)->create();
            $userIds = $users->modelKeys();

            $category = EventCategory::factory()->create();
            $categoryId = $category->getKey();
            $location = Location::query()->firstOrFail();

            $timezone = config('app.event_timezone', 'Europe/Lisbon');
            $firstDate = now($timezone)->addDays(30)->startOfDay();

            $series = EventSeries::query()->create([
                'frequency' => 'weekly',
                'ends_on' => $firstDate->copy()->addWeek()->toDateString(),
                'registration_deadline_days_before' => 1,
            ]);
            $seriesId = (string) $series->getKey();

            foreach (range(0, 1) as $index) {
                $sessionDate = $firstDate->copy()->addWeeks($index);

                $event = EventInstance::factory()->create([
                    'category_id' => $categoryId,
                    'location_id' => $location->getKey(),
                    'series_id' => $seriesId,
                    'event_date' => $sessionDate->toDateString(),
                    'registration_deadline' => $sessionDate
                        ->copy()
                        ->subDay()
                        ->setTime(23, 59)
                        ->format('Y-m-d H:i:s'),
                    'max_attendees' => 1,
                ]);

                $eventIds[] = (string) $event->getKey();
            }

            $tasks = $users->values()->map(
                static function (User $user, int $index) use ($eventIds): \Closure {
                    $token = $user->createToken('aspec-336-concurrency')->plainTextToken;
                    $eventId = $eventIds[$index];

                    return static function () use ($eventId, $token): int {
                        $request = Request::create(
                            "/api/events/{$eventId}/registrations",
                            'POST',
                            ['scope' => 'this_and_following'],
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
                }
            )->all();

            $statuses = Concurrency::run($tasks);
            sort($statuses);

            $this->assertSame([201, 409], $statuses);

            $registeredUsersBySession = [];

            foreach ($eventIds as $eventId) {
                $registrations = DB::table('event_registrations')
                    ->where('event_instance_id', $eventId)
                    ->pluck('user_id');

                $this->assertLessThanOrEqual(1, $registrations->count());
                $registeredUsersBySession[] = $registrations->first();
            }

            $firstSessionUser = $registeredUsersBySession[0];
            $followingSessionUser = $registeredUsersBySession[1];

            $this->assertNotNull($followingSessionUser);
            $this->assertContains($followingSessionUser, $userIds);

            $this->assertTrue(
                $firstSessionUser === null
                || $firstSessionUser === $followingSessionUser
            );
        } finally {
            if ($eventIds !== []) {
                DB::table('event_registrations')
                    ->whereIn('event_instance_id', $eventIds)
                    ->delete();

                DB::table('event_instances')
                    ->whereIn('id', $eventIds)
                    ->delete();
            }

            if ($seriesId !== null) {
                DB::table('event_series')
                    ->where('id', $seriesId)
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
