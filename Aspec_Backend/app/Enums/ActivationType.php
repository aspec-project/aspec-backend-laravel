<?php

namespace App\Enums;

/**
 * Tipo de pagamento que uma conta pode iniciar pelo link do email (User::activationType()).
 */
enum ActivationType: string
{
    /** Conta Approved: primeira subscrição, com período experimental. */
    case Activation = 'activation';

    /** Conta Inactive por falta de pagamento: nova subscrição, sem período experimental. */
    case Reactivation = 'reactivation';
}
