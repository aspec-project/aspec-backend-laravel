<?php

namespace Tests\Feature\Models;

use App\Models\AccountStatus;
use App\Models\Location;
use App\Models\MemberProfile;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A marca users.subscription_exempt (administrador técnico, isento de subscrição) só é escrita
 * pelo comando aspec:create-admin: fica fora do $fillable, por isso nenhum pedido HTTP a consegue
 * pôr, e fica escondida como o resto do estado da subscrição.
 */
class SubscriptionExemptMarkTest extends TestCase
{
    use RefreshDatabase;

    private function exemptInDatabase(string $email): bool
    {
        return (bool) User::withTrashed()->where('email', $email)->value('subscription_exempt');
    }

    #[Test]
    public function new_user_is_not_exempt_by_default(): void
    {
        $user = User::factory()->admin()->create();

        $this->assertFalse($user->fresh()->subscription_exempt);
        $this->assertFalse($user->fresh()->isSubscriptionExempt());
    }

    #[Test]
    public function create_ignores_the_exempt_mark(): void
    {
        $user = User::create([
            'email' => 'admin.massa@aspec.pt',
            'password' => 'Password123!',
            'phone' => '+351912345678',
            'role_id' => Role::where('name', 'Admin')->value('id'),
            'account_status_id' => AccountStatus::where('name', 'Active')->value('id'),
            'subscription_exempt' => true,
        ]);

        $this->assertFalse($user->fresh()->subscription_exempt);
    }

    #[Test]
    public function fill_ignores_the_exempt_mark(): void
    {
        $user = User::factory()->admin()->create();

        $user->fill(['subscription_exempt' => true])->save();

        $this->assertFalse($user->fresh()->subscription_exempt);
    }

    #[Test]
    public function registration_cannot_create_an_exempt_account(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Membro Isento',
            'email' => 'quer.isencao@example.com',
            'password' => 'Password123!',
            'phone' => '912345678',
            'business_name' => 'Empresa Isenta',
            'sector_id' => Sector::firstOrFail()->id,
            'location_id' => Location::firstOrFail()->id,
            'congregation' => 'Congregação de Teste',
            'role_in_congregation' => 'Membro',
            'address' => 'Rua de Teste, 1',
            'commercial_email' => 'geral@empresa-isenta.pt',
            'commercial_phone' => '213 456 789',
            'terms_accepted' => true,
            'subscription_exempt' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'quer.isencao@example.com', 'subscription_exempt' => false]);
    }

    #[Test]
    public function profile_update_cannot_make_an_admin_exempt(): void
    {
        $admin = User::factory()->admin()->create();
        MemberProfile::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/member-profile', [
            'business_name' => 'Nova Lda',
            'subscription_exempt' => true,
        ])->assertOk();

        $this->assertFalse($this->exemptInDatabase($admin->email));
    }

    #[Test]
    public function to_array_hides_the_exempt_mark(): void
    {
        $technicalAdmin = User::factory()->technicalAdmin()->create();

        $this->assertArrayNotHasKey('subscription_exempt', $technicalAdmin->fresh()->toArray());
    }

    #[Test]
    public function auth_me_does_not_expose_the_exempt_mark(): void
    {
        Sanctum::actingAs(User::factory()->technicalAdmin()->create());

        $response = $this->getJson('/api/auth/me')->assertOk();

        $this->assertStringNotContainsString('subscription_exempt', $response->getContent());
    }

    #[Test]
    public function admin_user_detail_does_not_expose_the_exempt_mark(): void
    {
        $technicalAdmin = User::factory()->technicalAdmin()->create();
        Sanctum::actingAs($technicalAdmin);

        $response = $this->getJson("/api/admin/users/{$technicalAdmin->id}")->assertOk();

        $this->assertStringNotContainsString('subscription_exempt', $response->getContent());
    }
}
