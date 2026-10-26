<?php

namespace Tests\Unit\Exports;

use App\Services\Exports\CsvExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CsvExporterTest extends TestCase
{
    private const BOM = "\xEF\xBB\xBF";

    private const HEADER = ['Nome', 'Empresa'];

    private const HEADER_LINE = "Nome;Empresa\r\n";

    private function write(array $header, iterable $rows): string
    {
        $handle = fopen('php://memory', 'w+');

        (new CsvExporter)->write($handle, $header, $rows);

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * Conteúdo escrito depois do BOM e da linha de cabeçalho.
     */
    private function rowsWritten(iterable $rows): string
    {
        $content = $this->write(self::HEADER, $rows);

        $this->assertStringStartsWith(self::BOM.self::HEADER_LINE, $content);

        return substr($content, strlen(self::BOM.self::HEADER_LINE));
    }

    #[Test]
    public function write_starts_with_the_utf8_bom_and_the_header_line(): void
    {
        $this->assertSame(self::BOM.self::HEADER_LINE, $this->write(self::HEADER, []));
    }

    #[Test]
    public function write_uses_semicolon_and_crlf_and_quotes_only_when_needed(): void
    {
        $rows = [
            ['Ana', 'Silva; Filhos'],
            ['Rui', 'Diz "olá"'],
            ['Eva', "Linha1\nLinha2"],
        ];

        $this->assertSame(
            'Ana;"Silva; Filhos"'."\r\n"
            .'Rui;"Diz ""olá"""'."\r\n"
            ."Eva;\"Linha1\nLinha2\"\r\n",
            $this->rowsWritten($rows)
        );
    }

    public static function formulaValues(): array
    {
        return [
            'igual' => ['=1+1'],
            'mais (telefone)' => ['+351912345678'],
            'menos' => ['-2+3'],
            'arroba' => ['@SUM(1+1)'],
            'tab' => ["\tTAB"],
            'cr' => ["\rCR"],
        ];
    }

    #[Test]
    #[DataProvider('formulaValues')]
    public function sanitize_prefixes_formula_characters_with_an_apostrophe(string $value): void
    {
        $this->assertSame("'".$value, (new CsvExporter)->sanitize($value));
    }

    public static function safeValues(): array
    {
        return [
            'acentos' => ['João', 'João'],
            'hífen no meio' => ['Ana-Maria', 'Ana-Maria'],
            'número em texto' => ['2026', '2026'],
            'vazio' => ['', ''],
            'null' => [null, ''],
            'inteiro' => [42, '42'],
        ];
    }

    #[Test]
    #[DataProvider('safeValues')]
    public function sanitize_keeps_safe_values_unchanged(string|int|null $value, string $expected): void
    {
        $this->assertSame($expected, (new CsvExporter)->sanitize($value));
    }

    #[Test]
    public function write_neutralises_formulas_inside_the_file(): void
    {
        $this->assertSame(
            '"\'=HYPERLINK(""http://evil.test"")";\'@SUM(1+1)'."\r\n",
            $this->rowsWritten([['=HYPERLINK("http://evil.test")', '@SUM(1+1)']])
        );
    }

    #[Test]
    public function write_does_not_treat_backslash_as_an_escape_character(): void
    {
        $this->assertSame('"a\""b"'."\r\n", $this->rowsWritten([['a\"b']]));
    }

    #[Test]
    public function write_keeps_accented_characters_in_utf8(): void
    {
        $this->assertSame("Conceição;Évora\r\n", $this->rowsWritten([['Conceição', 'Évora']]));
    }
}
