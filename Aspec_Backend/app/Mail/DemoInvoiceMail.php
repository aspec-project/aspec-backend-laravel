<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email com o PDF da fatura demo (driver de faturação demo).
 *
 * Não implementa ShouldQueue de propósito: em fila, o email do cliente ficava gravado na tabela jobs.
 * Já corre dentro do IssueInvoiceJob, por isso o envio síncrono não atrasa nenhum pedido HTTP.
 */
class DemoInvoiceMail extends Mailable
{
    /**
     * @param  string  $number  Número da fatura (DEMO-AAAA-XXXXXXXX).
     * @param  string  $path  Caminho do PDF no disco local.
     */
    public function __construct(
        public string $number,
        public string $path,
    ) {}

    /**
     * Assunto com o número da fatura.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: "Fatura {$this->number} — ASPEC");
    }

    /**
     * Corpo curto, com o aviso de que é um documento de demonstração.
     */
    public function content(): Content
    {
        return new Content(view: 'mail.demo-invoice');
    }

    /**
     * O PDF da fatura, lido do disco privado, como único anexo.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk('local', $this->path)
                ->as("fatura-{$this->number}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
