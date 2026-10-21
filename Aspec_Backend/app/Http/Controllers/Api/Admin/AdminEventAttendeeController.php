<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventAttendeeResource;
use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Services\Exports\CsvExporter;
use Collator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inscritos de um evento no backoffice: lista em JSON e exportação da lista de presenças em CSV para o Excel.
 */
class AdminEventAttendeeController extends Controller
{
    /**
     * Lista os inscritos do evento com o nome, a empresa e os contactos pessoais, por ordem alfabética portuguesa.
     *
     * @param  Request  $request  Pedido HTTP atual.
     * @param  EventInstance  $eventInstance  Evento resolvido pelo route model binding.
     * @return JsonResponse 200 com a lista de inscritos (vazia se não houver).
     */
    public function index(Request $request, EventInstance $eventInstance): JsonResponse
    {
        return $this->successResponse(
            EventAttendeeResource::collection($this->attendees($eventInstance))->resolve($request),
            'Inscritos do evento obtidos com sucesso.'
        );
    }

    /**
     * Exporta a lista de presenças do evento em CSV (só depois do prazo de inscrição).
     *
     * @param  EventInstance  $eventInstance  Evento resolvido pelo route model binding.
     * @param  CsvExporter  $exporter  Serviço que escreve o CSV no formato do Excel.
     * @return JsonResponse|StreamedResponse 200 com o ficheiro ou 409 se as inscrições ainda estiverem abertas.
     */
    public function export(EventInstance $eventInstance, CsvExporter $exporter): JsonResponse|StreamedResponse
    {
        $timezone = config('app.event_timezone', 'Europe/Lisbon');
        $deadline = Carbon::parse($eventInstance->getRawOriginal('registration_deadline'), $timezone);

        if (now($timezone)->lessThanOrEqualTo($deadline)) {
            return $this->errorResponse(
                'As inscrições ainda estão abertas. A lista de presenças só pode ser exportada depois do prazo de inscrição.',
                Response::HTTP_CONFLICT
            );
        }

        $rows = $this->attendees($eventInstance)
            ->map(fn (array $attendee): array => [
                $attendee['name'],
                $attendee['business_name'],
                $attendee['email'],
                $attendee['phone'],
                $attendee['registered_at']->copy()->setTimezone($timezone)->format('d/m/Y H:i'),
            ])
            ->all();

        return $exporter->download(
            $this->filename($eventInstance),
            ['Nome', 'Empresa', 'Email', 'Telefone', 'Data de inscrição'],
            $rows
        );
    }

    /**
     * Calcula as linhas dos inscritos (as mesmas no JSON e no CSV): contas apagadas ficam com o nome
     * e a empresa anonimizados e sem contactos; nomes vazios ficam no fim.
     *
     * @param  EventInstance  $eventInstance  Evento cujos inscritos se pretendem.
     * @return Collection<int, array{id: string, user_id: string|null, name: string|null, business_name: string|null, email: string|null, phone: string|null, registered_at: Carbon}>
     */
    private function attendees(EventInstance $eventInstance): Collection
    {
        $collator = new Collator('pt_PT');

        return $eventInstance->registrations()
            ->with([
                'user' => fn ($query) => $query->withTrashed()
                    ->select(['id', 'email', 'phone', 'deleted_at'])
                    ->with([
                        'memberProfile' => fn ($profileQuery) => $profileQuery
                            ->withTrashed()
                            ->select(['id', 'user_id', 'name', 'business_name']),
                    ]),
            ])
            ->get()
            ->map(function (EventRegistration $registration): array {
                $user = $registration->user;
                $deleted = $user === null || $user->trashed();

                return [
                    'id' => $registration->id,
                    'user_id' => $deleted ? null : $user->id,
                    'name' => $user?->memberProfile?->name,
                    'business_name' => $user?->memberProfile?->business_name,
                    'email' => $deleted ? null : $user->email,
                    'phone' => $deleted ? null : $user->phone,
                    'registered_at' => $registration->created_at,
                ];
            })
            ->sort(function (array $a, array $b) use ($collator): int {
                if ($a['name'] !== null && $b['name'] !== null) {
                    $byName = $collator->compare($a['name'], $b['name']);
                } else {
                    $byName = ($a['name'] === null) <=> ($b['name'] === null);
                }

                return $byName
                    ?: $a['registered_at']->getTimestamp() <=> $b['registered_at']->getTimestamp()
                    ?: strcmp($a['id'], $b['id']);
            })
            ->values();
    }

    /**
     * Gera o nome do ficheiro só com ASCII: presencas_<AAAA-MM-DD>_<nome-do-evento>.csv (nome com 50 caracteres no máximo).
     *
     * @param  EventInstance  $eventInstance  Evento exportado.
     */
    private function filename(EventInstance $eventInstance): string
    {
        $slug = rtrim(substr(Str::slug($eventInstance->name), 0, 50), '-');

        return 'presencas_'.$eventInstance->event_date->format('Y-m-d')
            .($slug !== '' ? '_'.$slug : '')
            .'.csv';
    }
}
