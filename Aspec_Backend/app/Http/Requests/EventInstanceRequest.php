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

    // Regras de validação para a criação e atualização de instâncias de eventos.
    public function rules(): array
    {
        $isOnline = $this->boolean('is_online');

        $rules = [
            'category_id' => ['required', 'uuid', 'exists:event_categories,id'],
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
            'name' => ['required', 'string', 'max:255'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'event_start_time' => ['required', 'date_format:H:i'],
            'event_end_time' => ['required', 'date_format:H:i', 'after:event_start_time'],
            'description' => ['required', 'string', 'max:5000'],
            'is_online' => ['required', 'boolean'],
            'online_url' => $isOnline
                ? ['required', 'url', 'max:255']
                : ['prohibited'],
            'address' => $isOnline
                ? ['prohibited']
                : ['required', 'string', 'max:255'],
            'venue_name' => ['nullable', 'string', 'max:255'],
            'latitude' => $isOnline
                ? ['prohibited']
                : ['required', 'numeric', 'between:-90,90'],
            'longitude' => $isOnline
                ? ['prohibited']
                : ['required', 'numeric', 'between:-180,180'],
            'max_attendees' => ['required', 'integer', 'min:1', 'max:100000'],
            'registration_deadline' => ['required', 'date_format:Y-m-d'],
            'organizer_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];

        if ($this->isMethod('post')) {
            $now = now(config('app.event_timezone', 'Europe/Lisbon'));

            $rules['event_date'][] = 'after_or_equal:'.$now->toDateString();
        }

        return $rules;
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            if ($validator->errors()->has('registration_deadline')) {
                return;
            }

            $timezone = config('app.event_timezone', 'Europe/Lisbon');

            $deadline = Carbon::createFromFormat(
                '!Y-m-d',
                $this->input('registration_deadline'),
                $timezone
            )->setTime(23, 59);

            if ($this->isMethod('post') && $deadline->lessThanOrEqualTo(now($timezone))) {
                $validator->errors()->add(
                    'registration_deadline',
                    'O prazo de inscrição tem de ser posterior à data e hora atuais.'
                );
            }

            if (
                $validator->errors()->has('event_date')
                || $validator->errors()->has('event_start_time')
            ) {
                return;
            }

            $eventStartsAt = Carbon::parse(
                $this->input('event_date').' '.$this->input('event_start_time'),
                $timezone
            );

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
            'is_online' => 'evento online',
            'online_url' => 'link do evento online',
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
            'is_online.required' => 'Indique se o evento é online.',
            'is_online.boolean' => 'O campo evento online deve ser verdadeiro ou falso.',
            'online_url.required' => 'O link é obrigatório para eventos online.',
            'online_url.url' => 'Indique um link válido para o evento online.',
            'online_url.prohibited' => 'O link online só pode ser indicado para eventos online.',
            'address.prohibited' => 'Eventos online não devem ter morada.',
            'latitude.prohibited' => 'Eventos online não devem ter coordenadas.',
            'longitude.prohibited' => 'Eventos online não devem ter coordenadas.',
            'venue_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedEventData(): array
    {
        $data = $this->validated();
        $timezone = config('app.event_timezone', 'Europe/Lisbon');

        $data['registration_deadline'] = Carbon::createFromFormat(
            '!Y-m-d',
            $data['registration_deadline'],
            $timezone
        )
            ->setTime(23, 59)
            ->format('Y-m-d H:i:s');

        return $data;
    }
}
