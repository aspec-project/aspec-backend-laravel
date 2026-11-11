<?php

namespace App\Http\Requests;

use App\Models\EventInstance;
use App\Services\Events\EventSeriesSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use OverflowException;

class EventInstanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A autorização é feita pelo middleware admin das rotas.
        return true;
    }

    /**
     * Regras de validação para a criação e atualização de instâncias de eventos. A data do evento não pode
     * ser anterior a hoje (Lisboa) na criação e, na edição, só quando muda em relação à data guardada.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isOnline = $this->boolean('is_online');
        $today = now(config('app.event_timezone', 'Europe/Lisbon'))->toDateString();

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
                ? ['required', 'url:http,https', 'max:255']
                : ['prohibited'],
            'address' => $isOnline
                ? ['prohibited']
                : ['required', 'string', 'max:255'],
            'venue_name' => ['nullable', 'string', 'max:255'],
            'place_id' => $isOnline
                ? ['prohibited']
                : ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,512}$/D'],
            'latitude' => $isOnline
                ? ['prohibited']
                : ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => $isOnline
                ? ['prohibited']
                : ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'max_attendees' => ['required', 'integer', 'min:1', 'max:100000'],
            'registration_deadline' => $this->isMethod('post')
                ? ($this->has('recurrence')
                    ? ['prohibited']
                    : ['required', 'date_format:Y-m-d'])
                : ['required', 'date_format:Y-m-d'],
            'organizer_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'prayer_cards_enabled' => ['sometimes', 'boolean'],
            'prayer_verse' => ['required_if_accepted:prayer_cards_enabled', 'nullable', 'string', 'max:500'],
            'justification' => $this->isMethod('put')
                ? ['sometimes', 'nullable', 'string', 'max:2000']
                : ['prohibited'],
        ];

        if ($this->isMethod('post') || $this->changesStoredValue('event_date')) {
            $rules['event_date'][] = 'after_or_equal:'.$today;
        }

        if ($this->isMethod('post')) {
            $rules['recurrence'] = [
                'sometimes',
                'required',
                'array:frequency,ends_on,registration_deadline_days_before',
            ];
            $rules['recurrence.frequency'] = [
                'required_with:recurrence',
                'string',
                'in:weekly,biweekly,monthly',
            ];
            $rules['recurrence.ends_on'] = [
                'required_with:recurrence',
                'date_format:Y-m-d',
                'after_or_equal:event_date',
            ];
            $rules['recurrence.registration_deadline_days_before'] = [
                'required_with:recurrence',
                'integer',
                'min:1',
                'max:365',
            ];
        } else {
            $rules['recurrence'] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * Validações que dependem de vários campos: coordenadas, prazos de inscrição (nos eventos pontuais e em
     * cada sessão das séries) e, na edição, início no futuro quando a data ou a hora de início mudam.
     *
     * @param  ValidatorContract  $validator  Validador do pedido.
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            if (! $this->boolean('is_online')) {
                $hasLatitude = $this->filled('latitude');
                $hasLongitude = $this->filled('longitude');

                if ($hasLatitude !== $hasLongitude) {
                    $message = 'Envie latitude e longitude em conjunto, ou deixe ambas em branco para localizar a morada.';

                    $validator->errors()->add('latitude', $message);
                    $validator->errors()->add('longitude', $message);
                }

                if (
                    $this->filled('place_id')
                    && ($hasLatitude || $hasLongitude)
                ) {
                    $validator->errors()->add(
                        'place_id',
                        'Envie o identificador do local sem latitude ou longitude.'
                    );
                }
            }

            if ($this->isMethod('post') && $this->exists('recurrence')) {
                if ($validator->errors()->any()) {
                    return;
                }

                $recurrence = $this->input('recurrence');
                $timezone = config('app.event_timezone', 'Europe/Lisbon');
                $schedule = app(EventSeriesSchedule::class);
                $daysBefore = (int) $recurrence['registration_deadline_days_before'];

                try {
                    $sessionDates = $schedule->generate(
                        $this->input('event_date'),
                        $recurrence['ends_on'],
                        $recurrence['frequency']
                    );
                } catch (OverflowException) {
                    $validator->errors()->add(
                        'recurrence.ends_on',
                        'Uma série não pode exceder 52 sessões.'
                    );

                    return;
                }

                $deadline = $schedule->registrationDeadline($this->input('event_date'), $daysBefore);

                if ($deadline->lessThanOrEqualTo(now($timezone))) {
                    $validator->errors()->add(
                        'recurrence.registration_deadline_days_before',
                        'O prazo calculado para a primeira sessão já passou.'
                    );

                    return;
                }

                foreach ($sessionDates as $sessionDate) {
                    $sessionDeadline = $schedule->registrationDeadline($sessionDate, $daysBefore);

                    if ($this->deadlineIsAfterStart($sessionDeadline, $sessionDate, $this->input('event_start_time'))) {
                        $validator->errors()->add(
                            'recurrence.registration_deadline_days_before',
                            'O prazo de inscrição de cada sessão tem de ser anterior ou igual ao início da sessão.'
                        );

                        return;
                    }
                }

                return;
            }

            if (
                $this->isMethod('put')
                && ! $validator->errors()->has('event_date')
                && ! $validator->errors()->has('event_start_time')
                && ($this->changesStoredValue('event_date') || $this->changesStoredValue('event_start_time'))
                && $this->startsBeforeNow($this->input('event_date'), $this->input('event_start_time'))
            ) {
                $validator->errors()->add(
                    'event_start_time',
                    'A hora de início tem de ser posterior à hora atual.'
                );
            }

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

            if ($this->deadlineIsAfterStart($deadline, $this->input('event_date'), $this->input('event_start_time'))) {
                $validator->errors()->add(
                    'registration_deadline',
                    'O prazo de inscrição tem de ser anterior ou igual ao início do evento.'
                );
            }
        });
    }

    /**
     * Indica se o prazo de inscrição fica depois do início do evento ou da sessão (hora de Lisboa).
     *
     * @param  Carbon|CarbonImmutable  $deadline  Prazo de inscrição.
     * @param  string  $date  Data do evento ou da sessão (Y-m-d).
     * @param  string  $startTime  Hora de início (H:i).
     * @return bool true se o prazo é posterior ao início.
     */
    private function deadlineIsAfterStart(Carbon|CarbonImmutable $deadline, string $date, string $startTime): bool
    {
        return $deadline->greaterThan(
            Carbon::parse($date.' '.$startTime, config('app.event_timezone', 'Europe/Lisbon'))
        );
    }

    /**
     * Indica se, na edição, o valor enviado para o campo é diferente do guardado no evento.
     *
     * @param  string  $field  event_date ou event_start_time.
     * @return bool false na criação ou quando o valor não muda.
     */
    private function changesStoredValue(string $field): bool
    {
        $event = $this->route('eventInstance');

        if (! $this->isMethod('put') || ! $event instanceof EventInstance) {
            return false;
        }

        $stored = $field === 'event_date'
            ? $event->event_date->format('Y-m-d')
            : substr((string) $event->event_start_time, 0, 5);

        return $this->input($field) !== $stored;
    }

    /**
     * Indica se um início (data + hora, hora de Lisboa) já passou, com a mesma regra de
     * EventInstance::hasStarted (à hora em ponto ainda não começou).
     *
     * @param  string  $date  Data (Y-m-d).
     * @param  string  $startTime  Hora de início (H:i).
     * @return bool true se o início já passou.
     */
    private function startsBeforeNow(string $date, string $startTime): bool
    {
        $timezone = config('app.event_timezone', 'Europe/Lisbon');

        return Carbon::createFromFormat('!Y-m-d H:i', $date.' '.$startTime, $timezone)
            ->lessThan(now($timezone)->startOfSecond());
    }

    /**
     * Nomes dos campos em pt-PT nas mensagens de validação.
     *
     * @return array<string, string>
     */
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
            'place_id' => 'identificador do local',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'max_attendees' => 'lotação máxima',
            'registration_deadline' => 'prazo de inscrição',
            'is_online' => 'evento online',
            'online_url' => 'link do evento online',
            'organizer_notes' => 'notas do organizador',
            'prayer_cards_enabled' => 'cartões de oração',
            'prayer_verse' => 'versículo',
            'justification' => 'justificação',
            'recurrence.frequency' => 'frequência da série',
            'recurrence.ends_on' => 'data final da série',
            'recurrence.registration_deadline_days_before' => 'antecedência do prazo de inscrição',
        ];
    }

    /**
     * Mensagens de validação próprias em pt-PT.
     *
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return [
            'event_date.after_or_equal' => 'A data do evento não pode ser anterior a hoje.',
            'event_end_time.after' => 'A hora de fim deve ser posterior à hora de início.',
            'max_attendees.min' => 'A lotação máxima tem de ser pelo menos 1.',
            'latitude.between' => 'A latitude tem de estar entre -90 e 90.',
            'longitude.between' => 'A longitude tem de estar entre -180 e 180.',
            'place_id.regex' => 'O identificador do local não é válido.',
            'place_id.prohibited' => 'Eventos online não devem ter identificador do local.',
            'is_online.required' => 'Indique se o evento é online.',
            'is_online.boolean' => 'O campo evento online deve ser verdadeiro ou falso.',
            'online_url.required' => 'O link é obrigatório para eventos online.',
            'online_url.url' => 'Indique um link válido para o evento online.',
            'online_url.prohibited' => 'O link online só pode ser indicado para eventos online.',
            'address.prohibited' => 'Eventos online não devem ter morada.',
            'latitude.prohibited' => 'Eventos online não devem ter coordenadas.',
            'longitude.prohibited' => 'Eventos online não devem ter coordenadas.',
            'venue_name' => ['nullable', 'string', 'max:255'],
            'prayer_verse.required_if_accepted' => 'Indique o versículo da semana para ativar os cartões de oração.',
            'recurrence.registration_deadline_days_before.max' => 'A antecedência do prazo de inscrição não pode ser superior a 365 dias.',
        ];
    }

    /**
     * Dados validados prontos a gravar. Com os cartões de oração desligados o versículo fica null;
     * sem prayer_cards_enabled no pedido, o versículo também não muda.
     *
     * @return array<string, mixed>
     */
    public function validatedEventData(): array
    {
        $data = $this->validated();

        if (array_key_exists('prayer_cards_enabled', $data)) {
            $data['prayer_verse'] = $data['prayer_cards_enabled'] ? ($data['prayer_verse'] ?? null) : null;
        } else {
            unset($data['prayer_verse']);
        }

        if (isset($data['recurrence'])) {
            return $data;
        }

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
