<?php

namespace Tests\Feature\Member;

use App\Models\AccountStatus;
use App\Models\MemberProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class MemberLogoTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/member-profile/logo';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function makeUser(string $role, string $status): User
    {
        return User::factory()->create([
            'role_id' => Role::where('name', $role)->value('id'),
            'account_status_id' => AccountStatus::where('name', $status)->value('id'),
        ]);
    }

    private function memberWithProfile(string $status = 'Active'): User
    {
        $user = $this->makeUser('Member', $status);
        MemberProfile::factory()->create(['user_id' => $user->id, 'logo_path' => null]);

        return $user;
    }

    private function giveStoredLogo(User $user): string
    {
        $path = "logos/{$user->id}/".Str::random(40).'.png';
        Storage::disk('public')->put($path, 'logo-antigo');
        $user->memberProfile->update(['logo_path' => $path]);

        return $path;
    }

    private function logoPathOf(User $user): ?string
    {
        return $user->memberProfile->fresh()->logo_path;
    }

    private function assertNothingStored(User $user): void
    {
        $this->assertSame([], Storage::disk('public')->allFiles("logos/{$user->id}"));
    }

    // ---- Sucesso ----

    #[Test]
    public function active_member_uploads_logo_and_gets_200_with_public_url(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $response = $this->postJson(self::URL, ['logo' => UploadedFile::fake()->image('logo.png', 400, 400)])
            ->assertOk();

        $path = $this->logoPathOf($user);
        $this->assertNotNull($path);
        $response->assertExactJson([
            'success' => true,
            'message' => 'Logótipo atualizado com sucesso.',
            'data' => ['logo_url' => Storage::disk('public')->url($path)],
        ]);
    }

    #[Test]
    public function logo_is_stored_in_user_logos_folder_and_logo_path_is_filled(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['logo' => UploadedFile::fake()->image('logo.png')])->assertOk();

        $path = $this->logoPathOf($user);
        $this->assertStringStartsWith("logos/{$user->id}/", $path);
        Storage::disk('public')->assertExists($path);
        $this->assertCount(1, Storage::disk('public')->allFiles("logos/{$user->id}"));
    }

    #[Test]
    public function stored_file_name_is_generated_not_the_original_name(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['logo' => UploadedFile::fake()->image('o-meu-logo.png')])->assertOk();

        $this->assertStringNotContainsString('o-meu-logo', $this->logoPathOf($user));
        Storage::disk('public')->assertMissing("logos/{$user->id}/o-meu-logo.png");
    }

    #[Test]
    public function get_member_profile_returns_the_same_logo_url_after_upload(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $logoUrl = $this->postJson(self::URL, ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertOk()
            ->json('data.logo_url');

        $this->getJson('/api/member-profile')
            ->assertOk()
            ->assertJsonPath('data.logo_url', $logoUrl)
            ->assertJsonMissingPath('data.logo_path');
    }

    public static function acceptedImages(): array
    {
        return [
            'jpg' => ['logo.jpg'],
            'jpeg' => ['logo.jpeg'],
            'png' => ['logo.png'],
            'webp' => ['logo.webp'],
        ];
    }

    #[Test]
    #[DataProvider('acceptedImages')]
    public function accepted_image_types_are_stored(string $fileName): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['logo' => UploadedFile::fake()->image($fileName)])->assertOk();

        Storage::disk('public')->assertExists($this->logoPathOf($user));
    }

    #[Test]
    public function logo_of_exactly_5_mb_is_accepted(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['logo' => UploadedFile::fake()->image('grande.jpg')->size(5120)])
            ->assertOk();
    }

    // ---- Substituição: grava o novo, atualiza logo_path e só depois apaga o antigo ----

    #[Test]
    public function second_upload_replaces_old_file_and_updates_logo_path(): void
    {
        $user = $this->memberWithProfile();
        $oldPath = $this->giveStoredLogo($user);
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['logo' => UploadedFile::fake()->image('novo.png')])->assertOk();

        $newPath = $this->logoPathOf($user);
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
        $this->assertSame([$newPath], Storage::disk('public')->allFiles("logos/{$user->id}"));
    }

    #[Test]
    public function replacing_logo_does_not_touch_another_members_logo(): void
    {
        $other = $this->memberWithProfile();
        $otherPath = $this->giveStoredLogo($other);
        $user = $this->memberWithProfile();
        $this->giveStoredLogo($user);
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['logo' => UploadedFile::fake()->image('novo.png')])->assertOk();

        Storage::disk('public')->assertExists($otherPath);
        $this->assertSame($otherPath, $this->logoPathOf($other));
    }

    #[Test]
    public function upload_still_succeeds_when_old_logo_file_is_already_missing(): void
    {
        $user = $this->memberWithProfile();
        $oldPath = $this->giveStoredLogo($user);
        Storage::disk('public')->delete($oldPath);
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['logo' => UploadedFile::fake()->image('novo.png')])
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('public')->assertExists($this->logoPathOf($user));
    }

    #[Test]
    public function database_failure_keeps_old_logo_and_removes_new_file(): void
    {
        config(['app.debug' => false]);
        $user = $this->memberWithProfile();
        $oldPath = $this->giveStoredLogo($user);
        MemberProfile::updating(fn () => throw new RuntimeException('Falha simulada na BD.'));
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['logo' => UploadedFile::fake()->image('novo.png')])
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Ocorreu um erro interno. Tente novamente mais tarde.']);

        $this->assertSame($oldPath, $this->logoPathOf($user));
        $this->assertSame([$oldPath], Storage::disk('public')->allFiles("logos/{$user->id}"));
    }

    // ---- Validação ----

    #[Test]
    public function missing_logo_returns_422(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->postJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Os dados enviados são inválidos.')
            ->assertJsonValidationErrors(['logo'], 'errors');

        $this->assertNull($this->logoPathOf($user));
        $this->assertNothingStored($user);
    }

    public static function invalidFiles(): array
    {
        return [
            'pdf' => [fn () => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf')],
            'svg' => [fn () => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')],
            'gif (imagem fora da lista)' => [fn () => UploadedFile::fake()->image('logo.gif')],
            'mais de 5 MB' => [fn () => UploadedFile::fake()->image('enorme.jpg')->size(5121)],
            'texto em vez de ficheiro' => [fn () => 'nao-e-um-ficheiro'],
        ];
    }

    #[Test]
    #[DataProvider('invalidFiles')]
    public function invalid_file_returns_422_and_keeps_current_logo(callable $makeFile): void
    {
        $user = $this->memberWithProfile();
        $oldPath = $this->giveStoredLogo($user);
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['logo' => $makeFile()])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['logo'], 'errors');

        $this->assertSame($oldPath, $this->logoPathOf($user));
        $this->assertSame([$oldPath], Storage::disk('public')->allFiles("logos/{$user->id}"));
    }

    // ---- Throttle: throttle:10,1, como no portfólio ----

    #[Test]
    public function eleventh_upload_in_the_same_minute_is_throttled(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson(self::URL, [])->assertUnprocessable();
        }

        $this->postJson(self::URL, ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertTooManyRequests()
            ->assertExactJson(['success' => false, 'message' => 'Demasiados pedidos. Tente novamente mais tarde.']);

        $this->assertNull($this->logoPathOf($user));
        $this->assertNothingStored($user);
    }

    // ---- Acesso ----

    #[Test]
    public function upload_requires_authentication(): void
    {
        $this->postJson(self::URL, ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Não autenticado.']);

        $this->assertSame([], Storage::disk('public')->allFiles('logos'));
    }

    #[Test]
    public function pending_member_cannot_upload_logo(): void
    {
        $user = $this->memberWithProfile('Pending');
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'A conta não está ativa e não pode editar dados.');

        $this->assertNull($this->logoPathOf($user));
        $this->assertNothingStored($user);
    }

    #[Test]
    public function inactive_member_cannot_upload_logo(): void
    {
        $user = $this->memberWithProfile('Inactive');
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertNull($this->logoPathOf($user));
        $this->assertNothingStored($user);
    }

    #[Test]
    public function admin_without_profile_gets_not_found(): void
    {
        $admin = $this->makeUser('Admin', 'Active');
        Sanctum::actingAs($admin);

        $this->postJson(self::URL, ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Perfil de membro não encontrado.']);

        $this->assertNothingStored($admin);
    }
}
