<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Código postal português no formato NNNN-NNN, primeiro dígito de 1 a 9 (ex. "1050-196"): os
 * códigos vão de 1000 a 9999. Não verifica se o código existe. Usada no perfil de membro e nos
 * dados de faturação da ativação. Usa \z e não $ porque $ aceitaria um "\n" no fim.
 */
class PortuguesePostalCode implements ValidationRule
{
    /**
     * Tira os espaços à volta e converte 7 dígitos seguidos ou separados por um espaço
     * ("1000100", "1000 100") para "1000-100". Qualquer outro valor fica como está, para a
     * regra o rejeitar; valores que não são texto também (a regra 'string' rejeita-os).
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if (preg_match('/^(\d{4}) ?(\d{3})\z/', $value, $matches)) {
            return "{$matches[1]}-{$matches[2]}";
        }

        return $value;
    }

    /**
     * Falha se o valor não for texto exatamente no formato NNNN-NNN com o primeiro dígito de 1 a 9.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[1-9]\d{3}-\d{3}\z/', $value)) {
            $fail('validation.portuguese_postal_code')->translate();
        }
    }
}
