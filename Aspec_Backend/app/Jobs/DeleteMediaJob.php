<?php

namespace App\Jobs;

use App\Support\MediaStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Apaga um ficheiro ou uma pasta do disco das imagens (logótipos e portefólio), com novas tentativas.
 * Com o Cloudflare R2 o pedido pode falhar (rede, erro do servidor, chave revogada) e o disco devolve
 * false sem lançar; sem novas tentativas, a imagem de um membro anonimizado ficava pública.
 */
class DeleteMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @param  string  $path  Caminho no disco das imagens (gerado pelo servidor, nunca pelo cliente).
     * @param  bool  $directory  true para apagar a pasta inteira (ex.: logos/{user_id}).
     */
    public function __construct(public string $path, public bool $directory = false) {}

    /**
     * Esperas crescentes entre tentativas (segundos): 1 min, 5 min, 15 min, 1 h.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    /**
     * Idempotente: apagar um caminho que já não existe conta como sucesso.
     *
     * @throws RuntimeException Se o disco não conseguir apagar, para a fila voltar a tentar.
     */
    public function handle(): void
    {
        $disk = MediaStorage::disk();
        $deleted = $this->directory ? $disk->deleteDirectory($this->path) : $disk->delete($this->path);

        if (! $deleted) {
            throw new RuntimeException('Não foi possível apagar o ficheiro do disco das imagens.');
        }
    }

    /**
     * Esgotadas as tentativas, regista o caminho para um admin o apagar à mão no armazenamento.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Não foi possível apagar uma imagem de um membro; apagar manualmente no armazenamento.', [
            'path' => $this->path,
            'directory' => $this->directory,
        ]);
    }
}
