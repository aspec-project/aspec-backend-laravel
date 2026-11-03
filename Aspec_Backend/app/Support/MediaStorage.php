<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Disco das imagens dos membros (logótipos e portefólio), escolhido em filesystems.media_disk
 * (MEDIA_DISK no .env). Controllers, Resources e seeders pedem o disco e os URLs aqui, por isso
 * trocar o disco local pelo Cloudflare R2 (ou passar a URLs assinados) não mexe em mais código.
 */
final class MediaStorage
{
    /**
     * Disco de media configurado.
     */
    public static function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.media_disk'));
    }

    /**
     * URL público de um ficheiro do disco de media, ou null se não houver caminho.
     *
     * Recusa um disco S3 sem url configurado: o adapter devolvia o endpoint da conta
     * (com o account id e o bucket), que não abre e expõe a configuração do armazenamento.
     *
     * @throws RuntimeException Se o disco de media for S3 e não tiver url público.
     */
    public static function url(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $disk = self::disk();

        if ($disk instanceof AwsS3V3Adapter && blank($disk->getConfig()['url'] ?? null)) {
            throw new RuntimeException('O disco de media S3 não tem URL público configurado.');
        }

        return $disk->url($path);
    }
}
