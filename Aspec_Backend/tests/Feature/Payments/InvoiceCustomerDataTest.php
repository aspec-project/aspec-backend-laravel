<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Services\Invoicing\Data\InvoiceCustomerData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cliente da fatura a partir dos dados de faturação do membro (empresa com NIF).
 * Sem dados completos não há fatura possível: devolve null em vez de emitir uma fatura inválida.
 */
class InvoiceCustomerDataTest extends TestCase
{
    use RefreshDatabase;

    private function userWithBilling(): User
    {
        $user = User::factory()->create(['phone' => '912345678']);
        $user->forceFill([
            'billing_name' => 'Silva Contabilidade Lda',
            'nif' => '245678901',
            'billing_address' => 'Rua da Faturação 10',
            'billing_postal_code' => '1000-001',
            'billing_city' => 'Lisboa',
        ])->save();

        return $user->fresh();
    }

    #[Test]
    public function maps_the_billing_fields_of_the_user(): void
    {
        $user = $this->userWithBilling();

        $customer = InvoiceCustomerData::fromUser($user);

        $this->assertNotNull($customer);
        $this->assertSame($user->id, $customer->code);
        $this->assertSame('Silva Contabilidade Lda', $customer->name);
        $this->assertSame('245678901', $customer->nif);
        $this->assertSame('Rua da Faturação 10', $customer->address);
        $this->assertSame('1000-001', $customer->postalCode);
        $this->assertSame('Lisboa', $customer->city);
        $this->assertSame($user->email, $customer->email);
    }

    public static function billingFields(): array
    {
        return [
            'billing_name' => ['billing_name'],
            'nif' => ['nif'],
            'billing_address' => ['billing_address'],
            'billing_postal_code' => ['billing_postal_code'],
            'billing_city' => ['billing_city'],
        ];
    }

    #[Test]
    #[DataProvider('billingFields')]
    public function returns_null_when_a_billing_field_is_missing(string $field): void
    {
        $user = $this->userWithBilling();
        $user->forceFill([$field => null])->save();

        $this->assertNull(InvoiceCustomerData::fromUser($user->fresh()));
    }

    #[Test]
    public function never_uses_the_phone(): void
    {
        $customer = InvoiceCustomerData::fromUser($this->userWithBilling());

        $this->assertStringNotContainsString('912345678', var_export($customer, true));
    }

    #[Test]
    public function returns_null_for_an_anonymized_user(): void
    {
        $user = $this->userWithBilling();
        $user->anonymizeAndDelete();

        $this->assertNull(InvoiceCustomerData::fromUser(User::withTrashed()->find($user->id)));
    }
}
