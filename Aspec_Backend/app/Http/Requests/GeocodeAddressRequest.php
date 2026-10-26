<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Valida o pedido de localização de uma morada no mapa (formulário de eventos do backoffice).
 */
class GeocodeAddressRequest extends FormRequest
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
     * Morada obrigatória até 255 caracteres (como a coluna address dos eventos); distrito opcional e existente.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'address' => ['required', 'string', 'max:255'],
            'location_id' => ['sometimes', 'nullable', 'uuid', 'exists:locations,id'],
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
            'address' => 'morada',
            'location_id' => 'distrito',
        ];
    }

    /**
     * Morada a pesquisar, sem espaços a mais.
     *
     * @return string Ex.: "Rua Augusta 1".
     */
    public function geocodingAddress(): string
    {
        return Str::squish((string) $this->validated('address'));
    }

    /**
     * Nome do distrito/região indicado, ou null se não foi indicado.
     *
     * @return string|null Ex.: "Lisboa".
     */
    public function districtName(): ?string
    {
        $locationId = $this->validated('location_id');

        if ($locationId === null) {
            return null;
        }

        return Location::query()->whereKey($locationId)->value('name');
    }
}
