<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida os filtros da lista de utilizadores do backoffice (GET /api/admin/users).
 */
class IndexAdminUserRequest extends FormRequest
{
    /**
     * A autorização é feita pelo middleware admin da rota.
     *
     * @return bool Sempre true.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Estado existente em account_statuses, filtro de bloqueados só com o estado Inactive,
     * pesquisa até 255 caracteres, opção de mostrar administradores e página a partir de 1.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', 'exists:account_statuses,name'],
            'blocked' => ['sometimes', 'boolean', 'prohibited_unless:status,Inactive'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'include_admins' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * Mensagem própria para o filtro de bloqueados usado sem o estado Inactive.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'blocked.prohibited_unless' => 'O filtro de bloqueados só pode ser usado com o estado Inactive.',
        ];
    }

    /**
     * Nomes dos campos usados nas mensagens de erro.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status' => 'estado',
            'blocked' => 'bloqueados',
            'include_admins' => 'mostrar administradores',
            'page' => 'página',
        ];
    }
}
