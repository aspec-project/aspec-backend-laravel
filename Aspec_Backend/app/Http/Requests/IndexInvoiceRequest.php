<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida a paginação da lista de faturas do membro (GET /api/invoices).
 */
class IndexInvoiceRequest extends FormRequest
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
     * Página de 1 a 10000 e de 1 a 50 faturas por página (o mesmo teto do diretório).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
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
