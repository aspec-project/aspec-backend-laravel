<?php

namespace Tests\Unit\Rules;

use App\Rules\PortuguesePostalCode;
use Database\Factories\MemberProfileFactory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\PotentiallyTranslatedString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PostalCodeMessage;
use Tests\TestCase;

/**
 * Código postal português (NNNN-NNN, primeiro dígito de 1 a 9), usado no perfil de membro e na faturação da ativação.
 * Usa o Tests\TestCase do Laravel porque o Validator e as traduções precisam da aplicação.
 */
class PortuguesePostalCodeTest extends TestCase
{
    use PostalCodeMessage;

    /**
     * Chama a regra diretamente: o Validator não corre regras próprias em valores vazios,
     * e o TrimStrings dos pedidos HTTP esconderia o "\n" final.
     */
    private function passes(mixed $postalCode): bool
    {
        $failed = false;

        (new PortuguesePostalCode)->validate('postal_code', $postalCode, function (string $message) use (&$failed) {
            $failed = true;

            return new PotentiallyTranslatedString($message, app('translator'));
        });

        return ! $failed;
    }

    public static function validPostalCodes(): array
    {
        return [
            'Lisboa' => ['1000-100'],
            'Madeira' => ['9500-321'],
            'primeiro' => ['1000-000'],
            'último' => ['9999-999'],
        ];
    }

    public static function invalidPostalCodes(): array
    {
        return [
            'só 4 dígitos' => ['1000'],
            'sufixo com 2 dígitos' => ['1000-10'],
            'prefixo com 5 dígitos' => ['10001-00'],
            'letras' => ['ABCD-EFG'],
            'zeros' => ['0000-000'],
            'começa por 0' => ['0999-999'],
            'newline no fim' => ["1000-100\n"],
            'espaço antes' => [' 1000-100'],
            'sem hífen' => ['1000100'],
            'espaço em vez de hífen' => ['1000 100'],
            'vazio' => [''],
            'inteiro' => [1000100],
            'array' => [['1000-100']],
            'null' => [null],
        ];
    }

    #[Test]
    #[DataProvider('validPostalCodes')]
    public function accepts_portuguese_postal_codes(string $postalCode): void
    {
        $this->assertTrue($this->passes($postalCode));
    }

    #[Test]
    #[DataProvider('invalidPostalCodes')]
    public function rejects_anything_that_is_not_a_portuguese_postal_code(mixed $postalCode): void
    {
        $this->assertFalse($this->passes($postalCode));
    }

    #[Test]
    public function error_message_is_in_portuguese(): void
    {
        $validator = Validator::make(['postal_code' => '1000'], ['postal_code' => [new PortuguesePostalCode]]);

        $this->assertSame(self::POSTAL_CODE_MESSAGE, $validator->errors()->first('postal_code'));
    }

    /**
     * Os testes reenviam dados da factory nos pedidos; um código a começar por 0 faria-os falhar ao acaso.
     */
    #[Test]
    public function member_profile_factory_only_generates_valid_postal_codes(): void
    {
        $factory = new MemberProfileFactory;

        for ($i = 0; $i < 300; $i++) {
            $postalCode = $factory->definition()['postal_code'];

            $this->assertTrue($this->passes($postalCode), "Código postal inválido gerado pela factory: {$postalCode}");
        }
    }

    public static function normalizedPostalCodes(): array
    {
        return [
            'espaços à volta' => [' 1000-100 ', '1000-100'],
            'sete dígitos seguidos' => ['1000100', '1000-100'],
            'sete dígitos com espaços à volta' => [' 1000100 ', '1000-100'],
            'espaço em vez de hífen' => ['1000 100', '1000-100'],
            'newline no fim' => ["1000-100\n", '1000-100'],
            'já normalizado' => ['9500-321', '9500-321'],
        ];
    }

    #[Test]
    #[DataProvider('normalizedPostalCodes')]
    public function normalize_returns_the_postal_code_with_hyphen(string $input, string $expected): void
    {
        $this->assertSame($expected, PortuguesePostalCode::normalize($input));
        $this->assertTrue($this->passes(PortuguesePostalCode::normalize($input)));
    }

    public static function valuesLeftForTheValidator(): array
    {
        return [
            'inválido curto' => ['1000'],
            'oito dígitos' => ['10001000'],
            'letras' => ['ABCDEFG'],
            'sete zeros' => ['0000000'],
        ];
    }

    #[Test]
    #[DataProvider('valuesLeftForTheValidator')]
    public function normalize_never_turns_an_invalid_code_into_a_valid_one(string $input): void
    {
        $this->assertFalse($this->passes(PortuguesePostalCode::normalize($input)));
    }

    public static function nonStringValues(): array
    {
        return [
            'null' => [null],
            'inteiro' => [1000100],
            'array' => [['1000100']],
        ];
    }

    #[Test]
    #[DataProvider('nonStringValues')]
    public function normalize_returns_non_strings_unchanged(mixed $value): void
    {
        $this->assertSame($value, PortuguesePostalCode::normalize($value));
    }
}
