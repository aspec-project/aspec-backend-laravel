<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * O estado da subscrição não permite a operação pedida (ex.: cancelar com um pagamento em
 * atraso, retomar sem cancelamento agendado). A mensagem é a que o membro vê; o controller
 * converte-a num 409.
 */
class SubscriptionStateConflictException extends RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
