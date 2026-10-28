<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representação JSON do local encontrado: coordenadas só com o Nominatim, `place_id` só com a Google,
 * a atribuição do fornecedor e se é aproximado.
 */
class GeocodingResultResource extends JsonResource
{
    /**
     * Atribuição exigida pela licença ODbL dos dados do OpenStreetMap (resultados do Nominatim).
     */
    public const OSM_ATTRIBUTION = '© OpenStreetMap contributors';

    /**
     * Atribuição exigida pelas políticas da Google Maps Platform para resultados da Geocoding API.
     */
    public const GOOGLE_ATTRIBUTION = 'Google Maps';

    /**
     * Devolve sempre as mesmas 6 chaves (latitude, longitude, nome do local, atribuição, se é aproximado e `place_id`),
     * sem nenhum campo bruto do fornecedor; a atribuição é a da Google quando o resultado tem `place_id`.
     *
     * @param  Request  $request  Pedido HTTP atual.
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'latitude' => $this->resource->latitude,
            'longitude' => $this->resource->longitude,
            'display_name' => $this->resource->displayName,
            'attribution' => $this->resource->placeId !== null ? self::GOOGLE_ATTRIBUTION : self::OSM_ATTRIBUTION,
            'approximate' => $this->resource->approximate,
            'place_id' => $this->resource->placeId,
        ];
    }
}
