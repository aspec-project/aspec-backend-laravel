<?php

namespace Tests\Feature\Storage;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Configuração dos discos de ficheiros: o disco de media e o das faturas escolhem-se pelo .env,
 * e os discos do Cloudflare R2 existem mas nunca são usados pelos testes.
 *
 * Os valores do endpoint e das chaves são verificados com assertTrue de propósito: se o teste
 * falhar, a mensagem não pode mostrar os valores reais do .env da máquina.
 */
class StorageConfigTest extends TestCase
{
    // --- Ambiente de testes: discos locais, mesmo com MEDIA_DISK=r2 no .env ---

    #[Test]
    public function tests_always_use_the_local_media_disk(): void
    {
        $this->assertSame('public', env('MEDIA_DISK'));
        $this->assertSame('public', config('filesystems.media_disk'));
    }

    #[Test]
    public function tests_always_use_the_local_private_invoices_disk(): void
    {
        $this->assertSame('local', env('INVOICES_DISK'));
        $this->assertSame('local', config('filesystems.invoices_disk'));
    }

    #[Test]
    public function r2_disks_point_to_a_closed_local_port_in_tests(): void
    {
        // Rede de segurança: um teste que use o disco R2 real sem fake falha logo em vez de gravar no bucket.
        $this->assertTrue(config('filesystems.disks.r2.endpoint') === 'http://127.0.0.1:9', 'O disco r2 não usa o endpoint local fechado nos testes.');
        $this->assertTrue(config('filesystems.disks.r2-invoices.endpoint') === 'http://127.0.0.1:9', 'O disco r2-invoices não usa o endpoint local fechado nos testes.');
    }

    #[Test]
    public function r2_keys_are_fake_in_tests(): void
    {
        foreach (['r2', 'r2-invoices'] as $disk) {
            $this->assertTrue(config("filesystems.disks.{$disk}.key") === 'test', "O disco {$disk} não usa a chave falsa nos testes.");
            $this->assertTrue(config("filesystems.disks.{$disk}.secret") === 'test', "O disco {$disk} não usa o segredo falso nos testes.");
        }
    }

    // --- Definição dos discos R2 ---

    #[Test]
    public function r2_media_disk_is_an_s3_disk_with_the_settings_r2_requires(): void
    {
        $disk = config('filesystems.disks.r2');

        $this->assertIsArray($disk, 'Falta o disco r2 em config/filesystems.php.');
        $this->assertSame('s3', $disk['driver'] ?? null);
        $this->assertSame('auto', $disk['region'] ?? null);
        $this->assertTrue($disk['use_path_style_endpoint'] ?? null);
        $this->assertArrayHasKey('bucket', $disk);
        $this->assertArrayHasKey('url', $disk);
    }

    #[Test]
    public function r2_media_disk_caches_images_for_a_year(): void
    {
        // Seguro porque cada upload tem um nome novo: um ficheiro nunca muda de conteúdo.
        $this->assertSame(
            ['CacheControl' => 'public, max-age=31536000, immutable'],
            config('filesystems.disks.r2.options')
        );
    }

    #[Test]
    public function r2_disks_have_no_visibility_so_no_acl_header_is_sent(): void
    {
        // O R2 não usa ACLs por objeto; com visibility o Flysystem enviava x-amz-acl.
        $this->assertIsArray(config('filesystems.disks.r2'));
        $this->assertIsArray(config('filesystems.disks.r2-invoices'));
        $this->assertArrayNotHasKey('visibility', config('filesystems.disks.r2'));
        $this->assertArrayNotHasKey('visibility', config('filesystems.disks.r2-invoices'));
    }

    #[Test]
    public function r2_disks_never_throw_and_report_failures_to_the_log(): void
    {
        foreach (['r2', 'r2-invoices'] as $disk) {
            $this->assertFalse(config("filesystems.disks.{$disk}.throw"), $disk);
            $this->assertTrue(config("filesystems.disks.{$disk}.report"), $disk);
        }
    }

    #[Test]
    public function r2_invoices_disk_is_private_and_has_no_url(): void
    {
        $disk = config('filesystems.disks.r2-invoices');

        $this->assertIsArray($disk, 'Falta o disco r2-invoices em config/filesystems.php.');
        $this->assertSame('s3', $disk['driver'] ?? null);
        $this->assertSame('auto', $disk['region'] ?? null);
        $this->assertTrue($disk['use_path_style_endpoint'] ?? null);
        $this->assertArrayHasKey('bucket', $disk);
        $this->assertArrayNotHasKey('url', $disk);
        $this->assertArrayNotHasKey('options', $disk);
    }

    #[Test]
    public function local_disks_keep_their_current_definition(): void
    {
        $this->assertSame('local', config('filesystems.disks.public.driver'));
        $this->assertSame(storage_path('app/public'), config('filesystems.disks.public.root'));
        $this->assertSame('local', config('filesystems.disks.local.driver'));
        $this->assertFalse(config('filesystems.disks.local.serve'));
    }
}
