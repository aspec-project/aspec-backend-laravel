<?php

namespace App\Enums;

/**
 * Motivo pelo qual uma conta está Inactive (coluna users.inactive_reason).
 * Só o motivo Unpaid permite reativar a conta pagando; null numa conta Inactive
 * (contas antigas) é tratado como desconhecido, ou seja, não reativável.
 */
enum InactiveReason: string
{
    /** Candidatura recusada por um administrador. */
    case Rejected = 'rejected';

    /** Conta bloqueada por um administrador. */
    case Blocked = 'blocked';

    /** Fim do período de carência sem pagamento da subscrição. */
    case Unpaid = 'unpaid';

    /** Conta anonimizada e apagada (User::anonymizeAndDelete()). */
    case Deleted = 'deleted';
}
