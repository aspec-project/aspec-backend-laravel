<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida a página da lista de candidaturas pendentes do painel (GET /api/admin/dashboard/pending-applications).
 */
class IndexDashboardPendingApplicationsRequest extends FormRequest
{
    /**
     * A autorização é feita pelos middlewares da rota (grupo admin).
     *
     * @return bool Sempre true.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Página opcional de 1 a 10000; o tamanho da página é fixo (3), por isso não há per_page.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => 'nullable|integer|between:1,10000',
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
