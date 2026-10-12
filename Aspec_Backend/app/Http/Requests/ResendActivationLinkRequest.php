<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ResendActivationLinkRequest extends FormRequest
{
    /**
     * Pedido público: quem não tem link válido não tem login, por isso não há autorização a verificar.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Só o email da conta que aguarda ativação ou reativação.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => 'required|string|email|max:255',
        ];
    }
}
