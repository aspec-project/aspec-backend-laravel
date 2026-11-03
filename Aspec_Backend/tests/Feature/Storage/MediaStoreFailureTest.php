<?php

namespace Tests\Feature\Storage;

use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gravação falhada no disco de media. Com 'throw' => false, o Flysystem devolve false em vez de
 * lançar (ex. falha de rede no R2): o upload tem de dar 500 sem gravar nada na BD, em vez de
 * guardar logo_path = false ou uma linha do portefólio sem ficheiro.
 */
class MediaStoreFailureTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_500 = ['success' => false, 'message' => 'Ocorreu um erro interno. Tente novamente mais tarde.'];

    private MockInterface $disk;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false, 'filesystems.media_disk' => 'public']);

        $this->disk = Mockery::mock(Filesystem::class)->shouldIgnoreMissing();
        $this->disk->shouldReceive('putFileAs')->andReturn(false);
        $this->disk->shouldReceive('putFile')->andReturn(false);
        Storage::set('public', $this->disk);
    }

    private function member(?string $logoPath = null): User
    {
        $user = User::factory()->create();
        MemberProfile::factory()->create(['user_id' => $user->id, 'logo_path' => $logoPath]);

        return $user;
    }

    #[Test]
    public function failed_logo_write_returns_500_and_keeps_the_logo_path(): void
    {
        $user = $this->member('logos/abc/antigo.png');
        Sanctum::actingAs($user);

        $this->postJson('/api/member-profile/logo', ['logo' => UploadedFile::fake()->image('novo.png')])
            ->assertStatus(500)
            ->assertExactJson(self::GENERIC_500);

        $this->assertSame('logos/abc/antigo.png', $user->memberProfile->fresh()->logo_path);
    }

    #[Test]
    public function failed_logo_write_never_deletes_the_old_logo(): void
    {
        $user = $this->member('logos/abc/antigo.png');
        $this->disk->shouldNotReceive('delete');
        Sanctum::actingAs($user);

        $this->postJson('/api/member-profile/logo', ['logo' => UploadedFile::fake()->image('novo.png')])
            ->assertStatus(500);
    }

    #[Test]
    public function failed_first_logo_write_leaves_the_profile_without_logo(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);

        $this->postJson('/api/member-profile/logo', ['logo' => UploadedFile::fake()->image('novo.png')])
            ->assertStatus(500);

        $this->assertNull($user->memberProfile->fresh()->logo_path);
    }

    #[Test]
    public function failed_portfolio_write_returns_500_and_creates_no_row(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);

        $this->postJson('/api/member-portfolio', ['image' => UploadedFile::fake()->image('foto.jpg')])
            ->assertStatus(500)
            ->assertExactJson(self::GENERIC_500);

        $this->assertDatabaseCount('portfolios', 0);
    }
}
