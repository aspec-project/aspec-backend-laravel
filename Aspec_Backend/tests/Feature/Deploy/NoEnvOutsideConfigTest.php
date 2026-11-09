<?php

namespace Tests\Feature\Deploy;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No deploy corre php artisan optimize (config:cache). Com a configuração em cache, env() fora de
 * config/ devolve null, por isso o código da aplicação só pode ler variáveis através de config().
 *
 * Analisa os tokens PHP (e não o texto), para ignorar comentários, strings como ".env" e métodos
 * com o mesmo nome (->env(), ::env()).
 */
class NoEnvOutsideConfigTest extends TestCase
{
    #[Test]
    public function application_code_does_not_call_env_directly(): void
    {
        $offenders = [];

        foreach (['app', 'routes', 'bootstrap'] as $directory) {
            foreach (File::allFiles(base_path($directory)) as $file) {
                if ($file->getExtension() === 'php' && $this->callsEnv($file->getContents())) {
                    $offenders[] = $directory.'/'.str_replace('\\', '/', $file->getRelativePathname());
                }
            }
        }

        $this->assertSame([], $offenders, 'Usar config() em vez de env(): '.implode(', ', $offenders));
    }

    private function callsEnv(string $code): bool
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            fn ($token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            if (strtolower(ltrim($token[1], '\\')) !== 'env' || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;

            if (! is_array($previous) || ! in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                return true;
            }
        }

        return false;
    }
}
