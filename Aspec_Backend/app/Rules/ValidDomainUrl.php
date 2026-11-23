<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Exige que o host de um link seja um domínio verdadeiro (ex. empresa.pt).
 *
 * Recusa hosts só com números (https://12), sem domínio de topo (https://empresa), endereços IP
 * e domínios de topo numéricos. Complementa url:http,https, que aceita estes casos.
 * Domínios com acentos (ex. café.pt) também são recusados, como nos emails da conta (ASPEC-456).
 */
class ValidDomainUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return; // As regras required/url tratam tipos e formatos inválidos.
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host)) {
            return; // A regra url:http,https trata URLs sem host.
        }

        $host = rtrim($host, '.');
        $labels = explode('.', $host);
        $tld = $labels[count($labels) - 1] ?? '';

        $hasValidDomain = count($labels) >= 2
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})$/i', $tld) === 1;

        if (! $hasValidDomain) {
            $fail('validation.url')->translate();
        }
    }
}
