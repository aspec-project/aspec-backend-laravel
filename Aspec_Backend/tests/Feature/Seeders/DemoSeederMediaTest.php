<?php

namespace Tests\Feature\Seeders;

use App\Models\MemberProfile;
use Database\Seeders\DemoLogoGenerator;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsDemoData;
use Tests\TestCase;

/**
 * DemoSeeder: logótipos gerados com GD e fonte TrueType livre, fotografias do portefólio por setor
 * (com licença creditada) e reposição das imagens ao repetir o seeder.
 */
#[RequiresPhpExtension('gd')]
class DemoSeederMediaTest extends TestCase
{
    use RefreshDatabase, SeedsDemoData;

    /** Tamanho máximo de cada fotografia no repositório. */
    private const MAX_PHOTO_BYTES = 150 * 1024;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDemoSeeder();
    }

    /**
     * Fotografias de origem do portefólio no repositório.
     *
     * @return list<string>
     */
    private function sourcePhotos(): array
    {
        return glob(database_path('seeders/demo/portfolio/*.jpg'));
    }

    /**
     * Nome do ficheiro de origem de uma imagem do portefólio (portfolios/{perfil}/demo-{origem}).
     */
    private function sourceName(string $imagePath): string
    {
        $this->assertMatchesRegularExpression('#^portfolios/[0-9a-f-]{36}/demo-[a-z0-9-]+\.jpg$#', $imagePath);

        return substr(basename($imagePath), strlen('demo-'));
    }

    /**
     * Conjunto da fotografia (o nome sem o número, ex.: "arquitetura" em "arquitetura-3.jpg").
     */
    private function photoSet(string $sourceName): string
    {
        return preg_replace('/-\d+\.jpg$/', '', $sourceName);
    }

    /**
     * @return EloquentCollection<int, MemberProfile>
     */
    private function profilesWithPortfolio(): EloquentCollection
    {
        return MemberProfile::listedInDirectory()
            ->has('portfolios')
            ->with(['portfolios' => fn ($query) => $query->orderBy('created_at')->orderBy('id'), 'sector', 'location'])
            ->get();
    }

    private function thirdPartyNotices(): string
    {
        return file_get_contents(base_path('../THIRD_PARTY_NOTICES.md'));
    }

    /**
     * Fotografias leves (no máximo 150 KB, JPEG), cerca de 4 a 5 por setor.
     */
    #[Test]
    public function source_photos_are_light_jpegs(): void
    {
        $photos = $this->sourcePhotos();

        $this->assertGreaterThanOrEqual(51, count($photos));

        foreach ($photos as $photo) {
            $name = basename($photo);

            $this->assertLessThanOrEqual(self::MAX_PHOTO_BYTES, filesize($photo), $name);
            $this->assertSame('image/jpeg', getimagesize($photo)['mime'] ?? null, $name);
        }
    }

    /**
     * As fotografias de construção da versão anterior (Pexels) saem do repositório e dos créditos.
     */
    #[Test]
    public function the_previous_construction_photos_are_gone(): void
    {
        $notices = $this->thirdPartyNotices();

        foreach (['5335018', '17410739', '5820968'] as $pexelsId) {
            $this->assertStringNotContainsString("pexels.com/photo/{$pexelsId}/", $notices);
        }
    }

    /**
     * Cada fotografia está creditada em THIRD_PARTY_NOTICES.md com o link da origem (Unsplash ou,
     * para as que ficam da versão anterior, Pexels).
     */
    #[Test]
    public function every_source_photo_is_credited_in_the_third_party_notices(): void
    {
        $notices = $this->thirdPartyNotices();

        foreach ($this->sourcePhotos() as $photo) {
            $name = basename($photo);

            $this->assertMatchesRegularExpression(
                '#^.*`'.preg_quote($name, '#').'`.*https://(unsplash\.com|www\.pexels\.com)/\S+.*$#m',
                $notices,
                "{$name} sem crédito"
            );
        }

        $this->assertStringContainsString('Unsplash License', $notices);
    }

    /**
     * Portefólio em cerca de 35 dos 60 perfis, com 3 a 6 imagens cada (máximo da API: 10).
     */
    #[Test]
    public function about_thirty_five_profiles_have_a_portfolio_of_three_to_six_photos(): void
    {
        $this->runDemoSeeder();

        $profiles = $this->profilesWithPortfolio();

        $this->assertGreaterThanOrEqual(30, $profiles->count());
        $this->assertLessThanOrEqual(40, $profiles->count());

        foreach ($profiles as $profile) {
            $this->assertGreaterThanOrEqual(3, $profile->portfolios->count(), $profile->business_name);
            $this->assertLessThanOrEqual(6, $profile->portfolios->count(), $profile->business_name);
        }
    }

    #[Test]
    public function portfolio_files_are_the_source_photos_byte_for_byte(): void
    {
        $this->runDemoSeeder();

        $disk = Storage::disk('public');

        foreach ($this->profilesWithPortfolio() as $profile) {
            foreach ($profile->portfolios as $image) {
                $this->assertStringStartsWith("portfolios/{$profile->id}/demo-", $image->image_path);
                $source = database_path('seeders/demo/portfolio/'.$this->sourceName($image->image_path));

                $disk->assertExists($image->image_path);
                $this->assertFileExists($source);
                $this->assertSame(sha1_file($source), sha1($disk->get($image->image_path)), $image->image_path);
            }
        }
    }

    /**
     * Fotografias por setor: cada perfil usa um só conjunto, cada conjunto pertence a um só setor e
     * uma fotografia nunca se repete no mesmo perfil.
     */
    #[Test]
    public function each_photo_set_belongs_to_one_sector(): void
    {
        $this->runDemoSeeder();

        $sectorsBySet = [];

        foreach ($this->profilesWithPortfolio() as $profile) {
            $sources = $profile->portfolios->map(fn ($image) => $this->sourceName($image->image_path));
            $sets = $sources->map(fn (string $source) => $this->photoSet($source))->unique();

            $this->assertCount(1, $sets, "{$profile->business_name}: conjuntos {$sets->implode(', ')}");
            $this->assertSame($sources->count(), $sources->unique()->count(), $profile->business_name);

            $sectorsBySet[$sets->first()][$profile->sector_id] = true;
        }

        foreach ($sectorsBySet as $set => $sectors) {
            $this->assertCount(1, $sectors, "Conjunto {$set} em vários setores");
        }
    }

    /**
     * Uma fotografia pode repetir-se entre perfis do mesmo setor, mas nunca em dois perfis do mesmo distrito.
     */
    #[Test]
    public function no_photo_is_repeated_within_a_district(): void
    {
        $this->runDemoSeeder();

        foreach ($this->profilesWithPortfolio()->groupBy('location_id') as $profiles) {
            $sources = $profiles->flatMap(fn (MemberProfile $profile) => $profile->portfolios->map(fn ($image) => $this->sourceName($image->image_path)));

            $this->assertSame(
                $sources->count(),
                $sources->unique()->count(),
                "{$profiles->first()->location->name}: repetidas {$sources->duplicates()->implode(', ')}"
            );
        }
    }

    /**
     * Na primeira página do diretório (a que o professor vê primeiro) nenhuma fotografia se repete.
     */
    #[Test]
    public function no_photo_is_repeated_on_the_first_directory_page(): void
    {
        $this->runDemoSeeder();

        $ids = collect($this->getJson('/api/members')->assertOk()->json('data.items'))->pluck('id');
        $this->assertCount(12, $ids);

        $sources = $this->profilesWithPortfolio()
            ->whereIn('id', $ids)
            ->flatMap(fn (MemberProfile $profile) => $profile->portfolios->map(fn ($image) => $this->sourceName($image->image_path)));

        $this->assertNotEmpty($sources);
        $this->assertSame($sources->count(), $sources->unique()->count(), 'Repetidas: '.$sources->duplicates()->implode(', '));
    }

    #[Test]
    public function public_profile_returns_the_portfolio_image_urls_in_order(): void
    {
        $this->runDemoSeeder();

        $profile = $this->profilesWithPortfolio()->first();
        $expected = $profile->portfolios->map(fn ($image) => Storage::disk('public')->url($image->image_path))->all();

        $response = $this->getJson("/api/members/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame($expected, array_column($response->json('data.portfolio'), 'image_url'));
    }

    /**
     * Logótipos PNG 400×400 com nome versionado em logos/{perfil}; cerca de 1/6 dos perfis fica sem
     * logótipo (o frontend mostra o marcador).
     */
    #[Test]
    public function most_profiles_get_a_400_by_400_png_logo_and_about_one_in_six_has_none(): void
    {
        $this->runDemoSeeder();

        $profiles = $this->directoryProfiles();
        $withoutLogo = $profiles->whereNull('logo_path');

        $this->assertGreaterThanOrEqual(7, $withoutLogo->count());
        $this->assertLessThanOrEqual(13, $withoutLogo->count());

        foreach ($profiles->whereNotNull('logo_path') as $profile) {
            $this->assertSame("logos/{$profile->id}/demo-logo-v2.png", $profile->logo_path);
            Storage::disk('public')->assertExists($profile->logo_path);

            $size = getimagesizefromstring(Storage::disk('public')->get($profile->logo_path));
            $this->assertSame('image/png', $size['mime'] ?? null, $profile->business_name);
            $this->assertSame([400, 400], [$size[0], $size[1]], $profile->business_name);
        }
    }

    /**
     * O gerador é determinístico (o mesmo nome dá o mesmo logótipo) e produz PNG 400×400.
     */
    #[Test]
    public function logo_generator_produces_the_same_400_by_400_png_for_the_same_business(): void
    {
        $generator = app(DemoLogoGenerator::class);

        $first = $generator->generate('Ferreira Arquitetura e Interiores', 3);
        $second = $generator->generate('Ferreira Arquitetura e Interiores', 3);
        $size = getimagesizefromstring($first);

        $this->assertSame('image/png', $size['mime'] ?? null);
        $this->assertSame([400, 400], [$size[0], $size[1]]);
        $this->assertSame(sha1($first), sha1($second));
        $this->assertNotSame(sha1($first), sha1($generator->generate('Sabores do Algarve Restaurante', 3)));
    }

    /**
     * Sem FreeType (simulado) o seeder não falha: faz o logótipo simples e avisa.
     */
    #[Test]
    public function without_freetype_logos_fall_back_to_the_simple_version_with_a_warning(): void
    {
        $this->partialMock(DemoLogoGenerator::class, function (MockInterface $mock) {
            $mock->shouldReceive('supportsTrueType')->andReturn(false);
        });

        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])
            ->expectsOutputToContain('FreeType')
            ->assertSuccessful();

        $withLogo = $this->directoryProfiles()->whereNotNull('logo_path');
        $this->assertNotEmpty($withLogo);

        foreach ($withLogo as $profile) {
            $size = getimagesizefromstring(Storage::disk('public')->get($profile->logo_path));

            $this->assertSame('image/png', $size['mime'] ?? null, $profile->business_name);
            $this->assertSame([400, 400], [$size[0], $size[1]], $profile->business_name);
        }
    }

    /**
     * O logótipo da versão anterior (demo-logo.png) sai ao repetir o seeder; o novo tem nome
     * versionado para o R2 não servir o antigo em cache.
     */
    #[Test]
    public function running_again_removes_the_previous_version_logo(): void
    {
        $this->runDemoSeeder();

        $profile = $this->directoryProfiles()->whereNotNull('logo_path')->first();
        $oldLogo = "logos/{$profile->id}/demo-logo.png";
        Storage::disk('public')->put($oldLogo, 'logótipo antigo');

        $this->runDemoSeeder();

        Storage::disk('public')->assertMissing($oldLogo);
        Storage::disk('public')->assertExists("logos/{$profile->id}/demo-logo-v2.png");
        $this->assertSame("logos/{$profile->id}/demo-logo-v2.png", $profile->fresh()->logo_path);
    }

    #[Test]
    public function demo_media_is_never_stored_under_the_user_id(): void
    {
        $this->runDemoSeeder();

        foreach (MemberProfile::with('portfolios')->get() as $profile) {
            $this->assertSame([], Storage::disk('public')->allFiles("logos/{$profile->user_id}"), $profile->business_name);
            $this->assertSame([], Storage::disk('public')->allFiles("portfolios/{$profile->user_id}"), $profile->business_name);

            if ($profile->logo_path !== null) {
                $this->assertStringNotContainsString($profile->user_id, $profile->logo_path);
            }

            foreach ($profile->portfolios as $image) {
                $this->assertStringNotContainsString($profile->user_id, $image->image_path);
            }
        }
    }

    /**
     * As imagens de demonstração que já não fazem parte do conjunto saem ao repetir o seeder; uma
     * imagem carregada pelo próprio membro, no mesmo perfil, fica.
     */
    #[Test]
    public function running_again_removes_old_demo_images_but_keeps_member_uploads(): void
    {
        $this->runDemoSeeder();

        $profile = $this->profilesWithPortfolio()->first();
        $expected = $profile->portfolios->pluck('image_path')->all();
        $oldDemo = "portfolios/{$profile->id}/demo-construcao-1.jpg";
        $memberUpload = "portfolios/{$profile->id}/abc.jpg";

        Storage::disk('public')->put($oldDemo, 'old demo image');
        Storage::disk('public')->put($memberUpload, 'member upload');
        $profile->portfolios()->create(['image_path' => $oldDemo]);
        $profile->portfolios()->create(['image_path' => $memberUpload]);

        $this->runDemoSeeder();

        $this->assertDatabaseMissing('portfolios', ['image_path' => $oldDemo]);
        Storage::disk('public')->assertMissing($oldDemo);

        $this->assertDatabaseHas('portfolios', ['profile_id' => $profile->id, 'image_path' => $memberUpload]);
        Storage::disk('public')->assertExists($memberUpload);

        $this->assertSame(count($expected) + 1, $profile->portfolios()->count());

        foreach ($expected as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    /**
     * Numa máquina semeada antes das pastas por perfil, repetir o seeder troca as imagens de
     * demonstração da pasta antiga pelas da pasta do perfil, sem duplicar o portefólio.
     */
    #[Test]
    public function running_again_replaces_demo_media_left_in_the_old_user_folders(): void
    {
        $this->runDemoSeeder();

        $disk = Storage::disk('public');
        $profile = $this->profilesWithPortfolio()->first(fn (MemberProfile $profile) => $profile->logo_path !== null);
        $this->assertNotNull($profile);
        $expected = $profile->portfolios->pluck('image_path')->sort()->values()->all();

        $oldLogo = "logos/{$profile->user_id}/demo-logo.png";
        $disk->move($profile->logo_path, $oldLogo);
        $profile->update(['logo_path' => $oldLogo]);

        foreach ($profile->portfolios as $image) {
            $oldPath = "portfolios/{$profile->user_id}/".basename($image->image_path);
            $disk->move($image->image_path, $oldPath);
            $image->update(['image_path' => $oldPath]);
        }

        $this->runDemoSeeder();

        $profile->refresh();
        $this->assertSame("logos/{$profile->id}/demo-logo-v2.png", $profile->logo_path);
        $disk->assertExists($profile->logo_path);
        $this->assertSame([], $disk->allFiles("logos/{$profile->user_id}"));
        $this->assertSame($expected, $profile->portfolios()->orderBy('image_path')->pluck('image_path')->all());
        $this->assertSame([], $disk->allFiles("portfolios/{$profile->user_id}"));
    }
}
