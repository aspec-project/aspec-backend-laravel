<?php

namespace Tests\Unit\Rules;

use App\Rules\PortugueseNif;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\PotentiallyTranslatedString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Usa o Tests\TestCase do Laravel porque o Validator e as traduções precisam da aplicação.
class PortugueseNifTest extends TestCase
{
    /**
     * Chama a regra diretamente: o Validator não corre regras próprias em valores vazios,
     * e aqui quer-se provar que a própria regra recusa ''.
     */
    private function passes(mixed $nif): bool
    {
        $failed = false;

        (new PortugueseNif)->validate('nif', $nif, function (string $message) use (&$failed) {
            $failed = true;

            return new PotentiallyTranslatedString($message, app('translator'));
        });

        return ! $failed;
    }

    public static function validNifs(): array
    {
        return [
            'pessoa singular' => ['123456789'],
            'empresa' => ['509123457'],
            'resto 1 dá controlo 0' => ['500000000'],
        ];
    }

    public static function invalidNifs(): array
    {
        return [
            'dígito de controlo errado' => ['123456780'],
            'outro controlo errado' => ['509123450'],
            '8 dígitos' => ['12345678'],
            '10 dígitos' => ['1234567890'],
            'letras' => ['12345678a'],
            'só letras' => ['abcdefghi'],
            'vazio' => [''],
            'inteiro' => [123456789],
        ];
    }

    #[Test]
    #[DataProvider('validNifs')]
    public function accepts_valid_portuguese_nifs(string $nif): void
    {
        $this->assertTrue($this->passes($nif));
    }

    #[Test]
    #[DataProvider('invalidNifs')]
    public function rejects_invalid_nifs(mixed $nif): void
    {
        $this->assertFalse($this->passes($nif));
    }

    #[Test]
    public function error_message_is_in_portuguese(): void
    {
        $validator = Validator::make(['nif' => '123456780'], ['nif' => [new PortugueseNif]]);

        $this->assertSame(
            'O campo NIF tem de ser um NIF português válido.',
            $validator->errors()->first('nif')
        );
    }
}
