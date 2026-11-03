<?php

namespace Tests\Feature\Storage;

use App\Models\MemberProfile;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsDirectoryMembers;
use Tests\TestCase;

/**
 * Logótipos e portefólio com MEDIA_DISK=r2: os ficheiros vão para o disco de media configurado,
 * nada fica no disco public, e os URLs devolvidos são os do bucket público (R2_MEDIA_URL).
 * O disco r2 é sempre um fake local: estes testes nunca tocam no Cloudflare.
 */
class MediaOnR2Test extends TestCase
{
    use BuildsDirectoryMembers, RefreshDatabase;

    private const R2_URL = 'https://media.test';

    private const LOGO_URL = '/api/member-profile/logo';

    private const PORTFOLIO_URL = '/api/member-portfolio';

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.media_disk' => 'r2']);
        Storage::fake('r2', ['url' => self::R2_URL]);
        Storage::fake('public');
    }

    private function member(): User
    {
        $user = User::factory()->create();
        MemberProfile::factory()->create(['user_id' => $user->id, 'logo_path' => null]);

        return $user;
    }

    private function storedOnR2(string $path): string
    {
        Storage::disk('r2')->put($path, 'conteudo');

        return $path;
    }

    private function assertPublicDiskIsEmpty(): void
    {
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /**
     * Nenhuma resposta pode expor o endpoint S3 da conta (tem o account id) nem o caminho local.
     */
    private function assertNoPrivateEndpoint(TestResponse $response): void
    {
        $response->assertDontSee('r2.cloudflarestorage.com', false);
        $response->assertDontSee('/storage/', false);
    }

    // --- Logótipo ---

    #[Test]
    public function logo_is_stored_on_the_media_disk_and_not_on_public(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);

        $this->postJson(self::LOGO_URL, ['logo' => UploadedFile::fake()->image('logo.png')])->assertOk();

        $path = $user->memberProfile->fresh()->logo_path;
        $this->assertIsString($path);
        $this->assertStringStartsWith('logos/', $path);
        Storage::disk('r2')->assertExists($path);
        $this->assertPublicDiskIsEmpty();
    }

    #[Test]
    public function logo_upload_returns_the_public_bucket_url(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);

        $response = $this->postJson(self::LOGO_URL, ['logo' => UploadedFile::fake()->image('logo.png')])->assertOk();

        $path = $user->memberProfile->fresh()->logo_path;
        $response->assertJsonPath('data.logo_url', self::R2_URL.'/'.$path);
        $this->assertNoPrivateEndpoint($response);
    }

    #[Test]
    public function replacing_the_logo_deletes_the_old_file_on_the_media_disk(): void
    {
        $user = $this->member();
        $oldPath = $this->storedOnR2("logos/{$user->id}/".Str::random(40).'.png');
        $user->memberProfile->update(['logo_path' => $oldPath]);
        Sanctum::actingAs($user);

        $this->postJson(self::LOGO_URL, ['logo' => UploadedFile::fake()->image('novo.png')])->assertOk();

        $newPath = $user->memberProfile->fresh()->logo_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('r2')->assertMissing($oldPath);
        Storage::disk('r2')->assertExists($newPath);
    }

    // --- Portefólio ---

    #[Test]
    public function portfolio_image_is_stored_on_the_media_disk_and_not_on_public(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);

        $this->postJson(self::PORTFOLIO_URL, ['image' => UploadedFile::fake()->image('foto.jpg')])->assertCreated();

        $image = $user->memberProfile->portfolios()->sole();
        $this->assertStringStartsWith('portfolios/', $image->image_path);
        Storage::disk('r2')->assertExists($image->image_path);
        $this->assertPublicDiskIsEmpty();
    }

    #[Test]
    public function portfolio_upload_returns_the_public_bucket_url(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);

        $response = $this->postJson(self::PORTFOLIO_URL, ['image' => UploadedFile::fake()->image('foto.jpg')])
            ->assertCreated();

        $image = $user->memberProfile->portfolios()->sole();
        $response->assertJsonPath('data.image_url', self::R2_URL.'/'.$image->image_path);
        $this->assertNoPrivateEndpoint($response);
    }

    #[Test]
    public function deleting_a_portfolio_image_deletes_the_file_on_the_media_disk(): void
    {
        $user = $this->member();
        $path = $this->storedOnR2("portfolios/{$user->id}/".Str::random(40).'.jpg');
        $image = Portfolio::create(['profile_id' => $user->memberProfile->id, 'image_path' => $path]);
        Sanctum::actingAs($user);

        $this->deleteJson(self::PORTFOLIO_URL.'/'.$image->id)->assertOk();

        Storage::disk('r2')->assertMissing($path);
        $this->assertDatabaseMissing('portfolios', ['id' => $image->id]);
    }

    // --- Anonimização ---

    #[Test]
    public function anonymization_deletes_the_member_folders_on_the_media_disk(): void
    {
        $user = $this->member();
        $logo = $this->storedOnR2("logos/{$user->id}/logo.png");
        $photo = $this->storedOnR2("portfolios/{$user->id}/a.jpg");

        $this->assertTrue($user->fresh()->anonymizeAndDelete());

        Storage::disk('r2')->assertMissing($logo);
        Storage::disk('r2')->assertMissing($photo);
    }

    #[Test]
    public function anonymization_keeps_other_members_files_on_the_media_disk(): void
    {
        $user = $this->member();
        $other = $this->member();
        $otherLogo = $this->storedOnR2("logos/{$other->id}/logo.png");
        $otherPhoto = $this->storedOnR2("portfolios/{$other->id}/a.jpg");

        $user->fresh()->anonymizeAndDelete();

        Storage::disk('r2')->assertExists($otherLogo);
        Storage::disk('r2')->assertExists($otherPhoto);
    }

    // --- URLs nas respostas ---

    #[Test]
    public function directory_list_returns_logo_urls_from_the_public_bucket(): void
    {
        $profile = $this->listedMember(['logo_path' => 'logos/abc/logo.png']);

        $response = $this->listMembers()->assertOk();

        $response->assertJsonPath('data.items.0.id', $profile->id)
            ->assertJsonPath('data.items.0.logo_url', self::R2_URL.'/logos/abc/logo.png');
        $this->assertNoPrivateEndpoint($response);
    }

    #[Test]
    public function public_profile_returns_logo_and_portfolio_urls_from_the_public_bucket(): void
    {
        $profile = $this->listedMember(['logo_path' => 'logos/abc/logo.png']);
        $image = Portfolio::create(['profile_id' => $profile->id, 'image_path' => 'portfolios/abc/foto.jpg']);

        $response = $this->showMember($profile->id)->assertOk();

        $response->assertJsonPath('data.logo_url', self::R2_URL.'/logos/abc/logo.png')
            ->assertJsonPath('data.portfolio', [
                ['id' => $image->id, 'image_url' => self::R2_URL.'/portfolios/abc/foto.jpg'],
            ]);
        $this->assertNoPrivateEndpoint($response);
    }

    #[Test]
    public function own_profile_returns_logo_and_portfolio_urls_from_the_public_bucket(): void
    {
        $user = $this->member();
        $user->memberProfile->update(['logo_path' => 'logos/abc/logo.png']);
        $image = Portfolio::create(['profile_id' => $user->memberProfile->id, 'image_path' => 'portfolios/abc/foto.jpg']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/member-profile')->assertOk();

        $response->assertJsonPath('data.logo_url', self::R2_URL.'/logos/abc/logo.png')
            ->assertJsonPath('data.portfolio.0', [
                'id' => $image->id,
                'image_url' => self::R2_URL.'/portfolios/abc/foto.jpg',
            ]);
        $this->assertNoPrivateEndpoint($response);
    }

    #[Test]
    public function profile_without_logo_still_returns_null_with_r2(): void
    {
        $profile = $this->listedMember();

        $this->showMember($profile->id)
            ->assertOk()
            ->assertJsonPath('data.logo_url', null);
    }
}
