<?php

namespace Tests\Feature\Console;

use App\Models\MemberProfile;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Comando media:move-to-profile-folders: passa os logótipos e o portfólio das pastas antigas por id do
 * utilizador (logos/{user_id}, portfolios/{user_id}) para as pastas por id do perfil, para o URL público
 * deixar de revelar o user_id.
 *
 * Por ficheiro move primeiro e só depois atualiza a linha: no S3 o move é copiar + apagar (não atómico),
 * e nesta ordem uma falha a meio deixa sempre um estado que a execução seguinte resolve.
 */
class MoveMediaToProfileFoldersTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'media:move-to-profile-folders';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function disk(): Filesystem
    {
        return Storage::disk('public');
    }

    /**
     * Perfil com logótipo e duas imagens do portfólio ainda nas pastas por id do utilizador.
     */
    private function legacyProfile(): MemberProfile
    {
        $user = User::factory()->create();
        $profile = MemberProfile::factory()->create(['user_id' => $user->id]);

        $profile->update(['logo_path' => "logos/{$user->id}/logo.png"]);
        $this->disk()->put("logos/{$user->id}/logo.png", 'logo');

        foreach (['a.jpg', 'b.jpg'] as $name) {
            $this->disk()->put("portfolios/{$user->id}/{$name}", "foto {$name}");
            Portfolio::create(['profile_id' => $profile->id, 'image_path' => "portfolios/{$user->id}/{$name}"]);
        }

        return $profile;
    }

    /**
     * @return list<string>
     */
    private function portfolioPaths(MemberProfile $profile): array
    {
        return Portfolio::where('profile_id', $profile->id)->orderBy('image_path')->pluck('image_path')->all();
    }

    private function logoPath(MemberProfile $profile): ?string
    {
        return MemberProfile::withTrashed()->whereKey($profile->id)->value('logo_path');
    }

    // ---- Mover ----

    #[Test]
    public function moves_the_logo_to_the_profile_folder_and_updates_the_row(): void
    {
        $profile = $this->legacyProfile();

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame("logos/{$profile->id}/logo.png", $this->logoPath($profile));
        $this->disk()->assertExists("logos/{$profile->id}/logo.png");
        $this->disk()->assertMissing("logos/{$profile->user_id}/logo.png");
        $this->assertSame('logo', $this->disk()->get("logos/{$profile->id}/logo.png"));
    }

    #[Test]
    public function moves_the_portfolio_images_to_the_profile_folder_and_updates_the_rows(): void
    {
        $profile = $this->legacyProfile();

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame(
            ["portfolios/{$profile->id}/a.jpg", "portfolios/{$profile->id}/b.jpg"],
            $this->portfolioPaths($profile),
        );
        $this->disk()->assertExists("portfolios/{$profile->id}/a.jpg");
        $this->disk()->assertExists("portfolios/{$profile->id}/b.jpg");
        $this->assertSame([], $this->disk()->allFiles("portfolios/{$profile->user_id}"));
    }

    #[Test]
    public function empty_user_folders_are_removed_at_the_end(): void
    {
        $profile = $this->legacyProfile();

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertFalse($this->disk()->directoryExists("logos/{$profile->user_id}"));
        $this->assertFalse($this->disk()->directoryExists("portfolios/{$profile->user_id}"));
    }

    #[Test]
    public function user_folder_with_a_file_not_referenced_by_any_row_is_kept(): void
    {
        $profile = $this->legacyProfile();
        $this->disk()->put("logos/{$profile->user_id}/sem-linha.png", 'ficheiro sem linha na BD');

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->disk()->assertExists("logos/{$profile->user_id}/sem-linha.png");
    }

    #[Test]
    public function soft_deleted_profiles_are_included(): void
    {
        $profile = $this->legacyProfile();
        $profile->delete();

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame("logos/{$profile->id}/logo.png", $this->logoPath($profile));
        $this->disk()->assertExists("logos/{$profile->id}/logo.png");
        $this->assertSame(
            ["portfolios/{$profile->id}/a.jpg", "portfolios/{$profile->id}/b.jpg"],
            $this->portfolioPaths($profile),
        );
    }

    #[Test]
    public function other_profiles_are_moved_to_their_own_folders(): void
    {
        $first = $this->legacyProfile();
        $second = $this->legacyProfile();

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame("logos/{$first->id}/logo.png", $this->logoPath($first));
        $this->assertSame("logos/{$second->id}/logo.png", $this->logoPath($second));
        $this->assertCount(1, $this->disk()->allFiles("logos/{$first->id}"));
        $this->assertCount(1, $this->disk()->allFiles("logos/{$second->id}"));
    }

    // ---- Idempotência e retoma ----

    #[Test]
    public function second_run_changes_nothing(): void
    {
        $profile = $this->legacyProfile();
        $this->artisan(self::COMMAND)->assertSuccessful();
        $filesAfterFirstRun = $this->disk()->allFiles();
        $portfolioAfterFirstRun = $this->portfolioPaths($profile);

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame($filesAfterFirstRun, $this->disk()->allFiles());
        $this->assertSame("logos/{$profile->id}/logo.png", $this->logoPath($profile));
        $this->assertSame($portfolioAfterFirstRun, $this->portfolioPaths($profile));
    }

    #[Test]
    public function paths_already_in_the_profile_folder_are_left_alone(): void
    {
        $user = User::factory()->create();
        $profile = MemberProfile::factory()->create(['user_id' => $user->id]);
        $profile->update(['logo_path' => "logos/{$profile->id}/logo.png"]);
        $this->disk()->put("logos/{$profile->id}/logo.png", 'logo');

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame("logos/{$profile->id}/logo.png", $this->logoPath($profile));
        $this->assertSame(["logos/{$profile->id}/logo.png"], $this->disk()->allFiles());
    }

    #[Test]
    public function file_already_moved_but_row_not_updated_only_updates_the_row(): void
    {
        $profile = $this->legacyProfile();
        $this->disk()->move("logos/{$profile->user_id}/logo.png", "logos/{$profile->id}/logo.png");
        $this->disk()->move("portfolios/{$profile->user_id}/a.jpg", "portfolios/{$profile->id}/a.jpg");

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame("logos/{$profile->id}/logo.png", $this->logoPath($profile));
        $this->assertSame('logo', $this->disk()->get("logos/{$profile->id}/logo.png"));
        $this->assertSame(
            ["portfolios/{$profile->id}/a.jpg", "portfolios/{$profile->id}/b.jpg"],
            $this->portfolioPaths($profile),
        );
    }

    #[Test]
    public function file_missing_in_both_places_keeps_the_row_and_the_rest_is_still_moved(): void
    {
        $profile = $this->legacyProfile();
        $this->disk()->delete("portfolios/{$profile->user_id}/a.jpg");

        Artisan::call(self::COMMAND);

        $this->assertEqualsCanonicalizing(
            ["portfolios/{$profile->id}/b.jpg", "portfolios/{$profile->user_id}/a.jpg"],
            $this->portfolioPaths($profile),
        );
        $this->assertSame("logos/{$profile->id}/logo.png", $this->logoPath($profile));
        $this->disk()->assertMissing("portfolios/{$profile->id}/a.jpg");
    }

    #[Test]
    public function file_missing_in_both_places_is_reported_in_the_output(): void
    {
        $profile = $this->legacyProfile();
        $this->disk()->delete("logos/{$profile->user_id}/logo.png");

        Artisan::call(self::COMMAND);

        $this->assertStringContainsString("logos/{$profile->user_id}/logo.png", Artisan::output());
        $this->assertSame("logos/{$profile->user_id}/logo.png", $this->logoPath($profile));
    }

    #[Test]
    public function path_outside_the_user_folder_is_left_alone(): void
    {
        $user = User::factory()->create();
        $profile = MemberProfile::factory()->create(['user_id' => $user->id]);
        $profile->update(['logo_path' => 'logos/outra-pasta/logo.png']);
        $this->disk()->put('logos/outra-pasta/logo.png', 'logo');

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame('logos/outra-pasta/logo.png', $this->logoPath($profile));
        $this->assertSame(['logos/outra-pasta/logo.png'], $this->disk()->allFiles());
    }

    // ---- Segurança: o destino usa só o basename do caminho guardado ----

    #[Test]
    public function destination_uses_only_the_basename_of_a_path_with_dot_dot(): void
    {
        $user = User::factory()->create();
        $profile = MemberProfile::factory()->create(['user_id' => $user->id]);
        $profile->update(['logo_path' => "logos/{$user->id}/../../fora/logo.png"]);
        $this->disk()->put("logos/{$profile->id}/logo.png", 'logo');

        Artisan::call(self::COMMAND);

        $this->assertSame("logos/{$profile->id}/logo.png", $this->logoPath($profile));
        $this->assertSame(["logos/{$profile->id}/logo.png"], $this->disk()->allFiles());
    }

    // ---- --dry-run ----

    #[Test]
    public function dry_run_changes_no_file_and_no_row(): void
    {
        $profile = $this->legacyProfile();
        $filesBefore = $this->disk()->allFiles();
        $portfolioBefore = $this->portfolioPaths($profile);

        $this->artisan(self::COMMAND, ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($filesBefore, $this->disk()->allFiles());
        $this->assertSame("logos/{$profile->user_id}/logo.png", $this->logoPath($profile));
        $this->assertSame($portfolioBefore, $this->portfolioPaths($profile));
        $this->assertTrue($this->disk()->directoryExists("logos/{$profile->user_id}"));
    }

    #[Test]
    public function dry_run_then_real_run_moves_everything(): void
    {
        $profile = $this->legacyProfile();

        $this->artisan(self::COMMAND, ['--dry-run' => true])->assertSuccessful();
        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame("logos/{$profile->id}/logo.png", $this->logoPath($profile));
        $this->disk()->assertExists("logos/{$profile->id}/logo.png");
    }

    // ---- Disco configurado ----

    #[Test]
    public function works_on_the_configured_r2_disk_and_never_touches_public(): void
    {
        config(['filesystems.media_disk' => 'r2']);
        $r2 = Storage::fake('r2', ['url' => 'https://media.test']);
        $user = User::factory()->create();
        $profile = MemberProfile::factory()->create(['user_id' => $user->id]);
        $profile->update(['logo_path' => "logos/{$user->id}/logo.png"]);
        $r2->put("logos/{$user->id}/logo.png", 'logo');
        $r2->put("portfolios/{$user->id}/a.jpg", 'foto');
        Portfolio::create(['profile_id' => $profile->id, 'image_path' => "portfolios/{$user->id}/a.jpg"]);

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame("logos/{$profile->id}/logo.png", $this->logoPath($profile));
        $this->assertSame(["portfolios/{$profile->id}/a.jpg"], $this->portfolioPaths($profile));
        $r2->assertExists("logos/{$profile->id}/logo.png");
        $r2->assertExists("portfolios/{$profile->id}/a.jpg");
        $r2->assertMissing("logos/{$user->id}/logo.png");
        $this->assertSame([], $this->disk()->allFiles());
    }

    #[Test]
    public function runs_without_profiles(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();
    }

    // ---- Revisão de segurança ----

    #[Test]
    public function never_overwrites_a_file_that_already_exists_in_the_profile_folder(): void
    {
        $profile = $this->legacyProfile();
        $this->disk()->put("logos/{$profile->id}/logo.png", 'outro conteúdo');

        $this->artisan(self::COMMAND)->assertFailed();

        $this->assertSame('outro conteúdo', $this->disk()->get("logos/{$profile->id}/logo.png"));
        $this->assertSame('logo', $this->disk()->get("logos/{$profile->user_id}/logo.png"));
        $this->assertSame("logos/{$profile->user_id}/logo.png", $this->logoPath($profile));
    }

    #[Test]
    public function a_path_with_backslashes_is_never_read_nor_moved(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $profile = MemberProfile::factory()->create(['user_id' => $user->id]);
        $profile->update(['logo_path' => "logos/{$user->id}/..\\{$other->id}\\x.png"]);
        $this->disk()->put("logos/{$other->id}/x.png", 'do outro');

        Artisan::call(self::COMMAND);

        $this->assertSame('do outro', $this->disk()->get("logos/{$other->id}/x.png"));
        $this->assertSame([], $this->disk()->allFiles("logos/{$profile->id}"));
        $this->assertSame("logos/{$user->id}/..\\{$other->id}\\x.png", $this->logoPath($profile));
    }

    #[Test]
    public function an_anonymised_profile_is_skipped_so_its_media_is_never_recreated(): void
    {
        $user = User::factory()->create();
        $profile = MemberProfile::factory()->create(['user_id' => $user->id]);
        $profile->update(['logo_path' => null]);
        $profile->delete();
        $this->disk()->put("logos/{$user->id}/esquecido.png", 'logo');

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame([], $this->disk()->allFiles("logos/{$profile->id}"));
        $this->assertNull($this->logoPath($profile));
    }
}
