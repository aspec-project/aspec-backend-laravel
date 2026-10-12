<?php

namespace App\Enums;

/**
 * Estado da emissão de uma fatura (coluna invoices.status).
 */
enum InvoiceStatus: string
{
    /** Pagamento recebido; a fatura ainda não foi emitida (job em fila ou a repetir). */
    case Pending = 'pending';

    /** Fatura emitida e enviada ao cliente. */
    case Issued = 'issued';

    /** Fatura emitida (tem número fiscal) mas o envio por email falhou: reenviar à mão. */
    case IssuedNotSent = 'issued_not_sent';

    /** Não foi possível emitir (dados de faturação em falta ou tentativas esgotadas): emitir à mão. */
    case Failed = 'failed';
}
