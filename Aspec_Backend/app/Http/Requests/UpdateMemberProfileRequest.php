<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LimitsCurrentPasswordAttempts;
use App\Rules\PortuguesePhone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberProfileRequest extends FormRequest
{
    use LimitsCurrentPasswordAttempts {
        prepareForValidation as limitCurrentPasswordAttempts;
    }

    /**
     * A autorização (conta Active) é feita pelo middleware account.active da rota.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Primeiro o bloqueio da password atual (trait); depois normaliza o telefone
     * para se guardar sempre no mesmo formato (9 dígitos, sem espaços nem +351).
     */
    protected function prepareForValidation(): void
    {
        $this->limitCurrentPasswordAttempts();

        if ($this->has('phone')) {
            $this->merge(['phone' => PortuguesePhone::normalize($this->input('phone'))]);
        }
    }

    /**
     * Regras da atualização parcial do perfil: campo ausente fica inalterado,
     * campos obrigatórios usam sometimes|required e mudar o email exige a password atual.
     * A morada é obrigatória por decisão da equipa (6/10), mas a coluna continua nullable:
     * há linhas antigas sem morada e o anonymizeAndDelete() limpa-a.
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
            'phone' => ['sometimes', 'required', 'string', new PortuguesePhone],
            'description' => 'nullable|string|max:1000',
            'website_url' => 'nullable|url:http,https|max:255',
            'commercial_contacts' => 'nullable|string|max:1000',
            'address' => 'sometimes|required|string|max:255',

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
