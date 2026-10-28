<?php

namespace App\Services\Geocoding;

use App\Contracts\Geocoder;
use InvalidArgumentException;

/**
 * Cria o fornecedor de geocodificação pelo nome (nominatim ou google).
 */
class GeocoderFactory
{
    /**
     * Devolve o fornecedor com o nome dado. A config da Google é lida a cada chamada.
     *
     * @param  string  $driver  Nome do fornecedor (services.geocoding.driver).
     * @return Geocoder Implementação do contrato para esse fornecedor.
     *
     * @throws InvalidArgumentException Se o fornecedor for desconhecido ou faltar a chave da Google;
     *                                  as mensagens não mostram os valores configurados.
     */
    public function make(string $driver): Geocoder
    {
        return match ($driver) {
            'nominatim' => app(NominatimGeocoder::class),
            'google' => $this->makeGoogleGeocoder(),
            default => throw new InvalidArgumentException('Driver de geocodificação desconhecido.'),
        };
    }

    /**
     * Cria o fornecedor Google com os valores de `services.google`.
     *
     * @return GoogleGeocoder Fornecedor Google com a chave, o timeout e os dias de cache configurados.
     *
     * @throws InvalidArgumentException Se faltar a chave da API.
     */
    private function makeGoogleGeocoder(): GoogleGeocoder
    {
        $config = config('services.google');

        if (blank($config['geocoding_key'] ?? null)) {
            throw new InvalidArgumentException('Falta a chave da API de geocodificação da Google.');
        }

        return new GoogleGeocoder(
            app(AddressCleaner::class),
            apiKey: (string) $config['geocoding_key'],
            timeout: (int) $config['timeout'],
            cacheDays: (int) $config['cache_days'],
        );
    }
}
