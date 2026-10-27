<?php

namespace App\Notifications\Concerns;

use App\Models\EventInstance;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

/**
 * Partes comuns aos emails de eventos para os membros (anúncio e agenda semanal): saudação,
 * descrição curta do evento, link para a página do evento e rodapé das preferências.
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
     * Data, horas e local numa linha (ex.: "25/11/2026, 12:30–14:00 · Hotel Central, Rua X, Porto").
     */
    protected function eventSummary(EventInstance $event): string
    {
        $start = substr((string) $event->event_start_time, 0, 5);
        $end = substr((string) $event->event_end_time, 0, 5);

        return "{$event->event_date->format('d/m/Y')}, {$start}–{$end} · {$this->eventPlace($event)}";
    }

    /**
     * "Online" ou o nome do espaço, a morada e o distrito (só os que existem).
     */
    protected function eventPlace(EventInstance $event): string
    {
        if ($event->is_online) {
            return 'Online';
        }

        return collect([$event->venue_name, $event->address, $event->location?->name])
            ->filter(fn (?string $part) => filled($part))
            ->implode(', ');
    }

    /**
     * Último dia para a inscrição (dd/mm/aaaa), lido do valor guardado na hora de Portugal.
     */
    protected function registrationDeadlineDate(EventInstance $event): string
    {
        return Carbon::parse($event->getRawOriginal('registration_deadline'))->format('d/m/Y');
    }

    /**
     * Página do evento no frontend.
     */
    protected function eventUrl(EventInstance $event): string
    {
        return $this->frontendUrl("/eventos/{$event->id}");
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
}
