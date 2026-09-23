<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterMemberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'                 => 'required|string|max:255',
            'email'                => 'required|email|unique:users,email',
            'password'             => 'required|string|min:8',
            'phone'                => 'required|string|max:20',
            'business_name'        => 'required|string|max:255',
            'sector_id'            => 'required|uuid|exists:sectors,id', 
            'location_id'          => 'required|uuid|exists:locations,id',
            'congregation'         => 'required|string|max:255',
            'role_in_congregation' => 'required|string|max:255',
            'description'          => 'nullable|string|max:1000',
            'website_url'          => 'nullable|url|max:255',
            'address'              => 'nullable|string|max:255',
        ];
    }
}
