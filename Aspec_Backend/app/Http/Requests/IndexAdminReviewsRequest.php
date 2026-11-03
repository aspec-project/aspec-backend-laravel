<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAdminReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['all', 'flagged', 'visible', 'hidden']),
            ],
            'page' => ['sometimes', 'nullable', 'integer', 'between:1,10000'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,50'],
        ];
    }

    public function attributes(): array
    {
        return [
            'status' => 'estado da avaliação',
            'page' => 'página',
            'per_page' => 'itens por página',
        ];
    }
}
