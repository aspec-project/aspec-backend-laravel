<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Str;

/**
 * Verifica se o local devolvido pelo mapa corresponde à rua e ao distrito pedidos.
 * O que não bate certo é marcado como aproximado, para o admin confirmar.
 */
final class ResultVerifier
{
    /**
     * Códigos ISO 3166-2:PT dos distritos e regiões autónomas (chaves sem acentos e em minúsculas).
     *
     * @var array<string, string>
     */
    private const ISO_CODES = [
        'aveiro' => 'PT-01',
        'beja' => 'PT-02',
        'braga' => 'PT-03',
        'braganca' => 'PT-04',
        'castelo branco' => 'PT-05',
        'coimbra' => 'PT-06',
        'evora' => 'PT-07',
        'faro' => 'PT-08',
        'guarda' => 'PT-09',
        'leiria' => 'PT-10',
        'lisboa' => 'PT-11',
        'portalegre' => 'PT-12',
        'porto' => 'PT-13',
        'santarem' => 'PT-14',
        'setubal' => 'PT-15',
        'viana do castelo' => 'PT-16',
        'vila real' => 'PT-17',
        'viseu' => 'PT-18',
        'acores' => 'PT-20',
        'madeira' => 'PT-30',
    ];

    /**
     * Campos do bloco address onde o Nominatim indica o código ISO do distrito (continente e regiões autónomas).
     *
     * @var list<string>
     */
    private const ISO_FIELDS = ['ISO3166-2-lvl6', 'ISO3166-2-lvl4'];

    /**
     * Campos do bloco address onde pode vir o nome da rua, por ordem de preferência.
     *
     * @var list<string>
     */
    private const STREET_FIELDS = ['road', 'pedestrian', 'square', 'footway'];

    /**
     * Palavras de ligação que não contam na comparação das ruas.
     *
     * @var list<string>
     */
    private const LINK_WORDS = ['a', 'o', 'as', 'os', 'da', 'do', 'das', 'dos', 'de', 'e', 'd'];

    /**
     * Diz se o resultado é aproximado: distrito diferente (pelo código ISO) ou rua diferente.
     *
     * @param  array<string, mixed>  $details  Bloco address da resposta do Nominatim (addressdetails=1).
     * @param  string|null  $street  Rua pedida, ou null se não houver.
     * @param  string|null  $district  Nome do distrito/região pedido, ou null.
     * @return bool true se a rua ou o distrito não corresponderem ao pedido.
     */
    public function isApproximate(array $details, ?string $street, ?string $district): bool
    {
        return $this->districtDiffers($details, $district) || $this->streetDiffers($details, $street);
    }

    /**
     * O distrito pedido está no mapa ISO, o resultado traz códigos e nenhum é o esperado.
     *
     * @param  array<string, mixed>  $details  Bloco address da resposta.
     * @param  string|null  $district  Nome do distrito/região pedido.
     * @return bool true se o distrito não corresponder.
     */
    private function districtDiffers(array $details, ?string $district): bool
    {
        $expected = $district === null ? null : (self::ISO_CODES[$this->normalize(Str::squish($district))] ?? null);

        if ($expected === null) {
            return false;
        }

        $codes = [];

        foreach (self::ISO_FIELDS as $field) {
            if (is_string($details[$field] ?? null)) {
                $codes[] = $details[$field];
            }
        }

        return $codes !== [] && ! in_array($expected, $codes, true);
    }

    /**
     * A rua pedida tem palavras significativas e o resultado não tem rua ou não as contém todas.
     *
     * @param  array<string, mixed>  $details  Bloco address da resposta.
     * @param  string|null  $street  Rua pedida.
     * @return bool true se a rua não corresponder.
     */
    private function streetDiffers(array $details, ?string $street): bool
    {
        $wanted = $street === null ? [] : $this->significantWords($street);

        if ($wanted === []) {
            return false;
        }

        foreach (self::STREET_FIELDS as $field) {
            if (is_string($details[$field] ?? null) && trim($details[$field]) !== '') {
                return array_diff($wanted, $this->significantWords($details[$field])) !== [];
            }
        }

        return true;
    }

    /**
     * Palavras que identificam uma rua: sem abreviaturas com ponto, palavras de ligação, tipos de via e números.
     *
     * @param  string  $street  Nome da rua.
     * @return list<string> Palavras sem acentos e em minúsculas.
     */
    private function significantWords(string $street): array
    {
        $text = (string) preg_replace('/(?<![a-z0-9])[a-z]+\./', ' ', $this->normalize($street));
        $words = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, fn (string $word) => ! in_array($word, self::LINK_WORDS, true)
            && ! in_array($word, AddressCleaner::STREET_TYPES, true)
            && ! preg_match('/^\d+[a-z]?$/', $word)));
    }

    /**
     * Texto sem acentos e em minúsculas, para comparar palavras e nomes de distritos.
     *
     * @param  string  $text  Texto original.
     * @return string Texto normalizado.
     */
    private function normalize(string $text): string
    {
        return Str::lower(Str::ascii($text));
    }
}
