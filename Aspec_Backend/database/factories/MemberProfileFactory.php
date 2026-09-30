<?php

namespace Database\Factories;

use App\Models\AccountStatus;
use App\Models\Location;
use App\Models\MemberProfile;
use App\Models\Role;
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
            // User::create em vez de User::factory(): o UserFactory ainda define `name` e não define role/status.
            'user_id' => fn () => User::create([
                'email' => fake()->unique()->safeEmail(),
                'password' => 'password',
                'phone' => fake()->numerify('9########'),
                'role_id' => Role::where('name', 'Member')->value('id'),
                'account_status_id' => AccountStatus::where('name', 'Active')->value('id'),
            ])->id,
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
