<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AccountStatus;

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
                ['name' => $status]
            );
        }
    }
}
