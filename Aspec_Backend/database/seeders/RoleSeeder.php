<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = ['admin', 'member'];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role],
                ['id' => Str::uuid()]
            );
        }
    }
}
