<?php

namespace App\Services\Geocoding;

use App\Contracts\Geocoder;
use App\Exceptions\GeocodingUnavailable;
use App\Services\Geocoding\Data\GeocodingResult;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Geocodificação com a Google Geocoding API, dentro dos termos da Google:
 * devolve e guarda em cache só o place_id, a morada formatada e se o local é aproximado (nunca latitude/longitude),
 * com a cache limitada a 30 dias. A chave da API nunca aparece em logs nem em exceções.
 */
final class GoogleGeocoder implements Geocoder
{
    /**
     * Endereço fixo no código: nenhuma configuração consegue mandar a chave para outro servidor.
     */
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /**
     * Tipos de localização que a Google dá como precisos (número de porta ou interpolação na rua).
     */
    private const PRECISE_LOCATION_TYPES = ['ROOFTOP', 'RANGE_INTERPOLATED'];

    /**
     * Estados da Google que tornam o serviço indisponível e ficam registados (chave errada ou quota esgotada).
     */
    private const LOGGED_UNAVAILABLE_STATUSES = ['OVER_QUERY_LIMIT', 'OVER_DAILY_LIMIT', 'REQUEST_DENIED'];

    /**
     * Máximo de dias em cache permitido pelos termos da Google.
     */
    private const MAX_CACHE_DAYS = 30;

    /**
     * @param  AddressCleaner  $cleaner  Limpa a morada antes de pesquisar.
     * @param  string  $apiKey  Chave da Geocoding API.
     * @param  int  $timeout  Timeout do pedido, em segundos (o de ligação nunca passa de 3).
     * @param  int  $cacheDays  Dias em cache dos resultados encontrados (no máximo 30).
     */
    public function __construct(
        private readonly AddressCleaner $cleaner,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly int $timeout,
        private readonly int $cacheDays,
    ) {}

    /**
     * Procura uma morada com uma só pesquisa (depois de a limpar), primeiro na cache e depois na Google.
     *
     * @param  string  $query  Morada em texto livre.
     * @return GeocodingResult|null null quando a morada não é encontrada.
     *
     * @throws GeocodingUnavailable
     */
    public function geocode(string $query): ?GeocodingResult
    {
        $cleaned = $this->cleaner->clean($query);

        if ($cleaned === '') {
            return null;
        }

        return $this->remember('geocoding:google:'.sha1(mb_strtolower($cleaned)), $cleaned);
    }

    /**
     * Procura uma morada escrita pelo admin com uma só pesquisa (morada limpa + distrito), primeiro na cache e depois na Google.
     *
     * @param  string  $address  Morada tal como foi escrita.
     * @param  string|null  $district  Nome do distrito/região, ou null.
     * @return GeocodingResult|null null quando a morada não é encontrada.
     *
     * @throws GeocodingUnavailable
     */
    public function locate(string $address, ?string $district = null): ?GeocodingResult
    {
        $cleaned = $this->cleaner->clean($address);

        if ($cleaned === '') {
            return null;
        }

        $cacheKey = 'geocoding:google:locate:'.sha1(mb_strtolower($cleaned.'|'.($district ?? '')));
        $query = filled($district) ? "{$cleaned}, {$district}" : $cleaned;

        return $this->remember($cacheKey, $query);
    }

    /**
     * Devolve o resultado em cache ou pesquisa na Google e guarda o resultado encontrado (o "não encontrado" não fica em cache).
     *
     * @param  string  $cacheKey  Chave da cache (com sha1, sem a morada em texto).
     * @param  string  $query  Texto a pesquisar.
     * @return GeocodingResult|null null quando a morada não é encontrada.
     *
     * @throws GeocodingUnavailable
     */
    private function remember(string $cacheKey, string $query): ?GeocodingResult
    {
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return GeocodingResult::fromArray($cached);
        }

        $result = $this->search($query);

        if ($result !== null) {
            Cache::put($cacheKey, $result->toArray(), now()->addDays(min(self::MAX_CACHE_DAYS, $this->cacheDays)));
        }

        return $result;
    }

    /**
     * Faz o pedido à Google e converte a resposta num resultado sem coordenadas.
     *
     * @param  string  $query  Texto a pesquisar.
     * @return GeocodingResult|null null quando a Google não encontra a morada (ZERO_RESULTS ou INVALID_REQUEST).
     *
     * @throws GeocodingUnavailable Com mensagem fixa e sem a exceção original, que tem o URL com a chave.
     */
    private function search(string $query): ?GeocodingResult
    {
        try {
            $response = Http::acceptJson()
                ->withoutRedirecting()
                ->timeout($this->timeout)
                ->connectTimeout(min(3, $this->timeout))
                ->get(self::ENDPOINT, [
                    'address' => $query,
                    'key' => $this->apiKey,
                    'region' => 'pt',
                    'components' => 'country:PT',
                    'language' => 'pt-PT',
                ]);
        } catch (ConnectionException|RequestException|TransferException) {
            throw new GeocodingUnavailable('Sem resposta da Google.');
        }

        if (! $response->successful()) {
            throw new GeocodingUnavailable('A Google respondeu com erro.', $response->status());
        }

        $data = $response->json();
        $status = is_array($data) ? ($data['status'] ?? null) : null;

        if ($status === 'ZERO_RESULTS') {
            return null;
        }

        if ($status === 'INVALID_REQUEST') {
            Log::warning('Pedido de geocodificação recusado pela Google.', ['status' => $status]);

            return null;
        }

        if (in_array($status, self::LOGGED_UNAVAILABLE_STATUSES, true)) {
            Log::warning('Geocodificação da Google indisponível.', ['status' => $status]);

            throw new GeocodingUnavailable('A Google recusou o pedido.');
        }

        if ($status === 'UNKNOWN_ERROR') {
            throw new GeocodingUnavailable('Erro temporário da Google.');
        }

        if ($status !== 'OK' || ! is_array($data['results'] ?? null)) {
            throw new GeocodingUnavailable('Resposta da Google com formato inválido.');
        }

        $result = $this->validateResult($data['results'][0] ?? null);

        return new GeocodingResult(
            null,
            null,
            $result['formatted_address'],
            $this->isApproximate($result),
            $result['place_id'],
        );
    }

    /**
     * Confirma que o primeiro resultado tem um place_id com o alfabeto e o tamanho da Google e uma morada formatada.
     *
     * @param  mixed  $result  Primeiro resultado da resposta.
     * @return array<string, mixed> O mesmo resultado.
     *
     * @throws GeocodingUnavailable Se o place_id ou a morada formatada forem inválidos.
     */
    private function validateResult(mixed $result): array
    {
        $placeId = is_array($result) ? ($result['place_id'] ?? null) : null;
        $address = is_array($result) ? ($result['formatted_address'] ?? null) : null;

        if (! is_string($placeId) || ! preg_match('/^[A-Za-z0-9_-]{1,512}$/D', $placeId)
            || ! is_string($address) || $address === '') {
            throw new GeocodingUnavailable('Resposta da Google com formato inválido.');
        }

        return $result;
    }

    /**
     * Diz se o resultado é aproximado: correspondência parcial ou tipo de localização que não seja preciso.
     *
     * @param  array<string, mixed>  $result  Resultado validado por validateResult().
     * @return bool true quando o admin deve confirmar o local.
     */
    private function isApproximate(array $result): bool
    {
        return ($result['partial_match'] ?? false) === true
            || ! in_array($result['geometry']['location_type'] ?? null, self::PRECISE_LOCATION_TYPES, true);
    }
}
