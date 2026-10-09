<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * NIF português: 9 dígitos, o último é o dígito de controlo (módulo 11).
 * Pesos 9 a 2 nos 8 primeiros dígitos; resto = soma % 11; controlo = 0 se o resto
 * for 0 ou 1, senão 11 - resto. Apanha erros de digitação antes de emitir uma fatura.
 */
class PortugueseNif implements ValidationRule
{
    /**
     * Falha se o valor não for texto com 9 dígitos ou se o dígito de controlo não bater certo.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^\d{9}$/', $value) || ! self::hasValidCheckDigit($value)) {
            $fail('validation.portuguese_nif')->translate();
        }
    }

    /**
     * Calcula o dígito de controlo a partir dos 8 primeiros dígitos e compara com o 9.º.
     */
    private static function hasValidCheckDigit(string $nif): bool
    {
        $sum = 0;

        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $nif[$i] * (9 - $i);
        }

        $remainder = $sum % 11;
        $checkDigit = $remainder < 2 ? 0 : 11 - $remainder;

        return $checkDigit === (int) $nif[8];
    }
}
