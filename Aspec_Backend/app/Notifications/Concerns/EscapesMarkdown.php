<?php

namespace App\Notifications\Concerns;

/**
 * Escapa texto livre (escrito pelos admins) antes de entrar numa linha Markdown de um email.
 */
trait EscapesMarkdown
{
    /**
     * Escapa a pontuação Markdown no texto escrito pelos admins (nome do evento, espaço, distrito, motivo,
     * justificação), para que um texto como "Sala](https://...)" não crie outro link nem formatação no email.
     * O < e o > não entram: o {{ }} do Blade já os escapa em HTML, e com a barra apareceriam como "&lt;" no email.
     */
    protected function markdownText(string $text): string
    {
        return addcslashes($text, '\`*_{}[]()#+-.!|');
    }
}
