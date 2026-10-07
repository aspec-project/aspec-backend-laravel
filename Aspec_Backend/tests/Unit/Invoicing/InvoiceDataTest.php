<?php

namespace Tests\Unit\Invoicing;

use App\Services\Invoicing\Data\InvoiceData;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// O DTO não precisa da aplicação: usa o TestCase do PHPUnit.
class InvoiceDataTest extends TestCase
{
    private function make(int $amount = 6000, string $currency = 'eur'): InvoiceData
    {
        return new InvoiceData(
            stripeInvoiceId: 'in_test_123',
            amount: $amount,
            currency: $currency,
            description: 'Quota mensal ASPEC — outubro 2026',
            date: new DateTimeImmutable('2026-10-07'),
        );
    }

    public static function invalidAmounts(): array
    {
        return [
            'zero' => [0],
            'negativo' => [-100],
        ];
    }

    public static function invalidCurrencies(): array
    {
        return [
            'quatro letras' => ['euro'],
            'duas letras' => ['eu'],
            'vazia' => [''],
        ];
    }

    #[Test]
    public function keeps_the_given_values(): void
    {
        $invoice = $this->make();

        $this->assertSame('in_test_123', $invoice->stripeInvoiceId);
        $this->assertSame(6000, $invoice->amount);
        $this->assertSame('eur', $invoice->currency);
        $this->assertSame('2026-10-07', $invoice->date->format('Y-m-d'));
    }

    #[Test]
    #[DataProvider('invalidAmounts')]
    public function rejects_amounts_that_are_not_positive(int $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->make(amount: $amount);
    }

    #[Test]
    #[DataProvider('invalidCurrencies')]
    public function rejects_currencies_without_three_letters(string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->make(currency: $currency);
    }
}
