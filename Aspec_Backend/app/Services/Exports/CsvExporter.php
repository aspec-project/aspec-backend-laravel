<?php

namespace App\Services\Exports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gera ficheiros CSV prontos a abrir no Excel em português (UTF-8 com BOM, separador ";", CRLF),
 * com as células que começam por caracteres de fórmula neutralizadas.
 */
class CsvExporter
{
    private const BOM = "\xEF\xBB\xBF";

    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Devolve uma resposta de download em streaming com o CSV.
     *
     * @param  string  $filename  Nome do ficheiro (ASCII).
     * @param  list<string>  $header  Títulos das colunas.
     * @param  iterable<list<string|int|null>>  $rows  Linhas, pela ordem das colunas.
     */
    public function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(
            fn () => $this->write(fopen('php://output', 'w'), $header, $rows),
            $filename,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /**
     * Escreve o BOM, o cabeçalho e as linhas num recurso de escrita.
     *
     * @param  resource  $handle  Destino (php://output no download, php://memory nos testes).
     * @param  list<string>  $header  Títulos das colunas.
     * @param  iterable<list<string|int|null>>  $rows  Linhas, pela ordem das colunas.
     */
    public function write($handle, array $header, iterable $rows): void
    {
        fwrite($handle, self::BOM);

        $this->writeLine($handle, $header);

        foreach ($rows as $row) {
            $this->writeLine($handle, $row);
        }
    }

    /**
     * Converte um valor numa célula segura: null passa a texto vazio e um valor que comece
     * por =, +, -, @, TAB ou CR ganha um apóstrofo à frente para o Excel não o tratar como fórmula.
     *
     * @param  string|int|null  $value  Valor original.
     */
    public function sanitize(string|int|null $value): string
    {
        $cell = (string) $value;

        if ($cell !== '' && in_array($cell[0], self::FORMULA_PREFIXES, true)) {
            return "'".$cell;
        }

        return $cell;
    }

    /**
     * Escreve uma linha com as células neutralizadas, separadas por ";" e terminada em CRLF.
     *
     * @param  resource  $handle  Destino da escrita.
     * @param  list<string|int|null>  $cells  Células da linha.
     */
    private function writeLine($handle, array $cells): void
    {
        $line = array_map(fn ($cell) => $this->quote($this->sanitize($cell)), $cells);

        fwrite($handle, implode(';', $line)."\r\n");
    }

    /**
     * Põe a célula entre aspas só quando contém ";", aspas ou mudanças de linha, duplicando as aspas internas (RFC 4180).
     * Não se usa o fputcsv porque também põe aspas em células com espaços; a barra "\" não tem significado especial.
     *
     * @param  string  $cell  Célula já neutralizada.
     */
    private function quote(string $cell): string
    {
        if (strpbrk($cell, ";\"\n\r") === false) {
            return $cell;
        }

        return '"'.str_replace('"', '""', $cell).'"';
    }
}
