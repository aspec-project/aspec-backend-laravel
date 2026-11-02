<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Str;

/**
 * Limpa moradas escritas à mão antes de as pesquisar no mapa (tira andar, lado, lote, loja…)
 * e prepara a lista de pesquisas alternativas, da mais precisa para a mais larga.
 */
final class AddressCleaner
{
    /**
     * Palavras que indicam um tipo de via (sem acentos e em minúsculas).
     *
     * @var list<string>
     */
    public const STREET_TYPES = [
        'rua', 'r', 'avenida', 'av', 'travessa', 'tv', 'largo', 'lg', 'praca', 'praceta', 'estrada',
        'alameda', 'beco', 'calcada', 'rotunda', 'caminho', 'azinhaga', 'rampa', 'viela', 'cais',
        'escadas', 'escadinhas', 'patio', 'urbanizacao', 'urb', 'bairro', 'quinta', 'parque',
    ];

    /**
     * Expressões que se tiram da morada, pela ordem em que são aplicadas (R1 a R6).
     *
     * @var list<string>
     */
    private const PATTERNS = [
        '/Regi[aã]o\s+Aut[oó]noma\s+(?:dos|da|de)\s+/iu',
        '/(?<![\p{L}\p{N}])(?:n\.?\s?[º°o]|n[uú]m(?:ero)?\.?)\s*\.?\s*(?=\d)/iu',
        '/(?<![\p{L}\p{N}])(?<!d[aeo]\s)(?<!d[ao]s\s)(?:r\s?\/\s?ch?|r[eé]s[\s-]+do[\s-]+ch[aã]o|sub-?cave|cave)(?:\s*(?:esq(?:uerdo|\.)?|dt[oa]?\.?|dir(?:eito|\.)?|frente|fte\.?|tr[aá]s))?(?![\p{L}\p{N}])/iu',
        '/(?<![\p{L}\p{N}])\d{1,2}\s?\.?\s?[º°](?!\s*(?:de|travessa|bairro|beco|rua)(?![\p{L}\p{N}]))(?:\s*andar)?(?:\s*(?:esq(?:uerdo|\.)?|dt[oa]?\.?|dir(?:eito|\.)?|frente|fte\.?|tr[aá]s|[a-h](?![\p{L}\p{N}])))?\.?/iu',
        '/(?:(?<=,)|^)\s*\d{1,2}\s*(?:esq(?:uerdo)?\.?|dto\.?|dta\.?|dt\.|dir(?:eito)?\.?)(?=\s*(?:,|$))/iu',
        '/(?<![\p{L}\p{N}])(?:esq\.?|dto\.?|dta\.?|dt\.|dir\.)(?![\p{L}\p{N}])/iu',
        '/(?<![\p{L}\p{N}])(?<!d[aeo]\s)(?<!d[ao]s\s)(?:lote|loja|bloco|fra(?:cç|ç|cc|c)[aã]o|piso|apartamento|apart\.|apt\.?)\s*(?:\d{1,4}[a-z]?|[a-z])(?![\p{L}\p{N}])/iu',
    ];

    /**
     * Segmentos que não são localidade (comparados sem acentos e em minúsculas).
     *
     * @var list<string>
     */
    private const NOT_LOCALITIES = ['acores', 'madeira', 'portugal'];

    /**
     * Tira da morada os marcadores que o mapa não conhece (nº, andar e lado, r/c, cave, lote, loja,
     * bloco, fração, piso, apartamento e "Região Autónoma dos/da") e arruma os segmentos.
     *
     * @param  string  $address  Morada tal como foi escrita.
     * @return string Morada limpa, com os segmentos separados por ", " (vazia se só tinha marcadores).
     */
    public function clean(string $address): string
    {
        $address = Str::squish($address);

        foreach (self::PATTERNS as $pattern) {
            $address = (string) preg_replace($pattern, '', $address);
        }

        $segments = [];

        foreach (explode(',', $address) as $segment) {
            $segment = trim(Str::squish($segment), ' -/.');

            if ($segment !== '' && $this->normalize($segment) !== 'portugal') {
                $segments[] = $segment;
            }
        }

        return implode(', ', $segments);
    }

    /**
     * Rua de uma morada limpa: o primeiro segmento com um tipo de via (ou o primeiro segmento),
     * com o número de porta do segmento seguinte quando a rua não tem número.
     *
     * @param  string  $cleaned  Morada já limpa por clean().
     * @return string|null Rua (ex.: "Avenida da Boavista 1277"), ou null se a morada estiver vazia.
     */
    public function street(string $cleaned): ?string
    {
        $segments = $this->segments($cleaned);

        if ($segments === []) {
            return null;
        }

        $index = $this->streetIndex($segments);
        $street = $segments[$index];
        $next = $segments[$index + 1] ?? null;

        if (! preg_match('/\d/', $street) && $next !== null && preg_match('/^\d{1,5}[a-z]?$/i', $next)) {
            $street .= ' '.$next;
        }

        return $street;
    }

    /**
     * Pesquisas a fazer por ordem, sem repetidas: morada limpa + distrito, morada limpa sem distrito
     * e rua + localidade.
     *
     * @param  string  $address  Morada tal como foi escrita.
     * @param  string|null  $district  Nome do distrito/região, ou null.
     * @return list<string> No máximo 3 pesquisas (vazia se a morada só tinha marcadores).
     */
    public function attempts(string $address, ?string $district): array
    {
        $cleaned = $this->clean($address);

        if ($cleaned === '') {
            return [];
        }

        $attempts = [];

        if ($district !== null) {
            $attempts[] = $cleaned.', '.$district;
        }

        $attempts[] = $cleaned;

        $street = $this->street($cleaned);
        $locality = $this->locality($cleaned);

        if ($street !== null && $locality !== null) {
            $attempts[] = $street.', '.$locality;
        }

        return array_values(array_unique($attempts));
    }

    /**
     * Localidade de uma morada limpa: o último segmento depois da rua que, sem o código postal,
     * não fica vazio, não é só um número e não é "Açores", "Madeira" nem "Portugal".
     *
     * @param  string  $cleaned  Morada já limpa por clean().
     * @return string|null Localidade (ex.: "Lisboa"), ou null se não houver.
     */
    private function locality(string $cleaned): ?string
    {
        $segments = $this->segments($cleaned);

        if ($segments === []) {
            return null;
        }

        $locality = null;

        foreach (array_slice($segments, $this->streetIndex($segments) + 1) as $segment) {
            $candidate = trim((string) preg_replace('/^\d{4}-\d{3}\s*/', '', $segment));

            if ($candidate !== '' && ! preg_match('/^\d+$/', $candidate)
                && ! in_array($this->normalize($candidate), self::NOT_LOCALITIES, true)) {
                $locality = $candidate;
            }
        }

        return $locality;
    }

    /**
     * Posição do segmento da rua: o primeiro com uma palavra de tipo de via, ou o primeiro.
     *
     * @param  list<string>  $segments  Segmentos da morada limpa (pelo menos um).
     * @return int Índice do segmento.
     */
    private function streetIndex(array $segments): int
    {
        foreach ($segments as $index => $segment) {
            $words = preg_split('/[^a-z0-9]+/', $this->normalize($segment), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (array_intersect($words, self::STREET_TYPES) !== []) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * Divide uma morada limpa nos seus segmentos.
     *
     * @param  string  $cleaned  Morada já limpa por clean().
     * @return list<string> Segmentos sem os vazios.
     */
    private function segments(string $cleaned): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $cleaned)), fn (string $s) => $s !== ''));
    }

    /**
     * Texto sem acentos e em minúsculas, para comparar palavras.
     *
     * @param  string  $text  Texto original.
     * @return string Texto normalizado.
     */
    private function normalize(string $text): string
    {
        return Str::lower(Str::ascii($text));
    }
}
