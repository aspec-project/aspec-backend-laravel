<?php

namespace App\Support;

use App\Models\EventInstance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Gera o ficheiro iCalendar (RFC 5545) de um evento, para o membro o adicionar ao calendário
 * (Google, Outlook, Apple). Um só VEVENT, com as horas em UTC e sem o link da reunião online,
 * porque o ficheiro é público.
 */
class EventCalendar
{
    public const ONLINE_LOCATION = 'Evento online: o link fica disponível na página do evento';

    private const DESCRIPTION_LIMIT = 500;

    /**
     * Conteúdo do ficheiro .ics do evento, com todas as linhas terminadas em CRLF e dobradas a 75 octetos.
     *
     * As horas saem em UTC (sufixo Z), convertidas da hora de Lisboa em que o backoffice guarda os
     * eventos; assim não é preciso um VTIMEZONE com as regras da hora de verão. O UID é estável
     * (id do evento + host do frontend), para que reimportar atualize a entrada em vez de a duplicar.
     */
    public function forEvent(EventInstance $event): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//ASPEC//Plataforma ASPEC//PT',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
        ];
        $lines[] = 'UID:'.$event->id.'@'.$this->frontendHost();
        $lines[] = 'DTSTAMP:'.$this->utc(now());
        $lines[] = 'DTSTART:'.$this->utc($event->startsAt());
        $lines[] = 'DTEND:'.$this->utc($this->endsAt($event));

        if ($event->updated_at !== null) {
            $lines[] = 'LAST-MODIFIED:'.$this->utc($event->updated_at);
        }

        $lines[] = 'SUMMARY:'.$this->text($event->name);
        $lines[] = 'DESCRIPTION:'.$this->text($this->description($event));
        $lines[] = 'LOCATION:'.$this->text($this->location($event));
        $lines[] = 'URL:'.$this->eventUrl($event);
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return collect($lines)
            ->map(fn (string $line): string => $this->fold($line)."\r\n")
            ->implode('');
    }

    /**
     * Nome do ficheiro: o nome do evento em slug ASCII, até 60 caracteres, ou "evento" se ficar vazio.
     */
    public function filename(EventInstance $event): string
    {
        $slug = rtrim(substr(Str::slug($event->name), 0, 60), '-');

        return ($slug === '' ? 'evento' : $slug).'.ics';
    }

    /**
     * Fim do evento na hora de Lisboa (a validação garante que acaba no mesmo dia, depois do início).
     */
    private function endsAt(EventInstance $event): Carbon
    {
        return Carbon::createFromFormat(
            'Y-m-d H:i',
            $event->event_date->format('Y-m-d').' '.substr((string) $event->event_end_time, 0, 5),
            config('app.event_timezone', 'Europe/Lisbon')
        );
    }

    private function utc(Carbon $moment): string
    {
        return $moment->copy()->utc()->format('Ymd\THis\Z');
    }

    /**
     * Descrição cortada a 500 caracteres, o aviso dos eventos online e o link para a página do evento.
     */
    private function description(EventInstance $event): string
    {
        return collect([
            filled($event->description) ? Str::limit($event->description, self::DESCRIPTION_LIMIT) : null,
            $event->is_online ? self::ONLINE_LOCATION.'.' : null,
            'Mais informações: '.$this->eventUrl($event),
        ])->filter()->implode("\n\n");
    }

    /**
     * Presencial: espaço, morada e distrito (só os preenchidos). Online: só o aviso, nunca o online_url,
     * que é dado apenas aos inscritos depois do prazo de inscrição.
     */
    private function location(EventInstance $event): string
    {
        if ($event->is_online) {
            return self::ONLINE_LOCATION;
        }

        return collect([$event->venue_name, $event->address, $event->location?->name])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(', ');
    }

    private function eventUrl(EventInstance $event): string
    {
        return rtrim(config('app.frontend_url'), '/').'/eventos/'.$event->id;
    }

    private function frontendHost(): string
    {
        return parse_url((string) config('app.frontend_url'), PHP_URL_HOST) ?: 'aspec';
    }

    /**
     * Escapa um valor TEXT (RFC 5545, 3.3.11). A barra invertida tem de ser a primeira, senão
     * duplicava as barras acrescentadas pelos outros escapes.
     */
    private function text(string $value): string
    {
        $value = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);

        return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $value);
    }

    /**
     * Dobra a linha a 75 octetos (RFC 5545, 3.1): cada continuação começa por um espaço e leva no
     * máximo 74 octetos. O mb_strcut corta por octetos sem partir um carácter UTF-8 a meio.
     */
    private function fold(string $line): string
    {
        $folded = mb_strcut($line, 0, 75, 'UTF-8');
        $rest = substr($line, strlen($folded));

        while ($rest !== '') {
            $chunk = mb_strcut($rest, 0, 74, 'UTF-8');
            $folded .= "\r\n ".$chunk;
            $rest = substr($rest, strlen($chunk));
        }

        return $folded;
    }
}
