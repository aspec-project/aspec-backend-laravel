<?php

namespace Tests\Concerns;

use App\Services\Invoicing\Data\InvoiceCustomerData;
use App\Services\Invoicing\Data\InvoiceData;
use DateTimeImmutable;

/**
 * Dados fictícios de faturação partilhados pelos testes dos drivers.
 * O NIF, nome, email e morada servem para provar que nunca aparecem em logs nem em exceções.
 */
trait BuildsInvoiceData
{
    protected const NIF = '123456789';

    protected const CUSTOMER_NAME = 'Empresa Teste Lda';

    protected const CUSTOMER_EMAIL = 'faturas@empresa.test';

    protected const CUSTOMER_ADDRESS = 'Rua das Flores 12';

    protected const CUSTOMER_CODE = '9f1c2b3a-4d5e-4f60-8a7b-1c2d3e4f5a6b';

    protected const ACCOUNT_NAME = 'aspec-test';

    protected const API_KEY = 'secret-key-123';

    protected function customer(): InvoiceCustomerData
    {
        return new InvoiceCustomerData(
            code: self::CUSTOMER_CODE,
            name: self::CUSTOMER_NAME,
            nif: self::NIF,
            address: self::CUSTOMER_ADDRESS,
            postalCode: '1000-001',
            city: 'Lisboa',
            email: self::CUSTOMER_EMAIL,
        );
    }

    protected function invoice(
        string $stripeInvoiceId = 'in_test_123',
        int $amount = 6000,
        string $currency = 'eur',
    ): InvoiceData {
        return new InvoiceData(
            stripeInvoiceId: $stripeInvoiceId,
            amount: $amount,
            currency: $currency,
            description: 'Quota mensal ASPEC — outubro 2026',
            date: new DateTimeImmutable('2026-10-07 14:30:00'),
        );
    }
}
