<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LimitsCurrentPasswordAttempts;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberProfileRequest extends FormRequest
{
    use LimitsCurrentPasswordAttempts;

    /**
     * A autorização (conta Active) é feita pelo middleware account.active da rota.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Regras da atualização parcial do perfil: campo ausente fica inalterado,
     * campos obrigatórios usam sometimes|required e mudar o email exige a password atual.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'business_name' => 'sometimes|required|string|max:255',
            'congregation' => 'sometimes|required|string|max:255',
            'role_in_congregation' => 'sometimes|required|string|max:255',
            'sector_id' => 'sometimes|required|uuid|exists:sectors,id',
            'location_id' => 'sometimes|required|uuid|exists:locations,id',
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users')->ignore($this->user()->id),
            ],
            'current_password' => 'bail|required_with:email|string|current_password:sanctum',
            'phone' => 'sometimes|required|string|max:20',
            'description' => 'nullable|string|max:1000',
            'website_url' => 'nullable|url:http,https|max:255',
            'commercial_contacts' => 'nullable|string|max:1000',
            'address' => 'nullable|string|max:255',

            'business_hours' => 'sometimes|array|max:7',
            'business_hours.*.week_day_id' => 'required|integer|distinct|exists:week_days,id',
            'business_hours.*.open_time' => 'required|date_format:H:i',
            'business_hours.*.close_time' => 'required|date_format:H:i|after:business_hours.*.open_time',

            'social_links' => 'sometimes|array',
            'social_links.*.platform_id' => 'required|uuid|distinct:ignore_case|exists:social_platforms,id',
            'social_links.*.url' => 'required|url:http,https|max:255',
        ];
    }
}
