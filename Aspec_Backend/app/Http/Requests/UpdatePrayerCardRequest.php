<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida o cartão de oração enviado ou editado pelo membro (PUT /api/events/{id}/prayer-card).
 */
class UpdatePrayerCardRequest extends FormRequest
{
    private const PARTS = ['personal_request', 'work_request', 'salvation_request'];

    /**
     * A autorização (conta Active, inscrição no evento) é feita pelo middleware da rota e pelo controller.
     *
     * @return bool Sempre true.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Pelo menos um dos 3 pedidos, cada um até 500 caracteres (vazio passa a null). O consentimento
     * explícito é obrigatório em cada envio: os pedidos são dados de crenças religiosas (RGPD art. 9.º).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $max = (int) config('prayer_cards.max_request_length');
        $rules = [];

        foreach (self::PARTS as $part) {
            $others = implode(',', array_diff(self::PARTS, [$part]));
            $rules[$part] = ['nullable', "required_without_all:{$others}", 'string', "max:{$max}"];
        }

        $rules['consent'] = ['required', 'accepted'];

        return $rules;
    }

    /**
     * Mensagens próprias para o pedido vazio e para o consentimento em falta.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $empty = 'Preencha pelo menos um dos pedidos: Pessoal, Emprego ou Salvação.';
        $consent = 'Tem de autorizar a partilha do cartão de oração para o enviar.';

        return [
            'personal_request.required_without_all' => $empty,
            'work_request.required_without_all' => $empty,
            'salvation_request.required_without_all' => $empty,
            'consent.required' => $consent,
            'consent.accepted' => $consent,
        ];
    }

    /**
     * Os 3 pedidos validados (os que não vieram ficam null).
     *
     * @return array{personal_request: ?string, work_request: ?string, salvation_request: ?string}
     */
    public function requests(): array
    {
        return [
            'personal_request' => $this->validated('personal_request'),
            'work_request' => $this->validated('work_request'),
            'salvation_request' => $this->validated('salvation_request'),
        ];
    }
}
