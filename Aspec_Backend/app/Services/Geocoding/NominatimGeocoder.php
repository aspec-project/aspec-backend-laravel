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
use Illuminate\Support\Sleep;

/**
 * Geocodificação com o Nominatim (OpenStreetMap), dentro da política do servidor público:
 * User-Agent identificável, no máximo 1 pedido por segundo para toda a aplicação e cache dos resultados.
 */
class NominatimGeocoder implements Geocoder
{
    private const RATE_LIMIT_KEY = 'nominatim';

    /**
     * Tempo máximo (segundos) da cascata: uma tentativa alternativa só começa se, depois da espera de 1 s, ainda couber neste prazo.
     */
    private const CASCADE_DEADLINE_SECONDS = 6;

    /**
     * Timeout (segundos) dos pedidos das tentativas alternativas (2.ª e 3.ª).
     */
    private const FALLBACK_TIMEOUT_SECONDS = 2;

    /**
     * @param  AddressCleaner  $cleaner  Limpa a morada e prepara as pesquisas alternativas.
     * @param  ResultVerifier  $verifier  Diz se o local encontrado é aproximado.
     */
    public function __construct(
        private readonly AddressCleaner $cleaner,
        private readonly ResultVerifier $verifier,
    ) {}

    /**
     * Procura as coordenadas de uma morada com uma só pesquisa (depois de a limpar), primeiro na cache e depois no Nominatim.
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

        $cacheKey = 'geocoding:'.sha1(mb_strtolower($cleaned));
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return GeocodingResult::fromArray($cached);
        }

        $this->takeRateLimitSlot();
        $place = $this->search($cleaned, (int) config('services.nominatim.timeout'));

        if ($place === null) {
            return null;
        }

        $result = $this->toResult($place, $this->isApproximate($place, $cleaned, null));
        $this->remember($cacheKey, $result);

        return $result;
    }

    /**
     * Procura as coordenadas com até 3 pesquisas alternativas (1 s entre elas) e devolve o primeiro local verificado;
     * se nenhum for verificado, devolve o primeiro encontrado, marcado como aproximado.
     * As tentativas alternativas só começam dentro do prazo total da cascata e têm um timeout mais curto.
     * Se a cascata for cortada (prazo esgotado ou erro do serviço) depois de já haver um local aproximado,
     * devolve esse local sem o guardar na cache.
     *
     * @param  string  $address  Morada tal como foi escrita.
     * @param  string|null  $district  Nome do distrito/região, ou null.
     * @return GeocodingResult|null null quando todas as tentativas terminam sem encontrar a morada.
     *
     * @throws GeocodingUnavailable Se o serviço falhar, o limite de pedidos for atingido ou o prazo se esgotar antes de haver algum local.
     */
    public function locate(string $address, ?string $district = null): ?GeocodingResult
    {
        $cleaned = $this->cleaner->clean($address);

        if ($cleaned === '') {
            return null;
        }

        $cacheKey = 'geocoding:locate:'.sha1(mb_strtolower($cleaned.'|'.($district ?? '')));
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return GeocodingResult::fromArray($cached);
        }

        $deadline = now()->addSeconds(self::CASCADE_DEADLINE_SECONDS);
        $fallback = null;
        $complete = true;

        foreach ($this->cleaner->attempts($address, $district) as $index => $attempt) {
            if ($index > 0) {
                if (now()->addSecond()->greaterThan($deadline)) {
                    if ($fallback === null) {
                        throw new GeocodingUnavailable('Tempo total das pesquisas alternativas esgotado.');
                    }

                    $complete = false;
                    break;
                }

                Sleep::for(1)->second();
            }

            $timeout = $index === 0 ? (int) config('services.nominatim.timeout') : self::FALLBACK_TIMEOUT_SECONDS;

            try {
                $this->takeRateLimitSlot();
                $place = $this->search($attempt, $timeout);
            } catch (GeocodingUnavailable $e) {
                if ($fallback === null) {
                    throw $e;
                }

                $complete = false;
                break;
            }

            if ($place === null) {
                continue;
            }

            if (! $this->isApproximate($place, $cleaned, $district)) {
                $result = $this->toResult($place, false);
                $this->remember($cacheKey, $result);

                return $result;
            }

            $fallback ??= $this->toResult($place, true);
        }

        if ($fallback !== null && $complete) {
            $this->remember($cacheKey, $fallback);
        }

        return $fallback;
    }

    /**
     * Ocupa o lugar do limite global de 1 pedido por segundo ao Nominatim.
     *
     * @throws GeocodingUnavailable Se o lugar deste segundo já estiver ocupado.
     */
    private function takeRateLimitSlot(): void
    {
        if (RateLimiter::hit(self::RATE_LIMIT_KEY, 1) > 1) {
            throw new GeocodingUnavailable('Limite de 1 pedido por segundo ao Nominatim atingido.');
        }
    }

    /**
     * Diz se uma ocorrência é aproximada, comparando-a com a rua da morada limpa e com o distrito.
     *
     * @param  array<string, mixed>  $place  Ocorrência validada do Nominatim.
     * @param  string  $cleaned  Morada já limpa.
     * @param  string|null  $district  Nome do distrito/região, ou null.
     * @return bool true se a rua ou o distrito não corresponderem.
     */
    private function isApproximate(array $place, string $cleaned, ?string $district): bool
    {
        $details = is_array($place['address'] ?? null) ? $place['address'] : [];

        return $this->verifier->isApproximate($details, $this->cleaner->street($cleaned), $district);
    }

    /**
     * Guarda um resultado na cache durante os dias configurados.
     *
     * @param  string  $cacheKey  Chave da cache (com sha1, sem a morada em texto).
     * @param  GeocodingResult  $result  Resultado a guardar.
     */
    private function remember(string $cacheKey, GeocodingResult $result): void
    {
        Cache::put($cacheKey, $result->toArray(), now()->addDays((int) config('services.nominatim.cache_days')));
    }

    /**
     * Faz o pedido ao Nominatim e devolve a primeira ocorrência, já validada.
     *
     * @param  string  $query  Texto a pesquisar.
     * @param  int  $timeout  Timeout do pedido, em segundos.
     * @return array<string, mixed>|null null quando o Nominatim não encontra a morada.
     *
     * @throws GeocodingUnavailable
     */
    private function search(string $query, int $timeout): ?array
    {
        try {
            $response = $this->client($timeout)->get('/search', $this->parameters($query));
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

        return $this->validatePlace($places[0]);
    }

    /**
     * Cliente HTTP com o URL base, o User-Agent que identifica a aplicação e timeouts curtos, sem novas tentativas.
     *
     * @param  int  $timeout  Timeout do pedido, em segundos (o de ligação nunca passa de 3).
     * @return PendingRequest Pedido pronto a enviar.
     */
    private function client(int $timeout): PendingRequest
    {
        $contactEmail = config('services.nominatim.contact_email');
        $userAgent = config('app.name').'/1.0'.(filled($contactEmail) ? " ({$contactEmail})" : '');

        return Http::baseUrl((string) config('services.nominatim.base_url'))
            ->withUserAgent($userAgent)
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout(min(3, $timeout));
    }

    /**
     * Parâmetros da pesquisa: só o melhor resultado, com os detalhes da morada, em português e restrito aos países configurados.
     *
     * @param  string  $query  Texto a pesquisar.
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
            'addressdetails' => 1,
        ];

        $contactEmail = config('services.nominatim.contact_email');

        if (filled($contactEmail)) {
            $parameters['email'] = (string) $contactEmail;
        }

        return $parameters;
    }

    /**
     * Confirma que a ocorrência tem coordenadas válidas e o nome do local.
     *
     * @param  mixed  $place  Primeira ocorrência da resposta.
     * @return array<string, mixed> A mesma ocorrência.
     *
     * @throws GeocodingUnavailable Se faltarem as coordenadas ou o nome, ou estiverem fora dos limites.
     */
    private function validatePlace(mixed $place): array
    {
        $lat = is_array($place) ? ($place['lat'] ?? null) : null;
        $lon = is_array($place) ? ($place['lon'] ?? null) : null;
        $displayName = is_array($place) ? ($place['display_name'] ?? null) : null;

        if (! is_numeric($lat) || ! is_numeric($lon) || ! is_string($displayName)
            || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
            throw new GeocodingUnavailable('Resposta do Nominatim com formato inválido.');
        }

        return $place;
    }

    /**
     * Converte uma ocorrência validada num resultado com coordenadas de 7 casas decimais.
     *
     * @param  array<string, mixed>  $place  Ocorrência validada por validatePlace().
     * @param  bool  $approximate  true se a rua ou o distrito não corresponderem ao pedido.
     * @return GeocodingResult Coordenadas, nome do local e se é aproximado.
     */
    private function toResult(array $place, bool $approximate): GeocodingResult
    {
        return new GeocodingResult(
            round((float) $place['lat'], 7),
            round((float) $place['lon'], 7),
            (string) $place['display_name'],
            $approximate,
        );
    }
}
