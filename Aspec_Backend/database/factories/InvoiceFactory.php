<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fatura pendente de 60 € (driver log) de um membro com dados de faturação completos.
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->withBilling(),
            'stripe_invoice_id' => 'in_test_'.Str::random(14),
            'provider' => 'log',
            'amount' => 6000,
            'currency' => 'eur',
            'status' => InvoiceStatus::Pending,
        ];
    }

    /**
     * Fatura emitida e enviada, com o número no formato do driver que a emitiu.
     */
    public function issued(): static
    {
        return $this->state(fn () => [
            'status' => InvoiceStatus::Issued,
            'issued_at' => now(),
            'number' => fn (array $attributes) => self::numberFor($attributes),
        ]);
    }

    /**
     * Fatura emitida (com número) cujo envio por email falhou.
     */
    public function issuedNotSent(): static
    {
        return $this->issued()->state(fn () => [
            'status' => InvoiceStatus::IssuedNotSent,
        ]);
    }

    /**
     * Fatura que não foi possível emitir (sem número).
     */
    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => InvoiceStatus::Failed,
        ]);
    }

    /**
     * Fatura do driver demo: o provider_invoice_id é o caminho do PDF no disco local
     * (invoices/{user_id}/{número}.pdf). O ficheiro não é criado aqui.
     */
    public function demo(): static
    {
        return $this->state(fn () => [
            'provider' => 'demo',
            'number' => fn (array $attributes) => self::numberFor($attributes),
            'provider_invoice_id' => fn (array $attributes) => "invoices/{$attributes['user_id']}/{$attributes['number']}.pdf",
        ]);
    }

    /**
     * Fatura do driver InvoiceExpress: id numérico do documento e link público do fornecedor.
     */
    public function invoiceExpress(): static
    {
        return $this->state(fn () => [
            'provider' => 'invoiceexpress',
            'provider_invoice_id' => (string) fake()->unique()->numberBetween(1, 999999),
            'pdf_url' => 'https://aspec-test.app.invoicexpress.com/documents/'.Str::random(12),
        ]);
    }

    /**
     * Número como cada driver o gera: LOG-/DEMO-{ano}-{8 hex do id do Stripe}, ou série da InvoiceExpress.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function numberFor(array $attributes): string
    {
        $hash = strtoupper(substr(sha1((string) $attributes['stripe_invoice_id']), 0, 8));
        $year = now()->format('Y');

        return match ($attributes['provider']) {
            'demo' => "DEMO-{$year}-{$hash}",
            'invoiceexpress' => "FR ASPEC{$year}/".fake()->unique()->numberBetween(1, 99999),
            default => "LOG-{$year}-{$hash}",
        };
    }
}
