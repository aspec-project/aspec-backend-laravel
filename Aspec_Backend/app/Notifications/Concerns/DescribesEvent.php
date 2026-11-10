<?php

namespace App\Notifications\Concerns;

use App\Models\EventInstance;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

/**
 * Partes comuns aos emails de eventos para os membros (anúncio e agenda semanal): saudação,
 * descrição curta do evento com o local no mapa, botões (página do evento, calendário) e rodapé
 * das preferências.
 */
trait DescribesEvent
{
    /**
     * Email já com saudação ("Olá, {nome}!", com o nome profissional) e despedida da ASPEC.
     */
    protected function eventMail(object $notifiable): MailMessage
    {
        $name = $notifiable->memberProfile?->name;

        return (new MailMessage)
            ->greeting($name ? "Olá, {$name}!" : 'Olá!')
            ->salutation("Cumprimentos,\nASPEC");
    }

    /**
     * Põe o corpo do email na vista mail.events.message, bloco a bloco: texto Markdown, botão, botões lado a lado ou evento
     * (título, detalhes e link para o calendário). O MailMessage do Laravel só tem um botão; assim cada
     * evento pode ter o seu link "Adicionar ao calendário", com o mesmo tema dos outros emails e a
     * versão em texto correta.
     *
     * @param  array<int, string|array{type: string}>  $blocks
     */
    protected function withBlocks(MailMessage $mail, array $blocks): MailMessage
    {
        return $mail->markdown('mail.events.message', ['blocks' => $blocks]);
    }

    /**
     * Bloco de botão para withBlocks().
     *
     * @return array{type: string, text: string, url: string}
     */
    protected function button(string $text, string $url): array
    {
        return ['type' => 'button', 'text' => $text, 'url' => $url];
    }

    /**
     * Bloco com vários botões lado a lado, numa linha de tabela (uma célula por botão, centrados).
     *
     * @param  array{type: string, text: string, url: string}  ...$buttons
     * @return array{type: string, buttons: array<int, array{type: string, text: string, url: string}>}
     */
    protected function buttonRow(array ...$buttons): array
    {
        return ['type' => 'buttons', 'buttons' => $buttons];
    }

    /**
     * Botão "Adicionar ao calendário" com o link para o ficheiro .ics do evento.
     *
     * @return array{type: string, text: string, url: string}
     */
    protected function calendarButton(EventInstance $event): array
    {
        return $this->button('Adicionar ao calendário', $this->calendarUrl($event));
    }

    /**
     * Data, horas e local numa linha (ex.: "25/11/2026, 12:30–14:00 · [Hotel Central](mapa), Porto").
     */
    protected function eventSummary(EventInstance $event): string
    {
        $start = substr((string) $event->event_start_time, 0, 5);
        $end = substr((string) $event->event_end_time, 0, 5);

        return "{$event->event_date->format('d/m/Y')}, {$start}–{$end} · {$this->eventPlace($event)}";
    }

    /**
     * Local em Markdown: "Online", ou o nome do espaço (ou "Ver no mapa") com link para o Google Maps,
     * seguido do distrito. A morada não aparece em texto: está no mapa, na página do evento e no .ics.
     * Sem nada para procurar no mapa, fica só o espaço e o distrito, sem link.
     */
    protected function eventPlace(EventInstance $event): string
    {
        if ($event->is_online) {
            return 'Online';
        }

        $district = filled($event->location?->name) ? $this->markdownText($event->location->name) : null;
        $mapsUrl = $this->mapsUrl($event);

        if ($mapsUrl === null) {
            return collect([filled($event->venue_name) ? $this->markdownText($event->venue_name) : null, $district])
                ->filter()
                ->implode(', ');
        }

        $label = filled($event->venue_name) ? $this->markdownText($event->venue_name) : 'Ver no mapa';

        return collect(["[{$label}]({$mapsUrl})", $district])->filter()->implode(', ');
    }

    /**
     * Link do Google Maps igual ao da página do evento no frontend: procura pelas coordenadas, senão
     * pela morada, pelo espaço ou pelo place_id, e junta o query_place_id quando existe (local exato
     * do Google). Null se não houver nada para procurar.
     */
    protected function mapsUrl(EventInstance $event): ?string
    {
        // O MySQL devolve os decimais como texto com zeros ("41.1500000"); o float deixa "41.15", como no frontend.
        $coordinates = $event->latitude !== null && $event->longitude !== null
            ? (float) $event->latitude.','.(float) $event->longitude
            : null;

        $query = collect([$coordinates, $event->address, $event->venue_name, $event->place_id])
            ->first(fn (?string $value): bool => filled($value));

        if ($query === null) {
            return null;
        }

        $url = 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query);

        return filled($event->place_id) ? $url.'&query_place_id='.rawurlencode($event->place_id) : $url;
    }

    /**
     * Ficheiro .ics do evento na API. Usa o APP_URL (route()) e não o frontend, porque o Vite local
     * não encaminha /api e no deploy a API responde sem passar pelo rewrite do Vercel.
     */
    protected function calendarUrl(EventInstance $event): string
    {
        return route('events.calendar', ['eventInstance' => $event->id]);
    }

    /**
     * Último dia para a inscrição (dd/mm/aaaa), lido do valor guardado na hora de Portugal.
     */
    protected function registrationDeadlineDate(EventInstance $event): string
    {
        return Carbon::parse($event->getRawOriginal('registration_deadline'))->format('d/m/Y');
    }

    /**
     * Página de login, com regresso ao evento depois da autenticação.
     */
    protected function eventUrl(EventInstance $event): string
    {
        return $this->frontendUrl('/login?redirect='.rawurlencode("/eventos/{$event->id}"));
    }

    /**
     * Rodapé com o link para a página da conta, onde o membro desliga estes emails.
     */
    protected function preferencesFooter(): string
    {
        return 'Pode desligar estes emails na [página da sua conta]('.$this->frontendUrl('/perfil/conta').').';
    }

    /**
     * URL absoluto do frontend para o caminho indicado.
     */
    protected function frontendUrl(string $path): string
    {
        return rtrim(config('app.frontend_url'), '/').$path;
    }

    /**
     * Escapa a pontuação Markdown no texto escrito pelos admins (nome do evento, espaço, distrito), para
     * que um nome como "Sala](https://...)" não crie outro link nem formatação no email. O < e o > não
     * entram: o {{ }} do Blade já os escapa em HTML, e com a barra apareceriam como "&lt;" no email.
     */
    protected function markdownText(string $text): string
    {
        return addcslashes($text, '\\`*_{}[]()#+-.!|');
    }
}
