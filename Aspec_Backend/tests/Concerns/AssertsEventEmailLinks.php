<?php

namespace Tests\Concerns;

/**
 * Verificações comuns aos emails de eventos: o botão "Adicionar ao calendário" (um <a> com aspeto de
 * botão: estilo com o dourado da ASPEC ou classe de botão) e o URL do ficheiro .ics na API.
 */
trait AssertsEventEmailLinks
{
    protected function calendarIcsUrl(string $eventId): string
    {
        return rtrim(config('app.url'), '/')."/api/events/{$eventId}/calendar.ics";
    }

    /**
     * Abertura da tag <a> que aponta para $url (o href vem escapado em HTML), ou null se não existir.
     */
    protected function anchorTagFor(string $html, string $url): ?string
    {
        $href = preg_quote('href="'.e($url).'"', '/');

        return preg_match('/<a\b[^>]*'.$href.'[^>]*>/i', $html, $matches) ? $matches[0] : null;
    }

    /**
     * Tag de abertura e conteúdo do <a> que aponta para o .ics do evento, ou null se não existir.
     *
     * @return array{tag: string, inner: string, offset: int}|null
     */
    protected function calendarAnchor(string $html, string $eventId): ?array
    {
        $tag = $this->anchorTagFor($html, $this->calendarIcsUrl($eventId));
        if ($tag === null) {
            return null;
        }

        $offset = strpos($html, $tag);
        $innerStart = $offset + strlen($tag);

        return [
            'tag' => $tag,
            'inner' => substr($html, $innerStart, strpos($html, '</a>', $innerStart) - $innerStart),
            'offset' => $offset,
        ];
    }

    /**
     * Link pequeno da agenda: só o ícone (24×24, alt), com title e aria-label, sem aspeto de botão grande.
     */
    protected function assertCalendarIconLink(string $html, string $eventId, string $iconUrl): void
    {
        $anchor = $this->calendarAnchor($html, $eventId);

        $this->assertNotNull($anchor, 'Sem link para o .ics do evento.');
        $this->assertStringContainsString('title="Adicionar ao calendário"', $anchor['tag']);
        $this->assertStringContainsString('aria-label="Adicionar ao calendário"', $anchor['tag']);
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*button/i', $anchor['tag']);
        $this->assertMatchesRegularExpression('/<img\s[^>]*src="'.preg_quote(e($iconUrl), '/').'"/', $anchor['inner']);
        $this->assertMatchesRegularExpression('/<img\s[^>]*width="24"/', $anchor['inner']);
        $this->assertMatchesRegularExpression('/<img\s[^>]*height="24"/', $anchor['inner']);
        $this->assertMatchesRegularExpression('/<img\s[^>]*alt="Adicionar ao calendário"/', $anchor['inner']);
    }

    protected function assertCalendarButton(string $html, string $eventId): void
    {
        $url = $this->calendarIcsUrl($eventId);
        $tag = $this->anchorTagFor($html, $url);

        $this->assertNotNull($tag, "Sem link para {$url} no email.");
        $this->assertTrue(
            stripos($tag, '#8A7043') !== false || preg_match('/class="[^"]*button[^"]*"/i', $tag) === 1,
            "O link do calendário não tem aspeto de botão: {$tag}"
        );

        $start = strpos($html, $tag);
        $end = strpos($html, '</a>', $start);
        $this->assertStringContainsString('Adicionar ao calendário', substr($html, $start, $end - $start));
    }
}
