<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A conta não tem subscrição (ex.: admin). Lançada pelo SubscriptionService; o controller
 * converte-a num 404.
 */
class SubscriptionNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Não existe subscrição.');
    }
}
