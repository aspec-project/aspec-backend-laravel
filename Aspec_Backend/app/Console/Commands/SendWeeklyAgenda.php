<?php

namespace App\Console\Commands;

use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\WeeklyAgendaNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Agenda semanal de eventos (agendada ao sábado às 10:00 de Lisboa em routes/console.php; também
 * pode correr à mão, por exemplo numa demonstração).
 */
class SendWeeklyAgenda extends Command
{
    private const OPEN_EVENTS_LIMIT = 10;

    protected $signature = 'events:send-weekly-agenda';

    protected $description = 'Envia a agenda semanal de eventos aos membros ativos com a preferência ligada.';

    /**
     * Para cada membro com a preferência ligada (conta Active com perfil), em lotes de 200:
     * - "A sua semana": eventos em que está inscrito com início entre agora (ao minuto) e o sábado
     *   seguinte às 23:59, inclusive (hora de Lisboa). Os eventos de sábado depois das 10:00 aparecem
     *   em duas agendas seguidas de propósito, para o membro saber com antecedência;
     * - "Inscrições abertas": os 10 eventos pontuais mais próximos com prazo aberto e vagas em que
     *   não está inscrito (lista calculada uma vez por execução).
     * Quem não tem nada nas duas secções não recebe email. Antes de enviar, reclama o envio com um
     * update condicional (sem agenda nos últimos 6 dias): correr o comando duas vezes na mesma semana
     * envia uma só agenda.
     */
    public function handle(): int
    {
        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $windowStart = now($timezone)->startOfMinute();
        $windowEnd = $windowStart->copy()->next(Carbon::SATURDAY)->endOfDay();
        $openEvents = $this->openEvents();
        $sent = 0;

        User::query()
            ->receivesEventEmails('notify_weekly_agenda')
            ->chunkById(200, function (Collection $users) use ($windowStart, $windowEnd, $openEvents, &$sent) {
                $registeredEvents = $this->futureRegisteredEvents($users, $windowStart);

                foreach ($users as $user) {
                    $registered = $registeredEvents->get($user->id, new Collection);

                    $weekEvents = $registered
                        ->filter(fn (EventInstance $event) => $event->startsAt()->between($windowStart, $windowEnd))
                        ->sortBy(fn (EventInstance $event) => $event->startsAt()->getTimestamp())
                        ->values();

                    $userOpenEvents = $openEvents
                        ->reject(fn (EventInstance $event) => $registered->contains('id', $event->id))
                        ->take(self::OPEN_EVENTS_LIMIT)
                        ->values();

                    if ($weekEvents->isEmpty() && $userOpenEvents->isEmpty()) {
                        continue;
                    }

                    if (! $this->claimWeeklyAgenda($user)) {
                        continue;
                    }

                    $user->notify(new WeeklyAgendaNotification($weekEvents, $userOpenEvents));
                    $sent++;
                }
            });

        $this->info("Agendas semanais enviadas: {$sent}");

        return self::SUCCESS;
    }

    /**
     * Eventos pontuais com prazo de inscrição aberto e vagas, por ordem de início.
     * O prazo está guardado na hora de Lisboa, por isso é comparado com a hora de Lisboa.
     *
     * @return Collection<int, EventInstance>
     */
    private function openEvents(): Collection
    {
        $now = now(config('app.event_timezone', 'Europe/Lisbon'))->format('Y-m-d H:i:s');

        return EventInstance::query()
            ->whereNull('series_id')
            ->where('registration_deadline', '>=', $now)
            ->with('location')
            ->withCount('registrations')
            ->get()
            ->filter(fn (EventInstance $event) => $event->registrations_count < $event->max_attendees)
            ->sortBy(fn (EventInstance $event) => $event->startsAt()->getTimestamp())
            ->values();
    }

    /**
     * Eventos a partir do dia de hoje em que cada utilizador do lote está inscrito, numa só query
     * (sem uma query por membro). Servem para a secção "A sua semana" e para tirar da secção
     * "Inscrições abertas" os eventos em que já está inscrito.
     *
     * @param  Collection<int, User>  $users
     * @return BaseCollection<string, Collection<int, EventInstance>> Por id do utilizador.
     */
    private function futureRegisteredEvents(Collection $users, Carbon $from): BaseCollection
    {
        return EventRegistration::query()
            ->whereIn('user_id', $users->modelKeys())
            ->whereHas('eventInstance', fn ($query) => $query->whereDate('event_date', '>=', $from->toDateString()))
            ->with('eventInstance.location')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($registrations) => new Collection($registrations->pluck('eventInstance')->all()));
    }

    /**
     * Marca a agenda desta semana como enviada, só se nenhuma tiver sido enviada nos últimos 6 dias.
     * Update condicional: entre duas execuções ao mesmo tempo, só uma consegue marcar e enviar.
     */
    private function claimWeeklyAgenda(User $user): bool
    {
        return User::query()
            ->whereKey($user->id)
            ->where(fn ($query) => $query->whereNull('weekly_agenda_sent_at')
                ->orWhere('weekly_agenda_sent_at', '<', now()->subDays(6)))
            ->update(['weekly_agenda_sent_at' => now()]) === 1;
    }
}
