<?php

namespace Tests\Unit\Rules;

use App\Rules\InternationalPhone;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Telefone de qualquer país, guardado em E.164. Sem indicativo, o número é interpretado como português.
 * Usa o Tests\TestCase do Laravel porque o Validator e as traduções precisam da aplicação.
 */
class InternationalPhoneTest extends TestCase
{
    private function passes(mixed $phone): bool
    {
        return Validator::make(['phone' => $phone], ['phone' => [new InternationalPhone]])->passes();
    }

    /**
     * Chama a regra diretamente: o Validator não corre regras próprias em valores vazios.
     */
    private function failsDirectly(mixed $phone): bool
    {
        $failed = false;

        (new InternationalPhone)->validate('phone', $phone, function () use (&$failed) {
            $failed = true;

            return new class
            {
                public function translate(): void {}
            };
        });

        return $failed;
    }

    /**
     * Entrada aceite => valor E.164 que deve ser guardado.
     */
    public static function validPhones(): array
    {
        return [
            'português sem indicativo' => ['912345678', '+351912345678'],
            'português com indicativo e espaços' => ['+351 912 345 678', '+351912345678'],
            'parênteses e hífenes' => ['(+351) 912-345-678', '+351912345678'],
            'prefixo 00' => ['00351912345678', '+351912345678'],
            'fixo Lisboa' => ['212345678', '+351212345678'],
            'E.164 português' => ['+351912345678', '+351912345678'],
            'Reino Unido com espaços' => ['+44 7911 123456', '+447911123456'],
            'Reino Unido E.164' => ['+447911123456', '+447911123456'],
            'Reino Unido com 00' => ['0044 7911 123456', '+447911123456'],
            'França' => ['+33 6 12 34 56 78', '+33612345678'],
            'Brasil com hífen' => ['+55 11 91234-5678', '+5511912345678'],
            'pontos como separador' => ['912.345.678', '+351912345678'],
        ];
    }

    public static function invalidPhones(): array
    {
        return [
            'prefixo PT não atribuído' => ['941234567'],
            'Reino Unido sem indicativo' => ['7911123456'],
            'curto' => ['12345'],
            'estrangeiro curto' => ['+44 123'],
            'português com 8 dígitos' => ['+351 91234567'],
            'português com dígitos a mais' => ['+3519123456789'],
            'indicativo inexistente' => ['+999 123456789'],
            'letras' => ['abc'],
            'HTML antes do número' => ['"><img src=x onerror=alert(1)> 912345678'],
            'HTML antes de número estrangeiro' => ['<svg onload=confirm(1)>+44 7911 123456'],
            'fórmula antes do número' => ['=HYPERLINK("x")912345678'],
            'hífen inicial com texto' => ['-cmd 912345678'],
            'prefixo de texto' => ['Tel: 912345678'],
            '+ no meio' => ['912+345678'],
            'byte NUL no fim' => ["912345678\0"],
            'dígitos fullwidth' => ['９１２３４５６７８'],
            'extensão' => ['912345678 ext. 1234'],
            'letras de vanity number' => ['+1 800 FLOWERS'],
            'número inteiro' => [912345678],
            'array' => [['912345678']],
        ];
    }

    #[Test]
    #[DataProvider('validPhones')]
    public function accepts_valid_phones(string $phone, string $e164): void
    {
        $this->assertTrue($this->passes($phone));
    }

    #[Test]
    #[DataProvider('invalidPhones')]
    public function rejects_invalid_phones(mixed $phone): void
    {
        $this->assertFalse($this->passes($phone));
    }

    #[Test]
    public function rejects_empty_string_when_called_directly(): void
    {
        $this->assertTrue($this->failsDirectly(''));
    }

    #[Test]
    public function very_long_text_is_rejected_without_exception(): void
    {
        $phone = '+351'.str_repeat('9', 296);

        $this->assertFalse($this->passes($phone));
        $this->assertTrue($this->failsDirectly($phone));
        $this->assertIsString(InternationalPhone::normalize($phone));
    }

    #[Test]
    #[DataProvider('validPhones')]
    public function normalize_returns_e164(string $phone, string $e164): void
    {
        $this->assertSame($e164, InternationalPhone::normalize($phone));
    }

    #[Test]
    #[DataProvider('validPhones')]
    public function every_valid_phone_normalizes_to_a_valid_e164_value(string $phone, string $e164): void
    {
        $normalized = InternationalPhone::normalize($phone);

        $this->assertMatchesRegularExpression('/^\+[1-9]\d{7,14}$/', $normalized);
        $this->assertTrue($this->passes($normalized));
        $this->assertSame($normalized, InternationalPhone::normalize($normalized));
    }

    #[Test]
    public function normalize_strips_separators_from_invalid_phones(): void
    {
        $this->assertSame('941234567', InternationalPhone::normalize('941 234-567'));
        $this->assertSame('7911123456', InternationalPhone::normalize('(7911) 123.456'));
        $this->assertSame('+44123', InternationalPhone::normalize('+44 123'));
    }

    #[Test]
    public function normalize_never_converts_text_around_a_number_to_e164(): void
    {
        $this->assertSame('Tel:912345678', InternationalPhone::normalize('Tel: 912345678'));
        $this->assertSame('912345678ext1234', InternationalPhone::normalize('912345678 ext. 1234'));
    }

    #[Test]
    public function normalize_leaves_non_strings_untouched(): void
    {
        $this->assertNull(InternationalPhone::normalize(null));
        $this->assertSame(912345678, InternationalPhone::normalize(912345678));
        $this->assertSame(['912345678'], InternationalPhone::normalize(['912345678']));
    }

    #[Test]
    public function error_message_is_in_portuguese_and_asks_for_the_country_code(): void
    {
        $validator = Validator::make(['phone' => '7911123456'], ['phone' => [new InternationalPhone]]);

        $this->assertSame(
            'O campo telefone tem de ser um número de telefone válido. Números estrangeiros precisam do indicativo do país (ex.: +44).',
            $validator->errors()->first('phone')
        );
    }

    #[Test]
    public function english_message_exists(): void
    {
        $this->assertTrue(Lang::hasForLocale('validation.international_phone', 'en'));
    }
}
