<?php

namespace Tests\Feature\Console;

use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * php artisan aspec:create-admin cria o administrador técnico: o único utilizador isento de
 * subscrição (users.subscription_exempt). Os outros administradores pagam como qualquer membro,
 * por isso o comando nunca isenta uma conta que já existe.
 */
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
    public function creates_an_active_subscription_exempt_admin_without_member_profile(): void
    {
        $this->configureAdmin();

        $this->artisan('aspec:create-admin')->assertSuccessful();

        $user = User::where('email', self::EMAIL)->firstOrFail();

        $this->assertSame('Admin', $user->role->name);
        $this->assertSame('Active', $user->accountStatus->name);
        $this->assertNull($user->memberProfile);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertTrue($user->subscription_exempt);
        $this->assertTrue($user->isSubscriptionExempt());
    }

    #[Test]
    public function running_twice_does_not_create_a_second_admin_and_keeps_it_exempt(): void
    {
        $this->configureAdmin();

        $this->artisan('aspec:create-admin')->assertSuccessful();
        $this->artisan('aspec:create-admin')
            ->expectsOutputToContain('O administrador já existe. Nada a fazer.')
            ->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertTrue(User::where('email', self::EMAIL)->firstOrFail()->subscription_exempt);
    }

    #[Test]
    public function refuses_a_second_technical_admin_with_another_email(): void
    {
        $this->configureAdmin();
        $this->artisan('aspec:create-admin')->assertSuccessful();

        $this->configureAdmin('outro.admin@aspec.pt');
        $this->artisan('aspec:create-admin')
            ->expectsOutputToContain('Já existe um administrador técnico.')
            ->assertFailed();

        $this->assertSame(1, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'outro.admin@aspec.pt']);
    }

    #[Test]
    public function does_not_make_an_existing_paying_admin_exempt(): void
    {
        $this->configureAdmin();
        $payingAdmin = User::factory()->admin()->create(['email' => self::EMAIL]);
        MemberProfile::factory()->create(['user_id' => $payingAdmin->id]);

        $this->artisan('aspec:create-admin')
            ->expectsOutputToContain('Já existe uma conta com este email que não é o administrador técnico.')
            ->assertFailed();

        $fresh = $payingAdmin->fresh();
        $this->assertFalse($fresh->subscription_exempt);
        $this->assertSame('Admin', $fresh->role->name);
        $this->assertSame(1, User::count());
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
        $this->assertFalse($user->subscription_exempt);
    }
}
