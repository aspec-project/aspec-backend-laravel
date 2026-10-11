<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Models\User;
use App\Notifications\ReactivationLinkNotification;
use App\Services\Payments\StripeSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * Webhook customer.subscription.deleted por falta de pagamento: rede de segurança do comando de
 * fim da carência (ex. scheduler parado). Só uma conta Active sem outra subscrição em curso fica
 * Inactive; contas bloqueadas, já desativadas ou apagadas ficam como estão e não recebem email.
 */
class SubscriptionDeletedWebhookTest extends TestCase
{
    use RefreshDatabase, SignsStripeWebhooks;

    private const CUSTOMER = 'cus_test_1';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['app.frontend_url' => 'http://localhost:5173']);
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('cancelNow');
        });
    }

    private function customer(?User $user = null): User
    {
        $user ??= User::factory()->withBilling()->create();
        $user->forceFill(['stripe_id' => self::CUSTOMER])->save();

        return $user;
    }

    private function subscribe(User $user, string $status = 'past_due', string $stripeId = 'sub_test_1'): void
    {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => $stripeId,
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'trial_ends_at' => $status === 'trialing' ? now()->addDays(10) : null,
        ]);
    }

    private function deleted(string $stripeId = 'sub_test_1', ?string $reason = null): array
    {
        return $this->stripeEvent(
            'customer.subscription.deleted',
            $this->stripeSubscription(self::CUSTOMER, 'canceled', $stripeId, $reason === null ? [] : [
                'cancellation_details' => ['reason' => $reason, 'comment' => null, 'feedback' => null],
            ]),
        );
    }

    #[Test]
    public function deleted_subscription_of_a_member_in_grace_deactivates_for_non_payment(): void
    {
        $user = $this->customer();
        $user->forceFill(['grace_ends_at' => now()->addDays(2)])->save();
        $this->subscribe($user);

        $this->postStripeWebhook($this->deleted())->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Inactive', $fresh->accountStatus->name);
        $this->assertSame(InactiveReason::Unpaid, $fresh->inactive_reason);
        $this->assertNull($fresh->grace_ends_at);
        $this->assertDatabaseHas('subscriptions', ['stripe_id' => 'sub_test_1', 'stripe_status' => 'canceled']);
        Notification::assertSentToTimes($fresh, ReactivationLinkNotification::class, 1);
    }

    /**
     * O payment_failed perdeu-se (sem carência registada), mas o Stripe diz que cancelou por
     * falta de pagamento.
     */
    #[Test]
    public function deleted_for_failed_payment_without_recorded_grace_deactivates_for_non_payment(): void
    {
        $user = $this->customer();
        $this->subscribe($user);

        $this->postStripeWebhook($this->deleted(reason: 'payment_failed'))->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Inactive', $fresh->accountStatus->name);
        $this->assertSame(InactiveReason::Unpaid, $fresh->inactive_reason);
        Notification::assertSentToTimes($fresh, ReactivationLinkNotification::class, 1);
    }

    /**
     * O bloqueio cancela a subscrição no Stripe e o deleted que se segue não pode trocar o
     * motivo nem enviar um link de reativação a um membro bloqueado.
     */
    #[Test]
    public function deleted_subscription_of_a_blocked_account_keeps_it_blocked_without_email(): void
    {
        $user = $this->customer(User::factory()->inactive(InactiveReason::Blocked)->withBilling()->create());
        $this->subscribe($user);

        $this->postStripeWebhook($this->deleted(reason: 'payment_failed'))->assertOk();

        $this->assertSame(InactiveReason::Blocked, $user->fresh()->inactive_reason);
        $this->assertDatabaseHas('subscriptions', ['stripe_id' => 'sub_test_1', 'stripe_status' => 'canceled']);
        Notification::assertNothingSent();
    }

    #[Test]
    public function deleted_after_the_end_grace_command_sends_no_second_email(): void
    {
        $user = $this->customer(User::factory()->inactive(InactiveReason::Unpaid)->withBilling()->create());
        $this->subscribe($user);

        $this->postStripeWebhook($this->deleted(reason: 'payment_failed'))->assertOk();

        $this->assertSame(InactiveReason::Unpaid, $user->fresh()->inactive_reason);
        Notification::assertNothingSent();
    }

    /**
     * O webhook created cancela duplicados; o deleted desse duplicado não pode tirar o acesso a
     * quem tem outra subscrição em curso.
     */
    #[Test]
    public function deleted_duplicate_with_another_ongoing_subscription_keeps_the_account_active(): void
    {
        $user = $this->customer();
        $this->subscribe($user, 'trialing', 'sub_test_1');
        $this->subscribe($user, 'active', 'sub_test_2');

        $this->postStripeWebhook($this->deleted('sub_test_2', 'cancellation_requested'))->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Active', $fresh->accountStatus->name);
        $this->assertNull($fresh->inactive_reason);
        $this->assertDatabaseHas('subscriptions', ['stripe_id' => 'sub_test_1', 'stripe_status' => 'trialing']);
        Notification::assertNothingSent();
    }

    public static function accountsLeftUnchanged(): array
    {
        return [
            'admin' => ['admin'],
            'anonimizada' => ['deleted'],
        ];
    }

    #[Test]
    #[DataProvider('accountsLeftUnchanged')]
    public function deleted_subscription_of_an_admin_or_anonymized_account_sends_no_email(string $kind): void
    {
        $user = $kind === 'admin'
            ? $this->customer(User::factory()->admin()->create())
            : $this->customer(User::factory()->inactive(InactiveReason::Deleted)->withBilling()->create());
        $this->subscribe($user);
        if ($kind === 'deleted') {
            $user->delete();
        }

        $this->postStripeWebhook($this->deleted(reason: 'payment_failed'))->assertOk();

        $fresh = User::withTrashed()->find($user->id);
        if ($kind === 'admin') {
            $this->assertSame('Active', $fresh->accountStatus->name);
        } else {
            $this->assertSame(InactiveReason::Deleted, $fresh->inactive_reason);
        }
        Notification::assertNothingSent();
    }
}
