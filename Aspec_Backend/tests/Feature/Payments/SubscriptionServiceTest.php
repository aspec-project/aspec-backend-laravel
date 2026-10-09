<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Exceptions\ActivationLinkNoLongerValidException;
use App\Models\User;
use App\Services\Payments\StripeSubscriptionService;
use App\Services\Payments\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regras do SubscriptionService chamadas diretamente (sem HTTP), para casos que o endpoint
 * não consegue provocar, como a conta mudar de estado entre a validação do pedido e o lock.
 */
class SubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function account_blocked_before_the_lock_cannot_start_a_checkout(): void
    {
        // O controller viu a conta Approved; entretanto o admin bloqueou-a.
        $user = User::factory()->approved()->create();
        User::whereKey($user->id)->first()->deactivate(InactiveReason::Blocked->value);
        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('createCheckout');
            $mock->shouldNotReceive('retrieveCheckout');
        });

        try {
            app(SubscriptionService::class)->startCheckout($user, ['billing_name' => 'X'], 'http://localhost:5173/ativacao/x');
            $this->fail('Devia ter lançado ActivationLinkNoLongerValidException.');
        } catch (ActivationLinkNoLongerValidException $e) {
            $this->assertSame('Este link já não é válido para esta conta.', $e->getMessage());
        }

        $fresh = $user->fresh();
        $this->assertSame(InactiveReason::Blocked, $fresh->inactive_reason);
        $this->assertNull($fresh->billing_name);
        $this->assertNull($fresh->stripe_checkout_session_id);
    }
}
