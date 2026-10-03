<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LimitsCurrentPasswordAttempts;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePasswordRequest extends FormRequest
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
     * Exige a password atual correta e a nova duas vezes (mín. 8, como no registo),
     * diferente da atual.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => 'bail|required|string|current_password:sanctum',
            'password' => 'required|string|min:8|confirmed|different:current_password',
        ];
    }
}
