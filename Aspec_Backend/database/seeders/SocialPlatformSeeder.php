<?php

namespace Database\Seeders;

use App\Models\SocialPlatform;
use Illuminate\Database\Seeder;

class SocialPlatformSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $platforms = [
            'LinkedIn',
            'Instagram',
            'Facebook',
            'YouTube',
        ];

        foreach ($platforms as $platform) {
            SocialPlatform::firstOrCreate(
                ['name' => $platform]
            );
        }
    }
}
