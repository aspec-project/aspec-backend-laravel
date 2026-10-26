<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * O serviço de geocodificação não está disponível: sem resposta, resposta com erro ou com formato inválido,
 * ou limite de pedidos por segundo atingido.
 *
 * A mensagem é interna (nunca tem a morada) e não é devolvida ao cliente.
 */
class GeocodingUnavailable extends RuntimeException
{
    /**
     * @param  string  $message  Descrição interna da falha.
     * @param  int|null  $httpStatus  Código HTTP da resposta, ou null quando não houve resposta.
     */
    public function __construct(string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
