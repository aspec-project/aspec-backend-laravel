<?php

namespace App\Services\Reviews;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

class ReviewContentModerator
{
    public function containsFlaggedLanguage(string $text): bool
    {
        $termsByLocale = config('review_moderation.terms');

        if (! is_array($termsByLocale) || $termsByLocale === []) {
            throw new LogicException('A configuração dos termos de moderação é inválida.');
        }

        $normalizedText = $this->normalize($text);

        foreach ($termsByLocale as $locale => $groups) {
            if (! is_string($locale) || ! is_array($groups)) {
                throw new LogicException('A configuração dos idiomas de moderação é inválida.');
            }

            foreach (['words', 'phrases'] as $group) {
                $terms = $groups[$group] ?? null;

                if (! is_array($terms)) {
                    throw new LogicException(
                        "O grupo '{$group}' não está configurado para o idioma '{$locale}'."
                    );
                }

                foreach ($terms as $term) {
                    if (! is_string($term) || trim($term) === '') {
                        throw new LogicException(
                            "A configuração contém um termo vazio ou inválido em '{$locale}.{$group}'."
                        );
                    }

                    $normalizedTerm = $this->normalize($term);
                    $pattern = '~(?<![\p{L}\p{N}_])'
                        .preg_quote($normalizedTerm, '~')
                        .'(?![\p{L}\p{N}_])~u';

                    $matched = preg_match($pattern, $normalizedText);

                    if ($matched === false) {
                        throw new RuntimeException(
                            'Falha ao verificar o comentário para moderação.'
                        );
                    }

                    if ($matched === 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);

        if ($normalized === false) {
            throw new InvalidArgumentException('Não foi possível normalizar o texto para moderação.');
        }

        $normalized = mb_strtolower($normalized, 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $normalized);

        if ($normalized === null) {
            throw new InvalidArgumentException('Não foi possível normalizar os espaços do texto.');
        }

        return trim($normalized);
    }
}
