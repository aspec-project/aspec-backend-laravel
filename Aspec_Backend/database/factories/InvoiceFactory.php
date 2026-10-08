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
}
