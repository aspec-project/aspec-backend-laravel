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
     * Mensagem do 200 quando o local encontrado não corresponde à rua ou ao distrito pedidos.
     */
    public const APPROXIMATE_MESSAGE = 'Foi encontrado um local aproximado. Confirme se corresponde à morada.';

    /**
     * Devolve o local encontrado para a morada pedida (com o distrito, quando indicado), indicando se é aproximado.
     *
     * @param  GeocodeAddressRequest  $request  Pedido com a morada e o distrito opcional.
     * @param  Geocoder  $geocoder  Serviço de geocodificação ligado no AppServiceProvider.
     * @return JsonResponse 200 com o local encontrado, 404 se a morada não for encontrada ou 503 se o serviço estiver indisponível.
     */
    public function __invoke(GeocodeAddressRequest $request, Geocoder $geocoder): JsonResponse
    {
        try {
            $result = $geocoder->locate($request->geocodingAddress(), $request->districtName());
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

        return $this->successResponse(
            new GeocodingResultResource($result),
            $result->approximate ? self::APPROXIMATE_MESSAGE : 'Morada localizada com sucesso.'
        );
    }
}
