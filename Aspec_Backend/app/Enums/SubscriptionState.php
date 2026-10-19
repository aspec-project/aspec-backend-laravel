<?php

namespace App\Enums;

/**
 * Estado da subscrição de um utilizador, derivado do estado da conta, do motivo de inatividade,
 * das datas de trial e de carência, do papel e do perfil (nunca guardado numa coluna).
 * Os casos estão pela ordem de precedência: ganha o primeiro cuja condição se verifica.
 */
enum SubscriptionState: string
{
    /** Candidatura à espera de aprovação (conta Pending). */
    case Pending = 'pending';

    /** Aprovada pelo administrador, à espera do cartão (conta Approved). */
    case AwaitingActivation = 'awaiting_activation';

    /** Conta bloqueada por um administrador. */
    case Blocked = 'blocked';

    /** Subscrição terminada por falta de pagamento ou cancelada pelo membro (reativável pelo link). */
    case Lapsed = 'lapsed';

    /** Restantes contas inativas: recusadas, anonimizadas ou antigas sem motivo. */
    case Inactive = 'inactive';

    /** Administrador técnico (sem perfil de membro): não paga subscrição. */
    case Exempt = 'exempt';

    /** Cobrança falhada, com acesso até ao fim do período de carência. */
    case Grace = 'grace';

    /** Período experimental ainda a decorrer. */
    case Trial = 'trial';

    /** Conta ativa fora do trial e da carência (não garante que exista subscrição paga). */
    case Active = 'active';

    /**
     * Nome do estado em pt-PT, para mostrar no backoffice.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::AwaitingActivation => 'Por ativar',
            self::Blocked => 'Bloqueado',
            self::Lapsed => 'Subscrição caída',
            self::Inactive => 'Inativo',
            self::Exempt => 'Isento',
            self::Grace => 'Em carência',
            self::Trial => 'Em trial',
            self::Active => 'Ativo',
        };
    }
}
