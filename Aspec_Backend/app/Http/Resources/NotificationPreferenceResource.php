<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Preferências dos emails de eventos do próprio utilizador (recebe o User).
 */
class NotificationPreferenceResource extends JsonResource
{
    /**
     * Só as duas preferências; nenhum outro dado da conta.
     *
     * @return array{notify_new_events: bool, notify_weekly_agenda: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'notify_new_events' => (bool) $this->notify_new_events,
            'notify_weekly_agenda' => (bool) $this->notify_weekly_agenda,
        ];
    }
}
