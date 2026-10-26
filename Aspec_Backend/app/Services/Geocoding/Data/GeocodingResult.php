<?php

namespace App\Services\Geocoding\Data;

/**
 * Coordenadas encontradas para uma morada, com o nome completo do local devolvido pelo serviço.
 */
final readonly class GeocodingResult
{
    /**
     * @param  float  $latitude  Latitude com 7 casas decimais.
     * @param  float  $longitude  Longitude com 7 casas decimais.
     * @param  string  $displayName  Nome completo do local encontrado.
     * @param  bool  $approximate  true quando a rua ou o distrito do local não correspondem ao pedido.
     */
    public function __construct(
        public float $latitude,
        public float $longitude,
        public string $displayName,
        public bool $approximate = false,
    ) {}

    /**
     * Converte o resultado num array, a forma guardada na cache.
     *
     * @return array{latitude: float, longitude: float, display_name: string, approximate: bool}
     */
    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'display_name' => $this->displayName,
            'approximate' => $this->approximate,
        ];
    }

    /**
     * Reconstrói o resultado a partir do array guardado na cache (as entradas antigas, sem approximate, contam como verificadas).
     *
     * @param  array{latitude: float, longitude: float, display_name: string, approximate?: bool}  $data  Array criado por toArray().
     * @return self Resultado equivalente ao original.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (float) $data['latitude'],
            (float) $data['longitude'],
            (string) $data['display_name'],
            (bool) ($data['approximate'] ?? false),
        );
    }
}
