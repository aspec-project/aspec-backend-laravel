<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET /api/invoices: o membro ativo vê as faturas dos seus pagamentos, mais recentes primeiro,
 * sem ids internos nem caminhos de ficheiros.
 */
class InvoiceIndexTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/invoices';

    private const ITEM_KEYS = ['id', 'number', 'amount', 'currency', 'status', 'paid_at', 'issued_at', 'pdf_available'];

    private const PAGINATION_KEYS = ['current_page', 'per_page', 'total', 'last_page'];

    private const FORBIDDEN_KEYS = ['stripe_invoice_id', 'provider', 'provider_invoice_id', 'pdf_url', 'user_id'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-20 10:00');
    }

    private function member(): User
    {
        return User::factory()->withBilling()->create();
    }

    // --- I1 ---

    #[Test]
    public function member_lists_own_invoices_newest_first(): void
    {
        $member = $this->member();
        $oldest = Invoice::factory()->for($member)->demo()->issued()->create(['created_at' => now()->subMonths(2)]);
        $newest = Invoice::factory()->for($member)->demo()->issued()->create(['created_at' => now()]);
        $middle = Invoice::factory()->for($member)->demo()->issued()->create(['created_at' => now()->subMonth()]);
        Sanctum::actingAs($member);

        $response = $this->getJson(self::URL);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Faturas obtidas com sucesso.')
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.id', $newest->id)
            ->assertJsonPath('data.items.1.id', $middle->id)
            ->assertJsonPath('data.items.2.id', $oldest->id);
    }

    #[Test]
    public function each_item_has_exactly_the_public_keys(): void
    {
        $member = $this->member();
        Invoice::factory()->for($member)->demo()->issued()->create();
        Sanctum::actingAs($member);

        $response = $this->getJson(self::URL)->assertOk();

        $this->assertEqualsCanonicalizing(self::ITEM_KEYS, array_keys($response->json('data.items.0')));
        $this->assertEqualsCanonicalizing(['items', 'pagination'], array_keys($response->json('data')));
        $this->assertEqualsCanonicalizing(self::PAGINATION_KEYS, array_keys($response->json('data.pagination')));
    }

    #[Test]
    public function item_has_number_currency_and_iso_dates(): void
    {
        $this->freezeSecond();
        $member = $this->member();
        $paidAt = now()->subDays(3);
        $issuedAt = now()->subDays(2);
        $invoice = Invoice::factory()->for($member)->demo()->issued()->create([
            'created_at' => $paidAt,
            'issued_at' => $issuedAt,
        ]);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.items.0.number', $invoice->number)
            ->assertJsonPath('data.items.0.currency', 'eur')
            ->assertJsonPath('data.items.0.paid_at', $paidAt->toIso8601String())
            ->assertJsonPath('data.items.0.issued_at', $issuedAt->toIso8601String());
    }

    #[Test]
    public function invoices_paid_at_the_same_moment_have_a_stable_order(): void
    {
        $member = $this->member();
        $first = Invoice::factory()->for($member)->create(['created_at' => now()]);
        $second = Invoice::factory()->for($member)->create(['created_at' => now()]);
        Sanctum::actingAs($member);

        $expected = collect([$first->id, $second->id])->sortDesc()->values()->all();

        $this->assertSame($expected, $this->getJson(self::URL)->json('data.items.*.id'));
    }

    // --- I2 ---

    public static function publicStatuses(): array
    {
        return [
            'pending' => [[], 'processing', false, false],
            'failed' => [['failed'], 'processing', false, false],
            'issued demo' => [['demo', 'issued'], 'issued', true, true],
            'issued_not_sent demo' => [['demo', 'issuedNotSent'], 'issued', true, true],
            'issued log' => [['issued'], 'issued', true, false],
            'issued invoiceexpress' => [['invoiceExpress', 'issued'], 'issued', true, true],
        ];
    }

    /**
     * @param  array<int, string>  $states
     */
    #[Test]
    #[DataProvider('publicStatuses')]
    public function internal_status_is_mapped_to_the_public_status(array $states, string $status, bool $hasNumber, bool $pdfAvailable): void
    {
        $member = $this->member();
        $factory = Invoice::factory()->for($member);
        foreach ($states as $state) {
            $factory = $factory->{$state}();
        }
        $factory->create();
        Sanctum::actingAs($member);

        $response = $this->getJson(self::URL)->assertOk();

        $response->assertJsonPath('data.items.0.status', $status)
            ->assertJsonPath('data.items.0.pdf_available', $pdfAvailable);
        $hasNumber
            ? $this->assertNotNull($response->json('data.items.0.number'))
            : $response->assertJsonPath('data.items.0.number', null);
    }

    #[Test]
    public function processing_invoice_never_shows_a_number_or_issue_date(): void
    {
        $member = $this->member();
        Invoice::factory()->for($member)->failed()->create(['number' => 'FR ASPEC2026/9']);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.items.0.status', 'processing')
            ->assertJsonPath('data.items.0.number', null)
            ->assertJsonPath('data.items.0.issued_at', null);
    }

    #[Test]
    public function issued_invoice_without_a_document_id_has_no_pdf(): void
    {
        $member = $this->member();
        Invoice::factory()->for($member)->demo()->issued()->create(['provider_invoice_id' => null]);
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.items.0.status', 'issued')
            ->assertJsonPath('data.items.0.pdf_available', false);
    }

    // --- I3 ---

    #[Test]
    public function other_members_invoices_are_not_listed(): void
    {
        $member = $this->member();
        $mine = Invoice::factory()->for($member)->demo()->issued()->create();
        Invoice::factory()->count(2)->for($this->member())->demo()->issued()->create();
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $mine->id)
            ->assertJsonPath('data.pagination.total', 1);
    }

    // --- I4 ---

    #[Test]
    public function list_is_paginated_ten_per_page(): void
    {
        $member = $this->member();
        for ($i = 0; $i < 12; $i++) {
            Invoice::factory()->for($member)->demo()->issued()->create(['created_at' => now()->subMonths($i)]);
        }
        Sanctum::actingAs($member);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(10, 'data.items')
            ->assertJsonPath('data.pagination', [
                'current_page' => 1,
                'per_page' => 10,
                'total' => 12,
                'last_page' => 2,
            ]);

        $this->getJson(self::URL.'?page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination', [
                'current_page' => 2,
                'per_page' => 10,
                'total' => 12,
                'last_page' => 2,
            ]);
    }

    // --- I5 ---

    #[Test]
    public function member_without_invoices_gets_an_empty_list(): void
    {
        Sanctum::actingAs($this->member());

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.pagination.total', 0);
    }

    // --- I6 ---

    #[Test]
    public function internal_ids_and_file_paths_are_never_exposed(): void
    {
        $member = $this->member();
        $demo = Invoice::factory()->for($member)->demo()->issued()->create();
        $express = Invoice::factory()->for($member)->invoiceExpress()->issued()->create();
        Sanctum::actingAs($member);

        $response = $this->getJson(self::URL)->assertOk();

        foreach ($response->json('data.items') as $item) {
            foreach (self::FORBIDDEN_KEYS as $key) {
                $this->assertArrayNotHasKey($key, $item);
            }
        }

        $body = $response->getContent();
        foreach ([$demo, $express] as $invoice) {
            $this->assertStringNotContainsString($invoice->stripe_invoice_id, $body);
            $this->assertStringNotContainsString(json_encode($invoice->provider_invoice_id), $body);
            $this->assertStringNotContainsString($member->id, $body);
        }
        $this->assertStringNotContainsString('invoices\/', $body);
        $this->assertStringNotContainsString('invoicexpress.com', $body);
    }

    // --- I7 ---

    #[Test]
    public function amount_is_returned_in_euros(): void
    {
        $member = $this->member();
        Invoice::factory()->for($member)->demo()->issued()->create(['amount' => 6000]);
        Sanctum::actingAs($member);

        $this->assertEquals(60, $this->getJson(self::URL)->assertOk()->json('data.items.0.amount'));
    }

    #[Test]
    public function amount_with_cents_keeps_the_cents(): void
    {
        $member = $this->member();
        Invoice::factory()->for($member)->demo()->issued()->create(['amount' => 6050]);
        Sanctum::actingAs($member);

        $this->assertEquals(60.5, $this->getJson(self::URL)->assertOk()->json('data.items.0.amount'));
    }

    // --- I8, I9, I10 ---

    #[Test]
    public function guest_gets_401(): void
    {
        $this->getJson(self::URL)
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Não autenticado.');
    }

    public static function accountsWithoutAccess(): array
    {
        return [
            'Approved' => ['approved'],
            'Pending' => ['pending'],
            'Inactive unpaid' => ['inactiveUnpaid'],
        ];
    }

    #[Test]
    #[DataProvider('accountsWithoutAccess')]
    public function account_that_is_not_active_gets_403(string $state): void
    {
        $factory = User::factory()->withBilling();
        $user = $state === 'inactiveUnpaid'
            ? $factory->inactive(InactiveReason::Unpaid)->create()
            : $factory->{$state}()->create();
        Invoice::factory()->for($user)->demo()->issued()->create();
        Sanctum::actingAs($user);

        $this->getJson(self::URL)
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('data.items');
    }

    #[Test]
    public function admin_gets_an_empty_list(): void
    {
        Invoice::factory()->for($this->member())->demo()->issued()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.pagination.total', 0);
    }

    // --- I11 a I16: per_page e page ---

    #[Test]
    public function per_page_sets_the_page_size(): void
    {
        $member = $this->member();
        for ($i = 0; $i < 7; $i++) {
            Invoice::factory()->for($member)->demo()->issued()->create(['created_at' => now()->subMonths($i)]);
        }
        Sanctum::actingAs($member);

        $this->getJson(self::URL.'?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data.items')
            ->assertJsonPath('data.pagination', [
                'current_page' => 1,
                'per_page' => 5,
                'total' => 7,
                'last_page' => 2,
            ]);

        $this->getJson(self::URL.'?page=2&per_page=5')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.per_page', 5);
    }

    /**
     * Um per_page vazio chega como null (ConvertEmptyStringsToNull) e tem de cair no valor por omissão.
     */
    #[Test]
    public function missing_or_empty_per_page_uses_ten(): void
    {
        $member = $this->member();
        Invoice::factory()->count(12)->for($member)->create();
        Sanctum::actingAs($member);

        foreach ([self::URL, self::URL.'?per_page=', self::URL.'?page=1&per_page='] as $url) {
            $this->getJson($url)
                ->assertOk()
                ->assertJsonCount(10, 'data.items')
                ->assertJsonPath('data.pagination.per_page', 10);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPerPages(): array
    {
        return [
            'zero' => ['0'],
            'acima de 50' => ['51'],
            'negativo' => ['-1'],
            'texto' => ['abc'],
            'decimal' => ['5.5'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPerPages')]
    public function per_page_outside_one_to_fifty_is_rejected(string $value): void
    {
        Sanctum::actingAs($this->member());

        $this->getJson(self::URL.'?per_page='.$value)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['per_page'])
            ->assertJsonMissingPath('data.items');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPages(): array
    {
        return [
            'zero' => ['0'],
            'acima de 10000' => ['10001'],
            'texto' => ['abc'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPages')]
    public function page_outside_one_to_ten_thousand_is_rejected_with_a_portuguese_attribute(string $value): void
    {
        Sanctum::actingAs($this->member());

        $response = $this->getJson(self::URL.'?page='.$value)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['page']);

        $this->assertStringContainsString('página', $response->json('errors.page.0'));
    }

    #[Test]
    public function fifty_per_page_is_accepted(): void
    {
        $member = $this->member();
        Invoice::factory()->count(3)->for($member)->create();
        Sanctum::actingAs($member);

        $this->getJson(self::URL.'?per_page=50')
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.pagination.per_page', 50);
    }

    #[Test]
    public function large_page_size_still_lists_only_own_invoices(): void
    {
        $member = $this->member();
        Invoice::factory()->count(2)->for($member)->create();
        Invoice::factory()->count(4)->for($this->member())->create();
        Sanctum::actingAs($member);

        $response = $this->getJson(self::URL.'?per_page=50')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.total', 2);

        $this->assertEqualsCanonicalizing(
            $member->issuedInvoices()->pluck('id')->all(),
            $response->json('data.items.*.id'),
        );
    }
}
