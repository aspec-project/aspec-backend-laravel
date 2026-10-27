<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    /**
     * A autorização (conta Active) é feita pelo middleware account.active da rota.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * As duas preferências são obrigatórias: o PUT substitui as preferências inteiras.
     * Aceita true/false, 1/0 e "1"/"0".
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'notify_new_events' => 'required|boolean',
            'notify_weekly_agenda' => 'required|boolean',
        ];
    }

    /**
     * Preferências validadas convertidas em booleanos (1/0 e "1"/"0" passam a true/false).
     *
     * @return array{notify_new_events: bool, notify_weekly_agenda: bool}
     */
    public function preferences(): array
    {
        return [
            'notify_new_events' => $this->boolean('notify_new_events'),
            'notify_weekly_agenda' => $this->boolean('notify_weekly_agenda'),
        ];
    }
}
