<?php

namespace App\Contracts;

use App\Exceptions\GeocodingUnavailable;
use App\Services\Geocoding\Data\GeocodingResult;

/**
 * Converte moradas em coordenadas geográficas.
 */
interface Geocoder
{
    /**
     * Procura as coordenadas de uma morada.
     *
     * @param  string  $query  Morada em texto livre (ex.: "Rua Augusta 1, Lisboa").
     * @return GeocodingResult|null null quando a morada não é encontrada.
     *
     * @throws GeocodingUnavailable Se o serviço não responder, responder com erro ou o limite de pedidos for atingido.
     */
    public function geocode(string $query): ?GeocodingResult;
}
