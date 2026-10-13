<?php

namespace Database\Factories;

use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventInstance>
 */
class EventInstanceFactory extends Factory
{
    public function definition(): array
    {
        $eventDate = fake()->dateTimeBetween('+1 month', '+3 months');
        $registrationDeadline = (clone $eventDate)->modify('-1 day')->setTime(23, 59, 0);

        return [
            'category_id' => EventCategory::factory(),
            'location_id' => fn () => Location::query()->firstOrFail()->id,
            'name' => fake()->sentence(4),
            'event_date' => $eventDate->format('Y-m-d'),
            'event_start_time' => '09:00:00',
            'event_end_time' => '11:00:00',
            'description' => fake()->paragraph(),
            'address' => fake()->streetAddress(),
            'latitude' => '38.7223000',
            'longitude' => '-9.1393000',
            'max_attendees' => fake()->numberBetween(10, 100),
            'registration_deadline' => $registrationDeadline,
            'organizer_notes' => fake()->optional()->sentence(),
        ];
    }
}
