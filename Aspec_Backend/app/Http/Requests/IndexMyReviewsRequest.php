<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexMyReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'direction' => [
                'sometimes',
                'nullable',
                Rule::in(['all', 'received', 'written']),
            ],
            'status' => [
                'sometimes',
                'nullable',
                Rule::in(['all', 'visible', 'hidden', 'flagged']),
            ],
            'page' => ['sometimes', 'nullable', 'integer', 'between:1,10000'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,50'],
        ];
    }

    public function attributes(): array
    {
        return [
            'direction' => 'direção',
            'status' => 'estado',
            'page' => 'página',
            'per_page' => 'itens por página',
        ];
    }
}
