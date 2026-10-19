<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * Número de telefone de qualquer país, validado com a libphonenumber da Google (regras reais de
 * cada país). Sem indicativo, o número é interpretado como português. Guarda-se sempre em E.164
 * (ex. "+351912345678"). Não lê outros campos do pedido (ex. phone_country), ao contrário da
 * regra do pacote, para que tudo o que passa na validação seja convertido pelo normalize().
 */
class InternationalPhone implements ValidationRule
{
    public const DEFAULT_COUNTRY = 'PT';

    /**
     * Converte um número válido para E.164 (ex. "+44 7911 123456" → "+447911123456").
     * Um número inválido fica só sem separadores, para a regra o rejeitar; valores que não são
     * texto ficam como estão (a regra 'string' rejeita-os depois). Usada no prepareForValidation.
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $phone = self::parse($value);

        return $phone?->formatE164() ?? self::stripSeparators($value);
    }

    /**
     * Falha se o valor não for texto ou não for um número válido. Usa a mesma interpretação do
     * normalize(), por isso nunca passa um valor que não se consiga guardar em E.164.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || self::parse($value) === null) {
            $fail('validation.international_phone')->translate();
        }
    }

    /**
     * Devolve o número interpretado, ou null se for inválido. Só aceita dígitos ASCII, um "+"
     * no início e separadores: a libphonenumber ignora texto à volta do número (ex. HTML, "Tel:",
     * extensões), que assim nunca chega a ser aceite. PhoneNumber::isValid() apanha as exceções
     * da libphonenumber, por isso nunca dá erro 500.
     */
    private static function parse(string $value): ?PhoneNumber
    {
        if (! preg_match('/^[0-9+\s\-.()]{1,40}$/D', $value)
            || ! preg_match('/^\+?[0-9]+$/D', self::stripSeparators($value))) {
            return null;
        }

        // O pacote só aceita um número sem "+" se for válido no país por omissão, logo "0044…"
        // (estrangeiro com o prefixo internacional português 00) seria rejeitado.
        $number = preg_replace('/^00/', '+', self::stripSeparators($value));

        $phone = new PhoneNumber($number, self::DEFAULT_COUNTRY);

        return $phone->isValid() ? $phone : null;
    }

    private static function stripSeparators(string $value): string
    {
        return preg_replace('/[\s\-.()]/', '', $value);
    }
}
