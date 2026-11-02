<?php

namespace App\Http\Requests;

use App\Rules\InternationalPhone;
use App\Rules\PortuguesePostalCode;
use App\Rules\UrlWithoutCredentials;
use App\Support\UrlNormalizer;
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
        if ($this->has('phone')) {
            $this->merge([
                'phone' => InternationalPhone::normalize(
                    $this->input('phone')
                ),
            ]);
        }

        if ($this->has('commercial_phone')) {
            $this->merge([
                'commercial_phone' => InternationalPhone::normalize(
                    $this->input('commercial_phone')
                ),
            ]);
        }

        if ($this->has('website_url')) {
            $this->merge([
                'website_url' => UrlNormalizer::withScheme(
                    $this->input('website_url')
                ),
            ]);
        }

        if ($this->has('postal_code')) {
            $this->merge([
                'postal_code' => PortuguesePostalCode::normalize(
                    $this->input('postal_code')
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
            'phone' => ['required', 'string', new InternationalPhone],
            'business_name' => 'required|string|max:255',
            'sector_id' => 'required|uuid|exists:sectors,id',
            'location_id' => 'required|uuid|exists:locations,id',
            'congregation' => 'required|string|max:255',
            'role_in_congregation' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'website_url' => [
                'nullable',
                'url:http,https',
                'max:255',
                new UrlWithoutCredentials,
            ],
            'address' => 'required|string|max:255',
            'postal_code' => ['required', 'string', new PortuguesePostalCode],
            'city' => 'required|string|max:100',
            'commercial_email' => 'required|string|email:rfc|max:255',
            'commercial_phone' => ['required', 'string', new InternationalPhone],
            'terms_accepted' => ['required', 'accepted'],

        ];
    }
}
