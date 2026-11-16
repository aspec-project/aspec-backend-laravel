<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

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