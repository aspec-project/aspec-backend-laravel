<?php

namespace App\Http\Requests;

use App\Models\ReviewReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberReviewReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => [
                'required',
                'string',
                Rule::in(ReviewReport::REASONS),
            ],
            'comment' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'reason' => 'motivo',
            'comment' => 'comentário',
        ];
    }
}
