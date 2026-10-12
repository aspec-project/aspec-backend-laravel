<?php

namespace App\Services\Invoicing\Data;

use App\Models\User;

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

    /**
     * Cliente da fatura a partir dos dados de faturação do membro (nunca o telefone).
     *
     * @return self|null null se faltar algum dado de faturação (conta sem ativação ou anonimizada):
     *                   sem NIF e morada não há fatura válida.
     */
    public static function fromUser(User $user): ?self
    {
        $fields = ['billing_name', 'nif', 'billing_address', 'billing_postal_code', 'billing_city'];

        foreach ($fields as $field) {
            if (blank($user->{$field})) {
                return null;
            }
        }

        return new self(
            code: $user->id,
            name: $user->billing_name,
            nif: $user->nif,
            address: $user->billing_address,
            postalCode: $user->billing_postal_code,
            city: $user->billing_city,
            email: $user->email,
        );
    }
}
