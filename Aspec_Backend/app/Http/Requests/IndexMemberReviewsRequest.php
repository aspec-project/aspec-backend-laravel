<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexMemberReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'sort' => ['sometimes', 'nullable', 'string', 'in:newest,oldest,highest,lowest'],
            'page' => ['sometimes', 'nullable', 'integer', 'between:1,10000'],
        ];
    }
}
