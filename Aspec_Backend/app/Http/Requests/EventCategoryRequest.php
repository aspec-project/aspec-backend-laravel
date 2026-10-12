<?php

namespace App\Http\Requests;

use App\Models\EventCategory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida a criação e a edição de categorias de eventos.
 */
class EventCategoryRequest extends FormRequest
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
     * Nome obrigatório, até 100 caracteres e único sem distinguir maiúsculas (ao editar, ignora a própria
     * categoria); cor opcional no formato #RRGGBB.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'bail',
                'required',
                'string',
                'max:100',
                function (string $attribute, mixed $value, Closure $fail) {
                    $exists = EventCategory::query()
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])
                        ->when($this->route('eventCategory'), fn ($query, $category) => $query->whereKeyNot($category->id))
                        ->exists();

                    if ($exists) {
                        $fail('Já existe uma categoria com este nome.');
                    }
                },
            ],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
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
            'name' => 'nome',
            'color' => 'cor',
        ];
    }

    /**
     * Mensagem própria para a cor fora do formato #RRGGBB.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'color.regex' => 'A cor tem de estar no formato #RRGGBB (ex.: #8A7043).',
        ];
    }
}
