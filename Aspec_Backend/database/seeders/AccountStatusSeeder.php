<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\AccountStatus;
use Illuminate\Support\Str;

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
