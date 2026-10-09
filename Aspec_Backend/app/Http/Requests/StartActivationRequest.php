<?php

namespace App\Http\Requests;

use App\Enums\ActivationType;
use App\Models\User;
use App\Rules\PortugueseNif;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StartActivationRequest extends FormRequest
{
    /**
     * Sem login: a autorização é a assinatura do link, verificada pelo middleware signed da rota.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Tira os espaços do NIF (ex. "509 123 457"), para se guardar sempre com 9 dígitos.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nif'))) {
            $this->merge(['nif' => preg_replace('/\s+/', '', $this->input('nif'))]);
        }
    }

    /**
     * Dados de faturação, só na ativação. Na reativação o body é ignorado (usa os dados guardados,
     * para um link que escape não trocar o NIF das faturas); contas que não podem pagar ou que não
     * existem passam sem regras e o controller responde 404/409, nunca 422.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = User::with('accountStatus')->find($this->route('user'));

        if ($user?->activationType() !== ActivationType::Activation) {
            return [];
        }

        return [
            'billing_name' => 'required|string|max:255',
            'nif' => ['required', 'string', new PortugueseNif],
            'billing_address' => 'required|string|max:255',
            'billing_postal_code' => ['required', 'string', 'regex:/^\d{4}-\d{3}\z/'],
            'billing_city' => 'required|string|max:100',
        ];
    }
}
