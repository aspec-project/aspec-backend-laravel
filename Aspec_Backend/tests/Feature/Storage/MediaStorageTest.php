<?php

namespace Tests\Feature\Storage;

use App\Support\MediaStorage;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * MediaStorage: o único sítio que sabe qual é o disco das imagens (logótipos e portefólio) e
 * como se gera o URL público. Os controllers e os Resources nunca escolhem o disco.
 */
class MediaStorageTest extends TestCase
{
    private const R2_URL = 'https://media.test';

    private function useFakeR2(): void
    {
        config(['filesystems.media_disk' => 'r2']);
        Storage::fake('r2', ['url' => self::R2_URL]);
    }

    #[Test]
    public function url_of_null_is_null(): void
    {
        $this->assertNull(MediaStorage::url(null));
    }

    #[Test]
    public function url_of_an_empty_path_is_null(): void
    {
        $this->assertNull(MediaStorage::url(''));
    }

    #[Test]
    public function public_disk_gives_the_app_storage_url(): void
    {
        config(['filesystems.media_disk' => 'public']);

        $this->assertSame(
            rtrim(config('app.url'), '/').'/storage/logos/abc/logo.png',
            MediaStorage::url('logos/abc/logo.png')
        );
    }

    #[Test]
    public function disk_is_the_configured_media_disk(): void
    {
        $this->useFakeR2();

        MediaStorage::disk()->put('logos/abc/logo.png', 'conteudo');

        Storage::disk('r2')->assertExists('logos/abc/logo.png');
    }

    #[Test]
    public function r2_disk_gives_the_public_bucket_url(): void
    {
        $this->useFakeR2();

        $this->assertSame(self::R2_URL.'/logos/abc/logo.png', MediaStorage::url('logos/abc/logo.png'));
    }

    #[Test]
    public function changing_the_media_disk_changes_the_url_without_touching_code(): void
    {
        Storage::fake('r2', ['url' => self::R2_URL]);

        config(['filesystems.media_disk' => 'public']);
        $local = MediaStorage::url('portfolios/abc/foto.jpg');

        config(['filesystems.media_disk' => 'r2']);
        $r2 = MediaStorage::url('portfolios/abc/foto.jpg');

        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/storage/', $local);
        $this->assertSame(self::R2_URL.'/portfolios/abc/foto.jpg', $r2);
    }

    #[Test]
    public function s3_disk_without_url_refuses_to_build_a_url(): void
    {
        // Sem URL público o adapter S3 devolvia o endpoint da conta (account id) e o nome do bucket.
        config([
            'filesystems.disks.s3-sem-url' => [
                'driver' => 's3',
                'key' => 'test',
                'secret' => 'test',
                'region' => 'auto',
                'bucket' => 'aspec-media',
                'endpoint' => 'http://127.0.0.1:9',
                'use_path_style_endpoint' => true,
                'throw' => false,
            ],
            'filesystems.media_disk' => 's3-sem-url',
        ]);

        $this->expectException(RuntimeException::class);

        MediaStorage::url('logos/abc/logo.png');
    }
}
