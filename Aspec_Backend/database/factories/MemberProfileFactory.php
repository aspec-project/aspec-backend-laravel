<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\MemberProfile;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Requer os lookups seeded (roles, account_statuses, sectors, locations).
 *
 * @extends Factory<MemberProfile>
 */
class MemberProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'sector_id' => fn () => Sector::inRandomOrder()->value('id'),
            'location_id' => fn () => Location::inRandomOrder()->value('id'),
            'name' => fake()->name(),
            'business_name' => fake()->company(),
            'congregation' => fake()->words(3, true),
            'role_in_congregation' => fake()->jobTitle(),
            'logo_path' => null,
            'description' => fake()->paragraph(),
            'website_url' => fake()->url(),
            'commercial_contacts' => fake()->phoneNumber(),
            'address' => fake()->address(),
        ];
    }
}
