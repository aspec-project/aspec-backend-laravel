<?php

namespace App\Http\Requests;

use App\Rules\InternationalPhone;
use App\Rules\PortuguesePhone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterMemberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => PortuguesePhone::normalize(
                $this->input('phone')
            ),
        ]);
        if ($this->has('commercial_phone')) {
            $this->merge([
                'commercial_phone' => InternationalPhone::normalize(
                    $this->input('commercial_phone')
                ),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'string', Password::default()],
            'phone' => ['required', 'string', new PortuguesePhone],
            'business_name' => 'required|string|max:255',
            'sector_id' => 'required|uuid|exists:sectors,id',
            'location_id' => 'required|uuid|exists:locations,id',
            'congregation' => 'required|string|max:255',
            'role_in_congregation' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'website_url' => 'nullable|url:http,https|max:255',
            'address' => 'required|string|max:255',
            'commercial_email' => 'required|string|email:rfc|max:255',
            'commercial_phone' => ['required', 'string', new InternationalPhone],
            'terms_accepted' => ['required', 'accepted'],

        ];
    }
}
