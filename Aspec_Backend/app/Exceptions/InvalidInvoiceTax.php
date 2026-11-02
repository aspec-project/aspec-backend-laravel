<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Configuração do IVA da fatura inválida (taxa fora de 0–100, código de isenção mal formado,
 * isenção sem motivo ou código numa fatura com IVA).
 *
 * Exceção própria para o job de faturação a distinguir de outros erros: este é permanente (repetir
 * dá sempre o mesmo resultado até alguém corrigir o .env), por isso falha logo sem novas tentativas.
 */
class InvalidInvoiceTax extends InvalidArgumentException {}
