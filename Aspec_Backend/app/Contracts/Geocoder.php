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
     * Procura as coordenadas de uma morada com uma só pesquisa, depois de a limpar (sem andar, lote, loja…).
     *
     * @param  string  $query  Morada em texto livre (ex.: "Rua Augusta 1, Lisboa").
     * @return GeocodingResult|null null quando a morada não é encontrada.
     *
     * @throws GeocodingUnavailable Se o serviço não responder, responder com erro ou o limite de pedidos for atingido.
     */
    public function geocode(string $query): ?GeocodingResult;

    /**
     * Procura as coordenadas de uma morada escrita pelo admin, com pesquisas alternativas e verificação do resultado.
     *
     * @param  string  $address  Morada tal como foi escrita (é limpa antes de pesquisar).
     * @param  string|null  $district  Nome do distrito/região (tabela locations), ou null.
     * @return GeocodingResult|null null quando nenhuma tentativa encontra a morada.
     *
     * @throws GeocodingUnavailable Se o serviço não responder, responder com erro ou o limite de pedidos for atingido.
     */
    public function locate(string $address, ?string $district = null): ?GeocodingResult;
}
