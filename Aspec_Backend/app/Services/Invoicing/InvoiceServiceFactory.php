<?php

namespace App\Services\Invoicing;

use App\Contracts\InvoiceService;
use InvalidArgumentException;

/**
 * Cria o driver de faturação pelo nome (log, demo ou invoiceexpress).
 *
 * O contrato InvoiceService usa o driver configurado agora; o PDF de uma fatura é pedido ao driver
 * que a emitiu (invoices.provider), que pode já não ser o configurado.
 */
class InvoiceServiceFactory
{
    /**
     * Devolve o driver com o nome dado. A config do InvoiceExpress é lida a cada chamada.
     *
     * @throws InvalidArgumentException Se o driver for desconhecido ou a conta InvoiceExpress não estiver
     *                                  (bem) configurada; as mensagens não mostram os valores configurados.
     */
    public function make(string $provider): InvoiceService
    {
        return match ($provider) {
            'log' => new LogInvoiceService,
            'demo' => new DemoInvoiceService,
            'invoiceexpress' => $this->makeInvoiceExpressService(config('services.invoiceexpress')),
            default => throw new InvalidArgumentException('Driver de faturação desconhecido.'),
        };
    }

    /**
     * Cria o driver InvoiceExpress com os valores da config.
     *
     * @throws InvalidArgumentException Se faltar o nome da conta ou a chave da API, ou se o nome da
     *                                  conta ou o tipo de documento tiverem caracteres que mudem o URL.
     */
    private function makeInvoiceExpressService(#[\SensitiveParameter] array $config): InvoiceExpressInvoiceService
    {
        if (blank($config['account_name'] ?? null) || blank($config['api_key'] ?? null)) {
            throw new InvalidArgumentException('Faltam o nome da conta ou a chave da API da InvoiceExpress.');
        }

        // Os dois valores entram no URL: um nome de conta como "evil.example/x?" mudava o host e
        // mandava a api_key para outro servidor; um tipo com "/" ou "?" mudava o caminho.
        if (! preg_match('/^[a-z0-9-]+$/i', $config['account_name'])) {
            throw new InvalidArgumentException('Nome da conta InvoiceExpress inválido.');
        }

        if (! preg_match('/^[a-z_]+$/', (string) ($config['document_type'] ?? ''))) {
            throw new InvalidArgumentException('Tipo de documento InvoiceExpress inválido.');
        }

        return new InvoiceExpressInvoiceService(
            accountName: $config['account_name'],
            apiKey: $config['api_key'],
            documentType: $config['document_type'],
            sequenceId: filled($config['sequence_id'] ?? null) ? (string) $config['sequence_id'] : null,
            itemName: $config['item_name'],
            taxName: $config['tax_name'],
            timeout: (int) $config['timeout'],
            retryTimes: (int) $config['retry_times'],
            retrySleepMs: (int) $config['retry_sleep_ms'],
        );
    }
}
