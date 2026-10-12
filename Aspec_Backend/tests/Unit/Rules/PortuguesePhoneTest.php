<?php

namespace Tests\Unit\Rules;

use App\Rules\PortuguesePhone;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Usa o Tests\TestCase do Laravel (e não o do PHPUnit) porque o Validator e as traduções precisam da aplicação.
class PortuguesePhoneTest extends TestCase
{
    private function passes(mixed $phone): bool
    {
        return Validator::make(['phone' => $phone], ['phone' => [new PortuguesePhone]])->passes();
    }

    public static function validPhones(): array
    {
        return [
            'telemóvel 91' => ['912345678'],
            'telemóvel 92' => ['921234567'],
            'telemóvel 93' => ['931234567'],
            'telemóvel 96' => ['961234567'],
            'com indicativo' => ['+351912345678'],
            'fixo Lisboa' => ['212345678'],
            'fixo Porto' => ['223456789'],
            'nómada 30' => ['301234567'],
            'com espaços' => ['912 345 678'],
            'indicativo e espaços' => ['+351 912 345 678'],
            'hífenes e parênteses' => ['(+351) 912-345-678'],
        ];
    }

    public static function invalidPhones(): array
    {
        return [
            'prefixo 94' => ['941234567'],
            'prefixo 95' => ['951234567'],
            'prefixo 31' => ['311234567'],
            'curto' => ['12345'],
            'dígitos a mais' => ['9123456789'],
            'estrangeiro' => ['+447911123456'],
            'indicativo sem +' => ['351912345678'],
            'letras' => ['91234567a'],
            'número inteiro' => [912345678],
        ];
        // Sem caso "vazio": o Laravel não corre regras próprias em valores vazios; isso é papel do 'required'.
    }

    #[Test]
    #[DataProvider('validPhones')]
    public function accepts_valid_portuguese_phones(string $phone): void
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
    public function error_message_is_in_portuguese(): void
    {
        $validator = Validator::make(['phone' => '941234567'], ['phone' => [new PortuguesePhone]]);

        $this->assertSame(
            'O campo telefone tem de ser um número de telefone português válido.',
            $validator->errors()->first('phone')
        );
    }

    #[Test]
    public function normalize_keeps_only_the_nine_digits(): void
    {
        $this->assertSame('912345678', PortuguesePhone::normalize('+351 912 345 678'));
        $this->assertSame('912345678', PortuguesePhone::normalize('(+351) 912-345-678'));
        $this->assertSame('212345678', PortuguesePhone::normalize('212345678'));
    }

    #[Test]
    public function normalize_leaves_non_strings_untouched(): void
    {
        $this->assertNull(PortuguesePhone::normalize(null));
        $this->assertSame(912345678, PortuguesePhone::normalize(912345678));
    }
}
