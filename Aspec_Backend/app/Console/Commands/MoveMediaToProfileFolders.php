<?php

namespace App\Console\Commands;

use App\Models\MemberProfile;
use App\Support\MediaStorage;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

/**
 * Passa os logótipos e o portefólio das pastas antigas por id do utilizador (logos/{user_id},
 * portfolios/{user_id}) para as pastas por id do perfil, no disco de media configurado. O id do perfil
 * já é público (/api/members/{id}); o do utilizador deixa assim de aparecer nos URLs das imagens.
 */
class MoveMediaToProfileFolders extends Command
{
    protected $signature = 'media:move-to-profile-folders
        {--dry-run : Mostra o que seria feito, sem mexer em ficheiros nem na base de dados}';

    protected $description = 'Move os logótipos e o portefólio das pastas por id do utilizador para as pastas por id do perfil.';

    /** @var array{moved: int, updated: int, missing: int, failed: int} */
    private array $counts = ['moved' => 0, 'updated' => 0, 'missing' => 0, 'failed' => 0];

    private Filesystem $disk;

    private bool $dryRun;

    /**
     * Percorre todos os perfis (também os apagados com soft delete) e trata cada caminho que ainda
     * começa pela pasta do utilizador; os que já estão na pasta do perfil ficam como estão (idempotente).
     * Por ficheiro, move primeiro e só depois atualiza a linha: no S3 o move é copiar + apagar, e nesta
     * ordem uma falha a meio deixa sempre um estado que a execução seguinte resolve.
     * No fim apaga as pastas do utilizador que ficaram vazias.
     *
     * @return int SUCCESS mesmo com ficheiros em falta (só avisa); FAILURE se o disco recusar algum move.
     */
    public function handle(): int
    {
        $this->disk = MediaStorage::disk();
        $this->dryRun = (bool) $this->option('dry-run');
        $userIds = [];

        foreach (MemberProfile::withTrashed()->select('id')->lazyById(100) as $row) {
            DB::transaction(function () use ($row, &$userIds) {
                // Relê e bloqueia o perfil, como o upload, o apagar do portefólio e a anonimização: nenhum
                // deles corre a meio deste perfil, e as linhas atualizadas são sempre as atuais.
                $profile = MemberProfile::withTrashed()->whereKey($row->id)->lockForUpdate()->with('portfolios')->first();

                // Anonimizado: as imagens já foram (ou vão ser) apagadas; mover recriava-as no bucket público.
                if (! $profile || ($profile->trashed() && $profile->logo_path === null)) {
                    return;
                }

                $this->moveProfileMedia($profile, $userIds);
            });
        }

        if (! $this->dryRun) {
            $this->deleteEmptyUserFolders(array_unique($userIds));
        }

        $this->info(($this->dryRun ? '[simulação] ' : '')
            ."Movidos: {$this->counts['moved']}; só atualizados: {$this->counts['updated']}; "
            ."em falta: {$this->counts['missing']}; com erro: {$this->counts['failed']}.");

        return $this->counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Move o logótipo e o portefólio de um perfil (já bloqueado) da pasta do utilizador para a do perfil.
     *
     * @param  array<int, string>  $userIds  Utilizadores tratados, para limpar no fim as pastas antigas vazias.
     */
    private function moveProfileMedia(MemberProfile $profile, array &$userIds): void
    {
        $logoFolder = "logos/{$profile->user_id}/";
        $portfolioFolder = "portfolios/{$profile->user_id}/";

        if (str_starts_with((string) $profile->logo_path, $logoFolder)) {
            $userIds[] = $profile->user_id;
            $target = $this->moveFile($profile->logo_path, "logos/{$profile->id}");

            if ($target !== null) {
                $profile->update(['logo_path' => $target]);
            }
        }

        foreach ($profile->portfolios as $image) {
            if (! str_starts_with($image->image_path, $portfolioFolder)) {
                continue;
            }

            $userIds[] = $profile->user_id;
            $target = $this->moveFile($image->image_path, "portfolios/{$profile->id}");

            if ($target !== null) {
                $image->update(['image_path' => $target]);
            }
        }
    }

    /**
     * Move um ficheiro para a pasta do perfil, mantendo só o nome do ficheiro do caminho guardado.
     *
     * @return string|null Caminho novo para gravar na linha; null se a linha deve ficar como está
     *                     (ficheiro em falta, move recusado pelo disco ou --dry-run).
     */
    private function moveFile(string $source, string $folder): ?string
    {
        $name = substr($source, strrpos($source, '/') + 1);
        $target = "{$folder}/{$name}";
        $prefix = $this->dryRun ? '[simulação] ' : '';

        // Só se lê e move uma origem no formato que a aplicação gera (pasta/uuid/nome, sem "..", "\" nem subpastas):
        // um valor escrito à mão na BD nunca leva o comando a mexer em ficheiros fora da pasta do utilizador.
        $nameIsValid = preg_match('#^[A-Za-z0-9._-]+$#', $name) === 1 && ! in_array($name, ['.', '..'], true);
        $sourceIsSafe = $nameIsValid && preg_match('#^(logos|portfolios)/[0-9a-f-]{36}/[A-Za-z0-9._-]+$#', $source) === 1;

        if ($sourceIsSafe && $this->disk->exists($source)) {
            // Nunca escreve por cima: com origem e destino presentes, não se sabe qual é o certo.
            if ($this->disk->exists($target)) {
                $this->counts['failed']++;
                $this->error("Destino já existe, nada movido: {$source} → {$target}");

                return null;
            }

            if (! $this->dryRun && ! $this->disk->move($source, $target)) {
                $this->counts['failed']++;
                $this->error("Erro ao mover: {$source}");

                return null;
            }

            $this->counts['moved']++;
            $this->line("{$prefix}Movido: {$source} → {$target}");

            return $this->dryRun ? null : $target;
        }

        if ($nameIsValid && $this->disk->exists($target)) {
            $this->counts['updated']++;
            $this->line("{$prefix}Só atualizado: {$source} → {$target}");

            return $this->dryRun ? null : $target;
        }

        $this->counts['missing']++;
        $this->warn("Em falta (linha mantida): {$source}");

        return null;
    }

    /**
     * Apaga logos/{user_id} e portfolios/{user_id} quando já não têm ficheiros. No S3 as pastas não
     * existem (são só prefixos), por isso não há nada a apagar.
     *
     * @param  array<int, string>  $userIds
     */
    private function deleteEmptyUserFolders(array $userIds): void
    {
        foreach ($userIds as $userId) {
            foreach (["logos/{$userId}", "portfolios/{$userId}"] as $folder) {
                if ($this->disk->directoryExists($folder) && $this->disk->allFiles($folder) === []) {
                    $this->disk->deleteDirectory($folder);
                }
            }
        }
    }
}
