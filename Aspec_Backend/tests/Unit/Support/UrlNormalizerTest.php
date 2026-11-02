<?php

namespace Tests\Unit\Support;

use App\Support\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Links escritos sem esquema (www.empresa.pt) ganham https://; tudo o resto fica igual para a
 * regra url:http,https decidir. Um esquema que não seja http/https nunca é escondido atrás de
 * https://, senão "https://mailto:a@b.pt" passaria na validação.
 */
class UrlNormalizerTest extends TestCase
{
    public static function linksWithoutScheme(): array
    {
        return [
            'www' => ['www.empresa.pt', 'https://www.empresa.pt'],
            'domínio' => ['empresa.pt', 'https://empresa.pt'],
            'com caminho' => ['linkedin.com/in/x', 'https://linkedin.com/in/x'],
            'com porta' => ['empresa.pt:8080/x', 'https://empresa.pt:8080/x'],
            'espaços à volta' => ['  www.x.pt ', 'https://www.x.pt'],
        ];
    }

    #[Test]
    #[DataProvider('linksWithoutScheme')]
    public function link_without_scheme_gets_https(string $input, string $expected): void
    {
        $this->assertSame($expected, UrlNormalizer::withScheme($input));
    }

    public static function linksWithHttpScheme(): array
    {
        return [
            'https' => ['https://x.pt'],
            'http' => ['http://x.pt'],
            'maiúsculas' => ['HTTP://X.PT'],
            'misto' => ['HtTpS://x.pt/a'],
        ];
    }

    #[Test]
    #[DataProvider('linksWithHttpScheme')]
    public function link_with_http_or_https_is_kept(string $input): void
    {
        $this->assertSame($input, UrlNormalizer::withScheme($input));
    }

    public static function otherSchemes(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript com maiúsculas' => ['JavaScript:alert(1)'],
            'mailto' => ['mailto:a@b.pt'],
            'ftp' => ['ftp://x.pt'],
            'data' => ['data:text/html,x'],
            'relativo ao protocolo' => ['//x.pt'],
        ];
    }

    #[Test]
    #[DataProvider('otherSchemes')]
    public function link_with_another_scheme_is_never_prefixed(string $input): void
    {
        $this->assertSame($input, UrlNormalizer::withScheme($input));
    }

    public static function emptyOrNonStringValues(): array
    {
        return [
            'vazio' => [''],
            'null' => [null],
            'array' => [['www.x.pt']],
            'inteiro' => [123],
        ];
    }

    #[Test]
    #[DataProvider('emptyOrNonStringValues')]
    public function empty_or_non_string_value_is_returned_unchanged(mixed $input): void
    {
        $this->assertSame($input, UrlNormalizer::withScheme($input));
    }

    #[Test]
    public function only_spaces_never_becomes_a_link(): void
    {
        $this->assertStringStartsNotWith('https://', (string) UrlNormalizer::withScheme('   '));
    }
}
