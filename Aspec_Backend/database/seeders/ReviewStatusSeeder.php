<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReviewStatusSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $statuses = array_map(
            fn (string $name): array => [
                'id' => (string) Str::uuid(),
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['Visible', 'Hidden', 'Flagged']
        );

        DB::table('review_statuses')->insertOrIgnore($statuses);
    }
}
