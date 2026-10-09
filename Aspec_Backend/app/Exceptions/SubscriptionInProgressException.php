<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A conta já tem uma subscrição em curso (ou um Checkout pago à espera do webhook).
 * Lançada pelo SubscriptionService; o controller converte-a num 409.
 */
class SubscriptionInProgressException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Já existe uma subscrição em curso para esta conta.');
    }
}
