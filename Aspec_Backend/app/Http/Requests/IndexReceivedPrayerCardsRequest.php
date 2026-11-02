<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida os filtros da lista de cartões de oração recebidos (GET /api/me/prayer-cards).
 */
class IndexReceivedPrayerCardsRequest extends FormRequest
{
    /**
     * A autorização é feita pelos middlewares da rota; a consulta parte sempre do próprio membro.
     *
     * @return bool Sempre true.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * period: current (ainda na semana de oração) ou previous (o resto); sem period devolve todos.
     * Página de 1 a 10000 e de 1 a 50 cartões por página.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period' => 'nullable|in:current,previous',
            'page' => 'nullable|integer|between:1,10000',
            'per_page' => 'nullable|integer|between:1,50',
        ];
    }

    /**
     * Nomes dos campos usados nas mensagens de erro.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'page' => 'página',
        ];
    }
}
