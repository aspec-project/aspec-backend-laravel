<?php

namespace Tests\Unit\Services\Reviews;

use App\Services\Reviews\ReviewContentModerator;
use Illuminate\Support\Facades\Config;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReviewContentModeratorTest extends TestCase
{
    private function setTerms(array $termsByLocale): void
    {
        Config::set('review_moderation.terms', $termsByLocale);
    }

    #[Test]
    public function it_matches_a_complete_word_without_case_sensitivity(): void
    {
        $this->setTerms([
            'en' => [
                'words' => ['moderationtestterm'],
                'phrases' => [],
            ],
        ]);

        $this->assertTrue(
            app(ReviewContentModerator::class)
                ->containsFlaggedLanguage('Texto com MODERATIONTESTTERM.')
        );
    }

    #[Test]
    public function it_does_not_match_a_term_inside_another_word(): void
    {
        $this->setTerms([
            'en' => [
                'words' => ['moderationtestterm'],
                'phrases' => [],
            ],
        ]);

        $moderator = app(ReviewContentModerator::class);

        foreach ([
            'prefixmoderationtesttermpostfix',
            'émoderationtestterm',
            'moderationtesttermé',
            'moderationtestterm_2',
        ] as $text) {
            $this->assertFalse(
                $moderator->containsFlaggedLanguage($text),
                "O texto '{$text}' não deveria corresponder ao termo completo."
            );
        }
    }

    #[Test]
    public function it_matches_phrases_with_variable_whitespace_and_case(): void
    {
        $this->setTerms([
            'en' => [
                'words' => [],
                'phrases' => ['moderation test phrase'],
            ],
        ]);

        $this->assertTrue(
            app(ReviewContentModerator::class)
                ->containsFlaggedLanguage("Texto MODERATION\t  TEST\nPHRASE.")
        );
    }

    #[Test]
    public function it_checks_words_and_phrases_in_every_configured_locale(): void
    {
        $termsByLocale = [
            'pt-PT' => [
                'words' => ['portuguese_word_marker'],
                'phrases' => ['portuguese phrase marker'],
            ],
            'en' => [
                'words' => ['english_word_marker'],
                'phrases' => ['english phrase marker'],
            ],
            'es' => [
                'words' => ['spanish_word_marker'],
                'phrases' => ['spanish phrase marker'],
            ],
            'fr' => [
                'words' => ['french_word_marker'],
                'phrases' => ['french phrase marker'],
            ],
        ];

        $this->setTerms($termsByLocale);
        $moderator = app(ReviewContentModerator::class);

        foreach ($termsByLocale as $locale => $groups) {
            foreach ($groups as $group => $terms) {
                foreach ($terms as $term) {
                    $this->assertTrue(
                        $moderator->containsFlaggedLanguage("Texto: {$term}."),
                        "O termo do grupo '{$group}' no idioma '{$locale}' não foi encontrado."
                    );
                }
            }
        }
    }

    #[Test]
    public function it_matches_unicode_text_after_normalization(): void
    {
        $this->setTerms([
            'fr' => [
                'words' => ["caf\u{00E9}"],
                'phrases' => [],
            ],
        ]);

        $decomposedText = "Texte cafe\u{0301}.";

        $this->assertTrue(
            app(ReviewContentModerator::class)
                ->containsFlaggedLanguage($decomposedText)
        );
    }

    #[Test]
    public function it_treats_regex_characters_in_terms_as_literal_text(): void
    {
        $this->setTerms([
            'en' => [
                'words' => ['moderation+test.term'],
                'phrases' => [],
            ],
        ]);

        $this->assertTrue(
            app(ReviewContentModerator::class)
                ->containsFlaggedLanguage('Texto moderation+test.term.')
        );

        $this->assertFalse(
            app(ReviewContentModerator::class)
                ->containsFlaggedLanguage('Texto moderationXXtestYterm.')
        );
    }

    #[Test]
    public function it_does_not_flag_when_configured_term_lists_are_empty(): void
    {
        $this->setTerms([
            'pt-PT' => [
                'words' => [],
                'phrases' => [],
            ],
            'en' => [
                'words' => [],
                'phrases' => [],
            ],
        ]);

        $this->assertFalse(
            app(ReviewContentModerator::class)
                ->containsFlaggedLanguage('Comentário normal.')
        );
    }

    #[Test]
    public function it_throws_when_the_terms_configuration_is_not_an_array(): void
    {
        Config::set('review_moderation.terms', null);

        $this->expectException(LogicException::class);

        app(ReviewContentModerator::class)
            ->containsFlaggedLanguage('Comentário de teste.');
    }

    #[Test]
    public function it_throws_when_the_terms_configuration_is_empty(): void
    {
        $this->setTerms([]);

        $this->expectException(LogicException::class);

        app(ReviewContentModerator::class)
            ->containsFlaggedLanguage('Comentário de teste.');
    }

    #[Test]
    public function it_throws_when_a_locale_group_is_not_an_array(): void
    {
        $this->setTerms([
            'en' => 'invalid',
        ]);

        $this->expectException(LogicException::class);

        app(ReviewContentModerator::class)
            ->containsFlaggedLanguage('Comentário de teste.');
    }

    #[Test]
    public function it_throws_when_a_locale_is_missing_a_term_group(): void
    {
        $this->setTerms([
            'en' => [
                'words' => [],
            ],
        ]);

        $this->expectException(LogicException::class);

        app(ReviewContentModerator::class)
            ->containsFlaggedLanguage('Comentário de teste.');
    }

    #[Test]
    public function it_throws_when_a_configured_term_is_not_a_string(): void
    {
        $this->setTerms([
            'en' => [
                'words' => [123],
                'phrases' => [],
            ],
        ]);

        $this->expectException(LogicException::class);

        app(ReviewContentModerator::class)
            ->containsFlaggedLanguage('Comentário de teste.');
    }

    #[Test]
    public function it_throws_when_a_configured_term_is_empty(): void
    {
        $this->setTerms([
            'en' => [
                'words' => ['   '],
                'phrases' => [],
            ],
        ]);

        $this->expectException(LogicException::class);

        app(ReviewContentModerator::class)
            ->containsFlaggedLanguage('Comentário de teste.');
    }
}
