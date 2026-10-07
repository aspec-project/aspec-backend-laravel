<?php

namespace Tests\Feature\Database;

use App\Models\AccountStatus;
use Database\Seeders\AccountStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountStatusSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeder_creates_the_four_account_statuses_including_approved(): void
    {
        $this->assertDatabaseHas('account_statuses', ['name' => 'Approved']);
        $this->assertEqualsCanonicalizing(
            ['Pending', 'Approved', 'Active', 'Inactive'],
            AccountStatus::pluck('name')->all()
        );
    }

    #[Test]
    public function running_the_seeder_again_does_not_duplicate_statuses(): void
    {
        $this->seed(AccountStatusSeeder::class);

        $this->assertSame(4, AccountStatus::count());
        $this->assertSame(1, AccountStatus::where('name', 'Approved')->count());
    }
}
