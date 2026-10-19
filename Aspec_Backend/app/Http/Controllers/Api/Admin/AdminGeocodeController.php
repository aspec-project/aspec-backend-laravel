<?php

namespace App\Http\Controllers\Api\Admin;

use App\Contracts\Geocoder;
use App\Exceptions\GeocodingUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\GeocodeAddressRequest;
use App\Http\Resources\GeocodingResultResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Localização de moradas no mapa para o formulário de eventos do backoffice.
 */
class AdminGeocodeController extends Controller
{
    /**
     * Devolve as coordenadas da morada pedida (com o distrito, quando indicado).
     *
     * @param  GeocodeAddressRequest  $request  Pedido com a morada e o distrito opcional.
     * @param  Geocoder  $geocoder  Serviço de geocodificação ligado no AppServiceProvider.
     * @return JsonResponse 200 com as coordenadas, 404 se a morada não for encontrada ou 503 se o serviço estiver indisponível.
     */
    public function __invoke(GeocodeAddressRequest $request, Geocoder $geocoder): JsonResponse
    {
        try {
            $result = $geocoder->geocode($request->geocodingQuery());
        } catch (GeocodingUnavailable) {
            return $this->errorResponse(
                'O serviço de localização não está disponível de momento. Tente novamente dentro de instantes.',
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        if ($result === null) {
            return $this->errorResponse(
                'Não foi possível encontrar esta morada no mapa. Verifique a morada e tente novamente.',
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->successResponse(new GeocodingResultResource($result), 'Morada localizada com sucesso.');
    }
}
