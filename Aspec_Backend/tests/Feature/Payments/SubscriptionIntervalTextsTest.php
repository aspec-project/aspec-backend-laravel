<?php

namespace Tests\Feature\Payments;

use App\Contracts\InvoiceService;
use App\Enums\InactiveReason;
use App\Mail\DemoInvoiceMail;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\ActivationLinkNotification;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\ReactivationLinkNotification;
use App\Notifications\SubscriptionActivatedNotification;
use App\Notifications\SubscriptionCancelledNotification;
use App\Notifications\SubscriptionResumedNotification;
use App\Services\Invoicing\Data\InvoiceCustomerData;
use App\Services\Invoicing\Data\InvoiceData;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsActivationLinks;
use Tests\TestCase;

/**
 * Textos dos emails de pagamento e de fatura com quota mensal ou anual: o intervalo só aparece
 * onde se diz o valor (o membro tem de saber o que vai pagar antes de dar o cartão); o resto é
 * neutro. Com a quota anual, nenhum email pode dizer "mensal".
 */
class SubscriptionIntervalTextsTest extends TestCase
{
    use BuildsActivationLinks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTestFrontendUrl();
        config([
            'subscription.price_amount' => 20.00,
            'subscription.trial_days' => 30,
            'subscription.activation_link_days' => 7,
            'subscription.reactivation_link_days' => 7,
        ]);
    }

    public static function perPeriodTexts(): array
    {
        return [
            'mensal' => ['month', 'por mês'],
            'anual' => ['year', 'por ano'],
        ];
    }

    public static function adjectiveTexts(): array
    {
        return [
            'mensal' => ['month', 'mensal'],
            'anual' => ['year', 'anual'],
        ];
    }

    private function member(?User $user = null): User
    {
        $user ??= User::factory()->withBilling()->create();
        MemberProfile::factory()->create(['user_id' => $user->id, 'name' => 'Ana Ferreira']);

        return $user->fresh();
    }

    /**
     * Assunto, saudação, botão e linhas do email num só texto.
     */
    private function mailText(MailMessage $mail): string
    {
        return implode("\n", array_merge(
            [$mail->subject, $mail->greeting, $mail->actionText, $mail->salutation],
            $mail->introLines,
            $mail->outroLines,
        ));
    }

    #[Test]
    #[DataProvider('perPeriodTexts')]
    public function activation_email_says_the_fee_per_month_or_per_year(string $interval, string $perPeriod): void
    {
        config(['subscription.interval' => $interval]);
        $user = $this->member(User::factory()->approved()->create());

        $text = $this->mailText((new ActivationLinkNotification)->toMail($user));

        $this->assertStringContainsString("depois, a quota é de 20,00 € {$perPeriod}", $text);
    }

    #[Test]
    #[DataProvider('adjectiveTexts')]
    public function payment_failed_email_says_the_monthly_or_annual_fee(string $interval, string $adjective): void
    {
        config(['subscription.interval' => $interval]);
        $user = $this->member();

        $text = $this->mailText((new PaymentFailedNotification(now()->addDays(7)))->toMail($user));

        $this->assertStringContainsString("a quota {$adjective} de 20,00 €", $text);
    }

    /**
     * Todos os emails de pagamento com a quota anual (um por variante de texto).
     *
     * @return array<string, MailMessage>
     */
    private function annualPaymentMails(): array
    {
        $member = $this->member();
        $unpaid = $this->member(User::factory()->inactive(InactiveReason::Unpaid)->withBilling()->create());
        $cancelled = $this->member(User::factory()->inactive(InactiveReason::Cancelled)->withBilling()->create());
        $approved = $this->member(User::factory()->approved()->create());

        return [
            'ativação' => (new ActivationLinkNotification)->toMail($approved),
            'reativação por falta de pagamento' => (new ReactivationLinkNotification)->toMail($unpaid),
            'reativação depois de cancelar' => (new ReactivationLinkNotification)->toMail($cancelled),
            'pagamento falhado' => (new PaymentFailedNotification(now()->addDays(7)))->toMail($member),
            'boas-vindas com trial' => (new SubscriptionActivatedNotification(now()->addDays(30)))->toMail($member),
            'boas-vindas sem trial' => (new SubscriptionActivatedNotification(null))->toMail($member),
            'retoma' => (new SubscriptionResumedNotification(now()->addYear()))->toMail($member),
            'cancelamento agendado' => (new SubscriptionCancelledNotification(now()->addYear()))->toMail($member),
        ];
    }

    #[Test]
    public function no_payment_email_says_monthly_when_the_fee_is_annual(): void
    {
        config(['subscription.interval' => 'year']);

        foreach ($this->annualPaymentMails() as $name => $mail) {
            $this->assertStringNotContainsStringIgnoringCase('mensal', $this->mailText($mail), "Email de {$name}.");
            $this->assertStringNotContainsStringIgnoringCase('por mês', $this->mailText($mail), "Email de {$name}.");
        }
    }

    #[Test]
    public function demo_invoice_email_names_the_fee_without_the_interval(): void
    {
        config(['subscription.interval' => 'year']);

        $html = (new DemoInvoiceMail('DEMO-2026-ABCDEF12', 'invoices/x/DEMO-2026-ABCDEF12.pdf'))->render();

        $this->assertStringContainsString('fatura da sua quota da ASPEC', $html);
        $this->assertStringNotContainsStringIgnoringCase('mensal', $html);
    }

    #[Test]
    public function invoiceexpress_email_body_names_the_fee_without_the_interval(): void
    {
        config(['subscription.interval' => 'year']);
        Http::preventStrayRequests();
        config([
            'services.invoicing.driver' => 'invoiceexpress',
            'services.invoiceexpress.account_name' => 'aspec-test',
            'services.invoiceexpress.api_key' => 'secret-key-123',
            'services.invoiceexpress.document_type' => 'invoice_receipts',
            'services.invoiceexpress.sequence_id' => null,
            'services.invoiceexpress.item_name' => 'Quota ASPEC',
            'services.invoiceexpress.tax_name' => 'IVA23',
            'services.invoiceexpress.vat_rate' => 23,
            'services.invoiceexpress.tax_exemption' => null,
            'services.invoiceexpress.retry_times' => 1,
            'services.invoiceexpress.retry_sleep_ms' => 0,
        ]);
        Http::fake([
            '*aspec-test.app.invoicexpress.com/invoice_receipts.json*' => Http::response(['invoice_receipt' => ['id' => 987, 'status' => 'draft']], 201),
            '*aspec-test.app.invoicexpress.com/invoice_receipts/987/change-state.json*' => Http::response(['invoice_receipt' => [
                'id' => 987,
                'status' => 'settled',
                'sequence_number' => 'FR ASPEC2026/1',
                'permalink' => 'https://aspec-test.app.invoicexpress.com/documents/abc123',
            ]], 200),
            '*aspec-test.app.invoicexpress.com/invoice_receipts/987/email-document.json*' => Http::response([], 200),
        ]);

        app(InvoiceService::class)->issue(
            new InvoiceCustomerData(
                code: 'c0de',
                name: 'Empresa Teste Lda',
                nif: '123456789',
                address: 'Rua das Flores 12',
                postalCode: '1000-001',
                city: 'Lisboa',
                email: 'faturas@empresa.test',
            ),
            new InvoiceData(
                stripeInvoiceId: 'in_test_123',
                amount: 2000,
                currency: 'eur',
                description: 'Quota ASPEC — período de 28/10/2026 a 27/10/2027',
                date: new DateTimeImmutable('2026-10-28 10:00:00'),
            ),
        );

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/email-document.json')
            && str_contains((string) data_get($request->data(), 'message.body'), 'fatura da sua quota da ASPEC')
            && ! str_contains(mb_strtolower((string) data_get($request->data(), 'message.body')), 'mensal'));
    }
}
