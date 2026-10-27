<?php

namespace App\Support;

/**
 * Normaliza links escritos sem esquema (ex. "www.empresa.pt"), que os membros escrevem muitas vezes
 * assim, para a regra url:http,https os aceitar.
 */
class UrlNormalizer
{
    /**
     * Acrescenta "https://" a um link sem esquema e devolve-o sem espaços à volta. Ficam como estão
     * (para a validação decidir): valores que não são texto ou vazios, links que já começam por
     * http:// ou https://, links começados por "//" e links com outro esquema (javascript:, mailto:,
     * ftp:, data:). Estes nunca levam "https://" à frente, senão "https://mailto:a@b.pt" passaria na
     * regra url. Um ":" seguido de dígito é uma porta ("empresa.pt:8080"), não um esquema.
     */
    public static function withScheme(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if ($trimmed === ''
            || preg_match('#^https?://#i', $trimmed)
            || str_starts_with($trimmed, '//')
            || preg_match('/^[a-z][a-z0-9+.\-]*:(?!\d)/i', $trimmed)) {
            return $value;
        }

        return 'https://'.$trimmed;
    }
}
