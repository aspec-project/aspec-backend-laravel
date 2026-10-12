<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * O serviço de faturação não entregou o PDF de uma fatura emitida (erro, sem ligação, ainda a gerar
 * ou resposta inválida).
 *
 * Tal como InvoiceIssuingFailed, a mensagem só tem o passo e o código HTTP e nunca encadeia a
 * exceção original: as do cliente HTTP trazem o URL com a `api_key`.
 */
class InvoicePdfUnavailable extends RuntimeException
{
    /**
     * @param  string  $step  Passo que falhou: request (pedido do PDF ao serviço) ou download (ficheiro).
     * @param  int|null  $httpStatus  Código HTTP da resposta, ou null quando não houve resposta.
     */
    public function __construct(
        string $message,
        public readonly string $step,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

    /**
     * O serviço respondeu com um erro ou com uma resposta que não serve (sem link https, sem PDF).
     */
    public static function rejected(string $step, ?int $status): self
    {
        $http = $status === null ? '' : ", HTTP {$status}";

        return new self("PDF da fatura indisponível (passo: {$step}{$http}).", $step, $status);
    }

    /**
     * Não houve resposta do serviço (timeout ou erro de ligação).
     */
    public static function unreachable(string $step): self
    {
        return new self("Sem ligação ao serviço de faturação para obter o PDF (passo: {$step}).", $step);
    }

    /**
     * O serviço continuou a gerar o PDF depois de todas as tentativas.
     */
    public static function stillGenerating(): self
    {
        return new self('O PDF da fatura ainda está a ser gerado pelo serviço de faturação.', 'request', 202);
    }
}
