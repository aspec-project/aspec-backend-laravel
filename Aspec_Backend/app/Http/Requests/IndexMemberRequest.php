<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexMemberRequest extends FormRequest
{
    /**
     * Diretório público: qualquer pessoa pode pesquisar, autenticada ou não.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Filtros da lista: texto (até 100 caracteres), setor e distrito existentes, avaliação mínima
     * de 1 a 5 (aceita decimais) e paginação com no máximo 50 resultados por página.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:100',
            'sector_id' => 'nullable|uuid|exists:sectors,id',
            'location_id' => 'nullable|uuid|exists:locations,id',
            'min_rating' => 'nullable|numeric|between:1,5',
            'page' => 'nullable|integer|between:1,10000',
            'per_page' => 'nullable|integer|between:1,50',
        ];
    }
}
