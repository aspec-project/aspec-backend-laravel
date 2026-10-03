<?php

namespace Tests\Feature\Member;

use App\Models\AccountStatus;
use App\Models\MemberProfile;
use App\Models\Portfolio;
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

class PortfolioTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/member-portfolio';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    // User::create em vez de User::factory(): o UserFactory (da auth) ainda define `name` e não define role/status.
    private function makeUser(string $role, string $status): User
    {
        return User::create([
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'phone' => '912345678',
            'role_id' => Role::where('name', $role)->value('id'),
            'account_status_id' => AccountStatus::where('name', $status)->value('id'),
        ]);
    }

    private function memberWithProfile(string $status = 'Active'): User
    {
        $user = $this->makeUser('Member', $status);
        MemberProfile::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    private function addStoredImage(User $user, ?string $name = null): Portfolio
    {
        $path = "portfolios/{$user->id}/".($name ?? Str::random(40).'.jpg');
        Storage::disk('public')->put($path, 'conteudo');

        return Portfolio::create(['profile_id' => $user->memberProfile->id, 'image_path' => $path]);
    }

    private function addImages(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->addStoredImage($user);
        }
    }

    private function assertNothingStored(User $user): void
    {
        $this->assertSame([], Storage::disk('public')->allFiles("portfolios/{$user->id}"));
    }

    // ---- POST /api/member-portfolio ----

    #[Test]
    public function active_member_uploads_image_and_gets_201_with_public_url(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $response = $this->postJson(self::URL, ['image' => UploadedFile::fake()->image('foto.jpg', 800, 600)])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Imagem adicionada ao portfólio.')
            ->assertJsonStructure(['success', 'message', 'data' => ['id', 'image_url']])
            ->assertJsonMissingPath('data.image_path')
            ->assertJsonMissingPath('data.profile_id');

        $image = Portfolio::where('profile_id', $user->memberProfile->id)->sole();
        $response
            ->assertJsonPath('data.id', $image->id)
            ->assertJsonPath('data.image_url', Storage::disk('public')->url($image->image_path));
    }

    #[Test]
    public function uploaded_file_is_stored_in_user_portfolio_folder(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['image' => UploadedFile::fake()->image('foto.jpg')])->assertCreated();

        $image = Portfolio::where('profile_id', $user->memberProfile->id)->sole();
        $this->assertStringStartsWith("portfolios/{$user->id}/", $image->image_path);
        Storage::disk('public')->assertExists($image->image_path);
    }

    #[Test]
    public function stored_file_name_is_generated_not_the_original_name(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['image' => UploadedFile::fake()->image('a-minha-foto.png')])->assertCreated();

        $image = Portfolio::where('profile_id', $user->memberProfile->id)->sole();
        $this->assertNotSame('a-minha-foto.png', basename($image->image_path));
        $this->assertStringNotContainsString('a-minha-foto', $image->image_path);
        Storage::disk('public')->assertMissing("portfolios/{$user->id}/a-minha-foto.png");
    }

    public static function acceptedImages(): array
    {
        return [
            'jpg' => ['foto.jpg'],
            'jpeg' => ['foto.jpeg'],
            'png' => ['foto.png'],
            'webp' => ['foto.webp'],
        ];
    }

    #[Test]
    #[DataProvider('acceptedImages')]
    public function accepted_image_types_are_stored(string $fileName): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['image' => UploadedFile::fake()->image($fileName)])->assertCreated();

        $image = Portfolio::where('profile_id', $user->memberProfile->id)->sole();
        Storage::disk('public')->assertExists($image->image_path);
    }

    #[Test]
    public function image_of_exactly_5_mb_is_accepted(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['image' => UploadedFile::fake()->image('grande.jpg')->size(5120)])
            ->assertCreated();
    }

    #[Test]
    public function missing_image_returns_422(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->postJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Os dados enviados são inválidos.')
            ->assertJsonValidationErrors(['image'], 'errors');

        $this->assertDatabaseCount('portfolios', 0);
    }

    public static function invalidFiles(): array
    {
        return [
            'pdf' => [fn () => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
            'svg' => [fn () => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')],
            'texto' => [fn () => UploadedFile::fake()->create('notas.txt', 10, 'text/plain')],
            'gif (imagem fora da lista)' => [fn () => UploadedFile::fake()->image('anim.gif')],
            'mais de 5 MB' => [fn () => UploadedFile::fake()->image('enorme.jpg')->size(5121)],
            'texto em vez de ficheiro' => [fn () => 'nao-e-um-ficheiro'],
        ];
    }

    #[Test]
    #[DataProvider('invalidFiles')]
    public function invalid_file_returns_422_and_stores_nothing(callable $makeFile): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['image' => $makeFile()])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['image'], 'errors');

        $this->assertDatabaseCount('portfolios', 0);
        $this->assertNothingStored($user);
    }

    #[Test]
    public function tenth_image_is_accepted(): void
    {
        $user = $this->memberWithProfile();
        $this->addImages($user, 9);
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['image' => UploadedFile::fake()->image('decima.jpg')])->assertCreated();

        $this->assertSame(10, $user->memberProfile->portfolios()->count());
    }

    #[Test]
    public function eleventh_image_returns_422_and_stores_nothing(): void
    {
        $user = $this->memberWithProfile();
        $this->addImages($user, 10);
        $filesBefore = Storage::disk('public')->allFiles("portfolios/{$user->id}");
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['image' => UploadedFile::fake()->image('decima-primeira.jpg')])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'O portfólio já tem o máximo de 10 imagens.')
            ->assertJsonMissingPath('errors');

        $this->assertSame(10, $user->memberProfile->portfolios()->count());
        $this->assertSame($filesBefore, Storage::disk('public')->allFiles("portfolios/{$user->id}"));
    }

    #[Test]
    public function image_limit_is_per_member(): void
    {
        $other = $this->memberWithProfile();
        $this->addImages($other, 10);
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['image' => UploadedFile::fake()->image('foto.jpg')])->assertCreated();
    }

    #[Test]
    public function deleting_an_image_frees_a_slot_under_the_limit(): void
    {
        $user = $this->memberWithProfile();
        $this->addImages($user, 9);
        $last = $this->addStoredImage($user);
        Sanctum::actingAs($user);

        $this->deleteJson(self::URL."/{$last->id}")->assertOk();
        $this->postJson(self::URL, ['image' => UploadedFile::fake()->image('nova.jpg')])->assertCreated();

        $this->assertSame(10, $user->memberProfile->portfolios()->count());
    }

    #[Test]
    public function stored_file_is_removed_when_database_insert_fails(): void
    {
        $user = $this->memberWithProfile();
        Portfolio::creating(fn () => throw new RuntimeException('Falha simulada na BD.'));
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['image' => UploadedFile::fake()->image('foto.jpg')])
            ->assertStatus(500);

        $this->assertDatabaseCount('portfolios', 0);
        $this->assertNothingStored($user);
    }

    // Os 10 primeiros pedidos são inválidos (422) para o 429 não se confundir com o 422 do limite de 10 imagens.
    #[Test]
    public function eleventh_upload_in_the_same_minute_is_throttled(): void
    {
        $user = $this->memberWithProfile();
        Sanctum::actingAs($user);

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson(self::URL, [])->assertUnprocessable();
        }

        $this->postJson(self::URL, ['image' => UploadedFile::fake()->image('foto.jpg')])
            ->assertTooManyRequests()
            ->assertExactJson(['success' => false, 'message' => 'Demasiados pedidos. Tente novamente mais tarde.']);

        $this->assertDatabaseCount('portfolios', 0);
        $this->assertNothingStored($user);
    }

    #[Test]
    public function upload_requires_authentication(): void
    {
        $this->postJson(self::URL, ['image' => UploadedFile::fake()->image('foto.jpg')])
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Não autenticado.']);

        $this->assertDatabaseCount('portfolios', 0);
    }

    #[Test]
    public function pending_member_cannot_upload(): void
    {
        $user = $this->memberWithProfile('Pending');
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['image' => UploadedFile::fake()->image('foto.jpg')])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'A conta está pendente e não pode editar dados.');

        $this->assertDatabaseCount('portfolios', 0);
        $this->assertNothingStored($user);
    }

    #[Test]
    public function inactive_member_cannot_upload(): void
    {
        $this->markTestSkipped('Depende do middleware CheckAccountActive (colega da auth).');

        $user = $this->memberWithProfile('Inactive');
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['image' => UploadedFile::fake()->image('foto.jpg')])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('portfolios', 0);
        $this->assertNothingStored($user);
    }

    #[Test]
    public function admin_without_profile_gets_not_found_on_upload(): void
    {
        $admin = $this->makeUser('Admin', 'Active');
        Sanctum::actingAs($admin);

        $this->postJson(self::URL, ['image' => UploadedFile::fake()->image('foto.jpg')])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Perfil de membro não encontrado.']);

        $this->assertNothingStored($admin);
    }

    // ---- DELETE /api/member-portfolio/{id} ----

    #[Test]
    public function member_deletes_own_image_row_and_file(): void
    {
        $user = $this->memberWithProfile();
        $image = $this->addStoredImage($user);
        $keep = $this->addStoredImage($user);
        Sanctum::actingAs($user);

        $this->deleteJson(self::URL."/{$image->id}")
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Imagem removida do portfólio.', 'data' => null]);

        $this->assertDatabaseMissing('portfolios', ['id' => $image->id]);
        Storage::disk('public')->assertMissing($image->image_path);
        $this->assertDatabaseHas('portfolios', ['id' => $keep->id]);
        Storage::disk('public')->assertExists($keep->image_path);
    }

    #[Test]
    public function member_cannot_delete_another_members_image(): void
    {
        $other = $this->memberWithProfile();
        $image = $this->addStoredImage($other);
        Sanctum::actingAs($this->memberWithProfile());

        $this->deleteJson(self::URL."/{$image->id}")
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Imagem não encontrada.']);

        $this->assertDatabaseHas('portfolios', ['id' => $image->id]);
        Storage::disk('public')->assertExists($image->image_path);
    }

    #[Test]
    public function deleting_nonexistent_image_returns_404(): void
    {
        Sanctum::actingAs($this->memberWithProfile());

        $this->deleteJson(self::URL.'/'.Str::uuid())
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Imagem não encontrada.']);
    }

    public static function nonUuidIds(): array
    {
        return [
            'texto' => ['abc'],
            'injeção SQL' => [rawurlencode('1 OR 1=1')],
        ];
    }

    #[Test]
    #[DataProvider('nonUuidIds')]
    public function deleting_with_non_uuid_id_returns_404_and_deletes_nothing(string $id): void
    {
        $user = $this->memberWithProfile();
        $image = $this->addStoredImage($user);
        $otherImage = $this->addStoredImage($this->memberWithProfile());
        Sanctum::actingAs($user);

        $this->deleteJson(self::URL."/{$id}")
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Imagem não encontrada.']);

        $this->assertDatabaseCount('portfolios', 2);
        Storage::disk('public')->assertExists($image->image_path);
        Storage::disk('public')->assertExists($otherImage->image_path);
    }

    #[Test]
    public function deleting_own_image_whose_file_is_missing_still_removes_row(): void
    {
        $user = $this->memberWithProfile();
        $image = $this->addStoredImage($user);
        Storage::disk('public')->delete($image->image_path);
        Sanctum::actingAs($user);

        $this->deleteJson(self::URL."/{$image->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Imagem removida do portfólio.');

        $this->assertDatabaseMissing('portfolios', ['id' => $image->id]);
    }

    #[Test]
    public function eleventh_delete_in_the_same_minute_is_throttled(): void
    {
        $user = $this->memberWithProfile();
        $image = $this->addStoredImage($user);
        Sanctum::actingAs($user);

        for ($i = 1; $i <= 10; $i++) {
            $this->deleteJson(self::URL.'/'.Str::uuid())->assertNotFound();
        }

        $this->deleteJson(self::URL."/{$image->id}")
            ->assertTooManyRequests()
            ->assertExactJson(['success' => false, 'message' => 'Demasiados pedidos. Tente novamente mais tarde.']);

        $this->assertDatabaseHas('portfolios', ['id' => $image->id]);
        Storage::disk('public')->assertExists($image->image_path);
    }

    #[Test]
    public function delete_requires_authentication(): void
    {
        $user = $this->memberWithProfile();
        $image = $this->addStoredImage($user);

        $this->deleteJson(self::URL."/{$image->id}")
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Não autenticado.']);

        $this->assertDatabaseHas('portfolios', ['id' => $image->id]);
        Storage::disk('public')->assertExists($image->image_path);
    }

    #[Test]
    public function pending_member_cannot_delete(): void
    {
        $user = $this->memberWithProfile('Pending');
        $image = $this->addStoredImage($user);
        Sanctum::actingAs($user);

        $this->deleteJson(self::URL."/{$image->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'A conta está pendente e não pode editar dados.');

        $this->assertDatabaseHas('portfolios', ['id' => $image->id]);
        Storage::disk('public')->assertExists($image->image_path);
    }

    #[Test]
    public function inactive_member_cannot_delete(): void
    {
        $this->markTestSkipped('Depende do middleware CheckAccountActive (colega da auth).');

        $user = $this->memberWithProfile('Inactive');
        $image = $this->addStoredImage($user);
        Sanctum::actingAs($user);

        $this->deleteJson(self::URL."/{$image->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('portfolios', ['id' => $image->id]);
        Storage::disk('public')->assertExists($image->image_path);
    }

    #[Test]
    public function admin_without_profile_gets_not_found_on_delete(): void
    {
        $member = $this->memberWithProfile();
        $image = $this->addStoredImage($member);
        Sanctum::actingAs($this->makeUser('Admin', 'Active'));

        $this->deleteJson(self::URL."/{$image->id}")
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Perfil de membro não encontrado.']);

        $this->assertDatabaseHas('portfolios', ['id' => $image->id]);
        Storage::disk('public')->assertExists($image->image_path);
    }
}
