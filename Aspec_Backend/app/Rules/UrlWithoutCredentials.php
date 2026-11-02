<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Recusa links com utilizador ou password antes do "@" (ex. "https://linkedin.com@site.pt"). O browser
 * abre o domínio depois do "@", por isso o link mostraria um site e levaria a outro. Usada no website
 * e nas redes sociais do perfil, que outros membros abrem.
 */
class UrlWithoutCredentials implements ValidationRule
{
    /**
     * Falha se o URL tiver a parte de utilizador ou de password. Valores que não são texto ou que
     * não são URL ficam para a regra 'url'.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $parts = parse_url($value);

        if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']))) {
            $fail('validation.url_without_credentials')->translate();
        }
    }
}
