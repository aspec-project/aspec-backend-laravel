<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Número de telefone português: telemóvel (91, 92, 93, 96), fixo (2x) ou nómada (30),
 * com o indicativo +351 opcional. É a mesma expressão do frontend (registerUtils.js),
 * para que backend e frontend aceitem exatamente os mesmos números.
 */
class PortuguesePhone implements ValidationRule
{
    private const PATTERN = '/^(\+351)?(9[1236]\d{7}|2\d{8}|30\d{7})$/';

    /**
     * Tira espaços, hífenes e parênteses e o indicativo +351, para guardar sempre os 9 dígitos
     * (ex. "+351 912 345 678" → "912345678"). Usada no prepareForValidation dos Form Requests.
     * Valores que não são texto ficam como estão: a regra 'string' rejeita-os depois.
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_replace('/^\+351/', '', self::stripSeparators($value));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(self::PATTERN, self::stripSeparators($value))) {
            $fail('validation.portuguese_phone')->translate();
        }
    }

    /**
     * Os separadores também se ignoram aqui: a regra fica correta mesmo num pedido que não normaliza.
     */
    private static function stripSeparators(string $value): string
    {
        return preg_replace('/[\s\-()]/', '', $value);
    }
}
