<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HideAdminReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'justification' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'justification' => 'justificação',
        ];
    }
}
