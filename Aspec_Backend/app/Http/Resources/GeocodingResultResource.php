<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representação JSON de uma morada localizada: só as coordenadas, o nome do local e a atribuição do OpenStreetMap.
 */
class GeocodingResultResource extends JsonResource
{
    /**
     * Texto de atribuição exigido pela licença ODbL dos dados do OpenStreetMap.
     */
    public const ATTRIBUTION = '© OpenStreetMap contributors';

    /**
     * Devolve a latitude, a longitude, o nome do local e a atribuição (nenhum campo bruto do Nominatim).
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
            'attribution' => self::ATTRIBUTION,
        ];
    }
}
