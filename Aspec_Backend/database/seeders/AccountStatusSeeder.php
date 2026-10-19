<?php

namespace Database\Seeders;

use App\Models\AccountStatus;
use Illuminate\Database\Seeder;

class AccountStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = ['Pending', 'Approved', 'Active', 'Inactive'];

        foreach ($statuses as $status) {
            AccountStatus::firstOrCreate(
                ['name' => $status]
            );
        }
    }
}
