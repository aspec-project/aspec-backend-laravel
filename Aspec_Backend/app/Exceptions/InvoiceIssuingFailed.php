<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Falha ao emitir uma fatura no serviço de faturação.
 *
 * A mensagem só tem o passo e o código HTTP e a exceção nunca encadeia a original (`previous`):
 * as exceções do cliente HTTP trazem o URL com a `api_key` e o corpo da resposta, que pode
 * repetir o NIF ou o nome do cliente, e o log e a tabela `failed_jobs` gravam a cadeia toda.
 */
class InvoiceIssuingFailed extends RuntimeException
{
    /**
     * @param  string  $step  Passo que falhou: create, finalize ou currency.
     * @param  int|null  $httpStatus  Código HTTP da resposta, ou null quando não houve resposta.
     * @param  string|null  $providerInvoiceId  Id do rascunho já criado (falhas a finalizar), para a nova
     *                                          tentativa retomar a partir dele em vez de criar outro.
     */
    public function __construct(
        string $message,
        public readonly string $step,
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerInvoiceId = null,
    ) {
        parent::__construct($message);
    }

    /**
     * O serviço respondeu com um erro (4xx, ou 5xx depois das novas tentativas).
     */
    public static function rejected(string $step, int $status, ?string $providerInvoiceId = null): self
    {
        return new self("Falha ao emitir a fatura (passo: {$step}, HTTP {$status}).", $step, $status, $providerInvoiceId);
    }

    /**
     * Não houve resposta do serviço (timeout ou erro de ligação), mesmo depois das novas tentativas.
     */
    public static function unreachable(string $step, ?string $providerInvoiceId = null): self
    {
        return new self("Sem ligação ao serviço de faturação (passo: {$step}).", $step, null, $providerInvoiceId);
    }

    /**
     * A moeda do pagamento não é suportada pelo serviço de faturação (só EUR).
     */
    public static function unsupportedCurrency(string $currency): self
    {
        return new self("Moeda não suportada na faturação: {$currency}.", 'currency');
    }
}
