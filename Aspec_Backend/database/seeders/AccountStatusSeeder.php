<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AccountStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = ['active', 'inactive', 'pending'];

        foreach ($statuses as $status) {
            AccountStatus::firstOrCreate(
                ['name' => $status],
                ['id' => Str::uuid()]
            );
        }
    }
}
