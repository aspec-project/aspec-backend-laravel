<?php

namespace App\Services\Invoicing\Data;

/**
 * Cliente a quem a fatura é emitida (a empresa do membro, com NIF). O país é sempre Portugal.
 *
 * Os dados pessoais estão marcados com #[\SensitiveParameter]: se uma exceção for registada
 * com stack trace, o PHP troca-os por SensitiveParameterValue e não chegam ao log.
 */
final readonly class InvoiceCustomerData
{
    /**
     * @param  string  $code  UUID do utilizador: identifica o cliente no serviço de faturação.
     */
    public function __construct(
        public string $code,
        #[\SensitiveParameter] public string $name,
        #[\SensitiveParameter] public string $nif,
        #[\SensitiveParameter] public string $address,
        public string $postalCode,
        public string $city,
        #[\SensitiveParameter] public string $email,
    ) {}
}
