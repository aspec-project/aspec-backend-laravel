<?php

namespace App\Services\Geocoding;

use App\Contracts\Geocoder;
use App\Exceptions\GeocodingUnavailable;
use App\Services\Geocoding\Data\GeocodingResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Geocodificação com o Nominatim (OpenStreetMap), dentro da política do servidor público:
 * User-Agent identificável, no máximo 1 pedido por segundo para toda a aplicação e cache dos resultados.
 */
class NominatimGeocoder implements Geocoder
{
    private const RATE_LIMIT_KEY = 'nominatim';

    /**
     * Procura as coordenadas de uma morada, primeiro na cache e depois no Nominatim.
     *
     * @param  string  $query  Morada em texto livre.
     * @return GeocodingResult|null null quando a morada não é encontrada.
     *
     * @throws GeocodingUnavailable
     */
    public function geocode(string $query): ?GeocodingResult
    {
        $query = Str::squish($query);
        $cacheKey = 'geocoding:'.sha1(mb_strtolower($query));

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return GeocodingResult::fromArray($cached);
        }

        if (RateLimiter::hit(self::RATE_LIMIT_KEY, 1) > 1) {
            throw new GeocodingUnavailable('Limite de 1 pedido por segundo ao Nominatim atingido.');
        }

        $result = $this->search($query);

        if ($result !== null) {
            Cache::put($cacheKey, $result->toArray(), now()->addDays((int) config('services.nominatim.cache_days')));
        }

        return $result;
    }

    /**
     * Faz o pedido ao Nominatim e converte a primeira ocorrência num resultado.
     *
     * @param  string  $query  Morada já normalizada.
     * @return GeocodingResult|null null quando o Nominatim não encontra a morada.
     *
     * @throws GeocodingUnavailable
     */
    private function search(string $query): ?GeocodingResult
    {
        try {
            $response = $this->client()->get('/search', $this->parameters($query));
        } catch (ConnectionException) {
            throw new GeocodingUnavailable('Sem resposta do Nominatim.');
        }

        if (! $response->successful()) {
            throw new GeocodingUnavailable('O Nominatim respondeu com erro.', $response->status());
        }

        $places = $response->json();

        if (! is_array($places) || ! array_is_list($places)) {
            throw new GeocodingUnavailable('Resposta do Nominatim com formato inválido.', $response->status());
        }

        if ($places === []) {
            return null;
        }

        return $this->toResult($places[0]);
    }

    /**
     * Cliente HTTP com o URL base, o User-Agent que identifica a aplicação e timeouts curtos, sem novas tentativas.
     *
     * @return PendingRequest Pedido pronto a enviar.
     */
    private function client(): PendingRequest
    {
        $contactEmail = config('services.nominatim.contact_email');
        $userAgent = config('app.name').'/1.0'.(filled($contactEmail) ? " ({$contactEmail})" : '');

        return Http::baseUrl((string) config('services.nominatim.base_url'))
            ->withUserAgent($userAgent)
            ->acceptJson()
            ->timeout((int) config('services.nominatim.timeout'))
            ->connectTimeout(3);
    }

    /**
     * Parâmetros da pesquisa: só o melhor resultado, em português e restrito aos países configurados.
     *
     * @param  string  $query  Morada já normalizada.
     * @return array<string, string|int> Parâmetros da query string.
     */
    private function parameters(string $query): array
    {
        $parameters = [
            'q' => $query,
            'format' => 'jsonv2',
            'limit' => 1,
            'countrycodes' => (string) config('services.nominatim.country_codes'),
            'accept-language' => 'pt-PT',
            'addressdetails' => 0,
        ];

        $contactEmail = config('services.nominatim.contact_email');

        if (filled($contactEmail)) {
            $parameters['email'] = (string) $contactEmail;
        }

        return $parameters;
    }

    /**
     * Converte uma ocorrência do Nominatim num resultado com coordenadas de 7 casas decimais.
     *
     * @param  mixed  $place  Primeira ocorrência da resposta.
     * @return GeocodingResult Coordenadas e nome do local.
     *
     * @throws GeocodingUnavailable Se faltarem as coordenadas ou o nome, ou estiverem fora dos limites.
     */
    private function toResult(mixed $place): GeocodingResult
    {
        $lat = is_array($place) ? ($place['lat'] ?? null) : null;
        $lon = is_array($place) ? ($place['lon'] ?? null) : null;
        $displayName = is_array($place) ? ($place['display_name'] ?? null) : null;

        if (! is_numeric($lat) || ! is_numeric($lon) || ! is_string($displayName)
            || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
            throw new GeocodingUnavailable('Resposta do Nominatim com formato inválido.');
        }

        return new GeocodingResult(round((float) $lat, 7), round((float) $lon, 7), $displayName);
    }
}
