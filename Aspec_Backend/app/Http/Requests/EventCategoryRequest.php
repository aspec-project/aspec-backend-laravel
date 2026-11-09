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
     * Nome obrigatório, até 30 caracteres e único sem distinguir maiúsculas; cor obrigatória no formato #RRGGBB,
     * da paleta e diferente da das outras categorias, também sem distinguir maiúsculas (ao editar, as regras de
     * unicidade ignoram a própria categoria). Ao criar com a paleta toda em uso, o erro diz como libertar uma cor.
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
                'max:30',
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
            'color' => [
                'bail',
                'required',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! array_key_exists(strtoupper($value), config('event_categories.palette'))) {
                        $fail('A cor escolhida não faz parte da paleta de cores disponíveis.');
                    }
                },
                function (string $attribute, mixed $value, Closure $fail) {
                    $exists = EventCategory::query()
                        ->where('color', strtoupper($value))
                        ->when($this->route('eventCategory'), fn ($query, $category) => $query->whereKeyNot($category->id))
                        ->exists();

                    if ($exists) {
                        $fail($this->isPaletteExhausted()
                            ? 'Todas as cores disponíveis já estão em uso. Apague um tipo de evento sem eventos para libertar uma cor.'
                            : 'Já existe uma categoria com esta cor.');
                    }
                },
            ],
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

    /**
     * Indica se se está a criar uma categoria com todas as cores da paleta já em uso.
     */
    private function isPaletteExhausted(): bool
    {
        if ($this->route('eventCategory')) {
            return false;
        }

        $palette = array_keys(config('event_categories.palette'));

        return EventCategory::query()->whereIn('color', $palette)->count() >= count($palette);
    }
}
