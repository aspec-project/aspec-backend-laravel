<?php

namespace App\Services\Geocoding\Data;

/**
 * Local encontrado para uma morada: coordenadas (só com o Nominatim), nome completo do local,
 * se é aproximado e o identificador do local na Google (só com a Google).
 */
final readonly class GeocodingResult
{
    /**
     * @param  float|null  $latitude  Latitude com 7 casas decimais, ou null quando o fornecedor não permite guardá-la (Google).
     * @param  float|null  $longitude  Longitude com 7 casas decimais, ou null quando o fornecedor não permite guardá-la (Google).
     * @param  string  $displayName  Nome completo do local encontrado.
     * @param  bool  $approximate  true quando a rua ou o distrito do local não correspondem ao pedido.
     * @param  string|null  $placeId  Identificador do local na Google (place_id), ou null com o Nominatim.
     */
    public function __construct(
        public ?float $latitude,
        public ?float $longitude,
        public string $displayName,
        public bool $approximate = false,
        public ?string $placeId = null,
    ) {}

    /**
     * Converte o resultado num array, a forma guardada na cache.
     *
     * @return array{latitude: float|null, longitude: float|null, display_name: string, approximate: bool, place_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'display_name' => $this->displayName,
            'approximate' => $this->approximate,
            'place_id' => $this->placeId,
        ];
    }

    /**
     * Reconstrói o resultado a partir do array guardado na cache (as entradas antigas, sem approximate, contam como verificadas;
     * sem place_id, ficam com placeId null).
     *
     * @param  array{latitude?: float|null, longitude?: float|null, display_name: string, approximate?: bool, place_id?: string|null}  $data  Array criado por toArray().
     * @return self Resultado equivalente ao original.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
            (string) $data['display_name'],
            (bool) ($data['approximate'] ?? false),
            isset($data['place_id']) ? (string) $data['place_id'] : null,
        );
    }
}
