<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'admin@aspec.pt';

    private const PASSWORD = 'Str0ng!Passw0rd#2026';

    private function configureAdmin(?string $email = self::EMAIL, ?string $password = self::PASSWORD): void
    {
        config([
            'aspec.admin.email' => $email,
            'aspec.admin.password' => $password,
        ]);
    }

    #[Test]
    public function creates_an_active_admin_without_member_profile(): void
    {
        $this->configureAdmin();

        $this->artisan('aspec:create-admin')->assertSuccessful();

        $user = User::where('email', self::EMAIL)->firstOrFail();

        $this->assertSame('Admin', $user->role->name);
        $this->assertSame('Active', $user->accountStatus->name);
        $this->assertNull($user->memberProfile);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    #[Test]
    public function running_twice_does_not_create_a_second_admin(): void
    {
        $this->configureAdmin();

        $this->artisan('aspec:create-admin')->assertSuccessful();
        $this->artisan('aspec:create-admin')->assertSuccessful();

        $this->assertSame(1, User::where('email', self::EMAIL)->count());
    }

    #[Test]
    public function fails_without_credentials_in_the_environment(): void
    {
        $this->configureAdmin(null, null);

        $this->artisan('aspec:create-admin')->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function fails_with_invalid_email_or_weak_password(): void
    {
        $this->configureAdmin('nao-e-email', '123');

        $this->artisan('aspec:create-admin')->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function does_not_promote_an_existing_member(): void
    {
        $this->configureAdmin();

        User::factory()->create(['email' => self::EMAIL]);

        $this->artisan('aspec:create-admin')->assertFailed();

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertSame('Member', $user->role->name);
    }
}
