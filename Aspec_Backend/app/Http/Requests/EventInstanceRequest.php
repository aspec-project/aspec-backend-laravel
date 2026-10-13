<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class EventInstanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A autorização é feita pelo middleware admin das rotas.
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'uuid', 'exists:event_categories,id'],
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
            'name' => ['required', 'string', 'max:255'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'event_start_time' => ['required', 'date_format:H:i'],
            'event_end_time' => ['required', 'date_format:H:i', 'after:event_start_time'],
            'description' => ['required', 'string'],
            'address' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'max_attendees' => ['required', 'integer', 'min:1'],
            'registration_deadline' => ['required', 'date'],
            'organizer_notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            foreach (['event_date', 'event_start_time', 'registration_deadline'] as $field) {
                if ($validator->errors()->has($field)) {
                    return;
                }
            }

            $eventStartsAt = Carbon::parse(
                $this->input('event_date').' '.$this->input('event_start_time')
            );
            $deadline = Carbon::parse($this->input('registration_deadline'));

            if ($deadline->greaterThan($eventStartsAt)) {
                $validator->errors()->add(
                    'registration_deadline',
                    'O prazo de inscrição tem de ser anterior ou igual ao início do evento.'
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'category_id' => 'categoria',
            'location_id' => 'local',
            'name' => 'nome',
            'event_date' => 'data do evento',
            'event_start_time' => 'hora de início',
            'event_end_time' => 'hora de fim',
            'description' => 'descrição',
            'address' => 'morada',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'max_attendees' => 'lotação máxima',
            'registration_deadline' => 'prazo de inscrição',
            'organizer_notes' => 'notas do organizador',
        ];
    }

    public function messages(): array
    {
        return [
            'event_end_time.after' => 'A hora de fim deve ser posterior à hora de início.',
            'max_attendees.min' => 'A lotação máxima tem de ser pelo menos 1.',
            'latitude.between' => 'A latitude tem de estar entre -90 e 90.',
            'longitude.between' => 'A longitude tem de estar entre -180 e 180.',
        ];
    }
}
