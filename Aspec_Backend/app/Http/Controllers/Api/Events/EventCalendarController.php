<?php

namespace App\Http\Controllers\Api\Events;

use App\Http\Controllers\Controller;
use App\Models\EventInstance;
use App\Support\EventCalendar;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Evento em ficheiro iCalendar (.ics) para o adicionar ao calendário, a partir dos emails de eventos.
 */
class EventCalendarController extends Controller
{
    /**
     * Descarrega o evento em .ics. Público e com as mesmas regras do detalhe público (GET /api/events/{id}):
     * qualquer evento existente, incluindo passados e sessões de séries; inexistente ou apagado → 404.
     * Se o detalhe público passar a filtrar eventos, o mesmo filtro tem de vir para aqui.
     *
     * @param  EventInstance  $eventInstance  Evento resolvido pelo route model binding.
     * @param  EventCalendar  $calendar  Gerador do ficheiro iCalendar.
     * @return Response 200 com o ficheiro (a resposta não é JSON; os erros continuam a sê-lo).
     */
    public function show(EventInstance $eventInstance, EventCalendar $calendar): Response
    {
        return response($calendar->forEvent($eventInstance), HttpResponse::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $calendar->filename($eventInstance)
            ),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
