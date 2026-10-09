<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A conta deixou de poder pagar pelo link (ex.: bloqueada entre a validação do pedido e o lock).
 * Lançada pelo SubscriptionService; o controller converte-a num 409.
 */
class ActivationLinkNoLongerValidException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Este link já não é válido para esta conta.');
    }
}
