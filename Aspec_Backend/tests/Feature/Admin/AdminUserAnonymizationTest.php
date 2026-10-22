<?php

namespace Tests\Feature\Admin;

use App\Enums\InactiveReason;
use App\Models\AccountStatus;
use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\AccountAnonymizedNotification;
use App\Notifications\ReactivationLinkNotification;
use App\Services\Payments\StripeCustomerService;
use App\Services\Payments\StripeSubscriptionService;
use App\Services\Payments\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * POST /api/admin/users/{user}/anonymize: apagamento a pedido do titular (RGPD art. 17),
 * feito por um admin no backoffice, com prova mínima de quem anonimizou e quando.
 */
class AdminUserAnonymizationTest extends TestCase
{
    use RefreshDatabase, SignsStripeWebhooks;

    private const SUCCESS_MESSAGE = 'Membro anonimizado com sucesso.';

    private const ADMIN_TARGET_MESSAGE = 'Não é possível anonimizar um administrador.';

    private const NOT_FOUND_MESSAGE = 'Recurso não encontrado.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Notification::fake();
    }

    private function url(string $userId): string
    {
        return "/api/admin/users/{$userId}/anonymize";
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function memberWithProfile(?User $user = null): User
    {
        $user ??= User::factory()->create();
        MemberProfile::factory()->create([
            'user_id' => $user->id,
            'name' => 'Ana Sofia Marques',
            'business_name' => 'Marques Consultoria Lda',
            'commercial_email' => 'comercial@marques.test',
            'commercial_phone' => '+351213456789',
        ]);

        return $user->fresh();
    }

    private function assertUntouched(User $user): void
    {
        $this->assertNotSoftDeleted('users', ['id' => $user->id, 'email' => $user->email]);
        $this->assertDatabaseMissing('account_anonymizations', ['user_id' => $user->id]);
    }

    #[Test]
    public function admin_anonymizes_an_active_member(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();

        $this->postJson($this->url($member->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', self::SUCCESS_MESSAGE)
            ->assertJsonPath('data.id', $member->id)
            ->assertJsonStructure(['success', 'message', 'data' => ['id', 'anonymized_at']]);
    }

    #[Test]
    public function response_has_no_personal_data(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();

        $response = $this->postJson($this->url($member->id))->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString($member->email, $content);
        $this->assertStringNotContainsString($member->phone, $content);
        $this->assertStringNotContainsString('Ana Sofia Marques', $content);
        $this->assertStringNotContainsString('Marques Consultoria Lda', $content);
        $this->assertSame(['id', 'anonymized_at'], array_keys($response->json('data')));
    }

    #[Test]
    public function anonymized_at_is_the_moment_of_the_request(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 10:15:00'));
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();

        $anonymizedAt = $this->postJson($this->url($member->id))->assertOk()->json('data.anonymized_at');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $anonymizedAt);
        $this->assertTrue(Carbon::parse($anonymizedAt)->equalTo(now()));
    }

    #[Test]
    public function account_and_profile_are_anonymized_and_soft_deleted(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();
        $profileId = $member->memberProfile->id;

        $this->postJson($this->url($member->id))->assertOk();

        $this->assertSoftDeleted('users', ['id' => $member->id]);
        $fresh = User::withTrashed()->find($member->id);
        $this->assertSame(InactiveReason::Deleted, $fresh->inactive_reason);
        $this->assertSame(AccountStatus::where('name', 'Inactive')->value('id'), $fresh->account_status_id);
        $this->assertMatchesRegularExpression('/^deleted_.+@aspec\.local$/', $fresh->email);
        $this->assertSoftDeleted('member_profiles', [
            'id' => $profileId,
            'name' => 'Utilizador Anónimo',
            'business_name' => 'Empresa Removida',
            'commercial_email' => null,
            'commercial_phone' => null,
        ]);
    }

    #[Test]
    public function anonymized_account_leaves_the_admin_user_list(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();

        $this->postJson($this->url($member->id))->assertOk();

        $ids = collect($this->getJson('/api/admin/users')->assertOk()->json('data.items'))->pluck('id');
        $this->assertNotContains($member->id, $ids);
    }

    #[Test]
    public function anonymized_account_detail_returns_404(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();

        $this->postJson($this->url($member->id))->assertOk();

        $this->getJson("/api/admin/users/{$member->id}")->assertNotFound();
    }

    #[Test]
    public function anonymized_member_leaves_the_public_directory(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();
        $profileId = $member->memberProfile->id;

        $this->postJson($this->url($member->id))->assertOk();

        $this->getJson("/api/members/{$profileId}")->assertNotFound();
    }

    #[Test]
    public function login_with_the_old_email_is_refused(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();
        $oldEmail = $member->email;

        $this->postJson($this->url($member->id))->assertOk();

        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/auth/login', ['email' => $oldEmail, 'password' => 'password'])
            ->assertUnauthorized();
    }

    #[Test]
    public function proof_record_stores_the_account_and_the_admin(): void
    {
        $admin = $this->actingAsAdmin();
        $member = $this->memberWithProfile();

        $this->postJson($this->url($member->id))->assertOk();

        $this->assertDatabaseCount('account_anonymizations', 1);
        $this->assertDatabaseHas('account_anonymizations', [
            'user_id' => $member->id,
            'anonymized_by_user_id' => $admin->id,
        ]);
    }

    /**
     * O direito ao apagamento não depende do estado da conta.
     *
     * @return array<string, array{string}>
     */
    public static function acceptedStates(): array
    {
        return [
            'pendente' => ['pending'],
            'aprovada, à espera de ativação' => ['approved'],
            'ativa em período experimental' => ['trial'],
            'ativa em período de carência' => ['grace'],
            'inativa por falta de pagamento' => ['unpaid'],
            'inativa por bloqueio' => ['blocked'],
        ];
    }

    private function memberInState(string $state): User
    {
        $factory = User::factory();

        $user = match ($state) {
            'pending' => $factory->pending()->create(),
            'approved' => $factory->approved()->create(),
            'trial' => $factory->create(['trial_ends_at' => now()->addDays(20)]),
            'grace' => $factory->create(['grace_ends_at' => now()->addDays(5)]),
            'unpaid' => $factory->inactive(InactiveReason::Unpaid)->create(),
            'blocked' => $factory->inactive(InactiveReason::Blocked)->create(),
        };

        return $this->memberWithProfile($user);
    }

    #[Test]
    #[DataProvider('acceptedStates')]
    public function every_non_admin_account_state_can_be_anonymized(string $state): void
    {
        $admin = $this->actingAsAdmin();
        $member = $this->memberInState($state);

        $this->postJson($this->url($member->id))
            ->assertOk()
            ->assertJsonPath('data.id', $member->id);

        $this->assertSoftDeleted('users', ['id' => $member->id]);
        $this->assertDatabaseHas('account_anonymizations', [
            'user_id' => $member->id,
            'anonymized_by_user_id' => $admin->id,
        ]);
    }

    #[Test]
    public function paying_admin_with_a_profile_cannot_be_anonymized(): void
    {
        $this->actingAsAdmin();
        $otherAdmin = $this->memberWithProfile(User::factory()->admin()->create());

        $this->postJson($this->url($otherAdmin->id))
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', self::ADMIN_TARGET_MESSAGE);

        $this->assertUntouched($otherAdmin);
        $this->assertNotSoftDeleted('member_profiles', [
            'user_id' => $otherAdmin->id,
            'business_name' => 'Marques Consultoria Lda',
        ]);
        Notification::assertNothingSent();
    }

    #[Test]
    public function admin_cannot_anonymize_himself(): void
    {
        $admin = $this->actingAsAdmin();

        $this->postJson($this->url($admin->id))
            ->assertStatus(409)
            ->assertJsonPath('message', self::ADMIN_TARGET_MESSAGE);

        $this->assertUntouched($admin);
    }

    #[Test]
    public function technical_admin_cannot_be_anonymized(): void
    {
        $this->actingAsAdmin();
        $technicalAdmin = User::factory()->technicalAdmin()->create();

        $this->postJson($this->url($technicalAdmin->id))
            ->assertStatus(409)
            ->assertJsonPath('message', self::ADMIN_TARGET_MESSAGE);

        $this->assertUntouched($technicalAdmin);
    }

    #[Test]
    public function refused_admin_target_never_reaches_stripe(): void
    {
        $this->mock(SubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('cancel');
        });
        $this->actingAsAdmin();
        $otherAdmin = User::factory()->admin()->create();

        $this->postJson($this->url($otherAdmin->id))->assertStatus(409);
    }

    #[Test]
    public function second_call_for_the_same_account_returns_404_and_keeps_one_record(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();

        $this->postJson($this->url($member->id))->assertOk();

        $this->postJson($this->url($member->id))
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', self::NOT_FOUND_MESSAGE);

        $this->assertDatabaseCount('account_anonymizations', 1);
    }

    #[Test]
    public function unknown_uuid_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->postJson($this->url('9f1c2b3a-0000-4000-8000-000000000000'))
            ->assertNotFound()
            ->assertJsonPath('message', self::NOT_FOUND_MESSAGE);

        $this->assertDatabaseCount('account_anonymizations', 0);
    }

    #[Test]
    public function non_uuid_id_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->postJson($this->url('nao-e-um-uuid'))
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function active_member_who_is_not_admin_gets_403(): void
    {
        $member = User::factory()->create();
        Sanctum::actingAs($member);
        $target = $this->memberWithProfile();

        $this->postJson($this->url($target->id))
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Não tem permissão para realizar esta ação.');

        $this->assertUntouched($target);
    }

    #[Test]
    public function inactive_admin_gets_403(): void
    {
        Sanctum::actingAs(User::factory()->admin()->inactive(InactiveReason::Blocked)->create());
        $target = $this->memberWithProfile();

        $this->postJson($this->url($target->id))
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertUntouched($target);
    }

    #[Test]
    public function unauthenticated_request_gets_401(): void
    {
        $target = $this->memberWithProfile();

        $this->postJson($this->url($target->id))
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Não autenticado.');

        $this->assertUntouched($target);
    }

    #[Test]
    public function sixth_request_in_the_same_hour_is_throttled(): void
    {
        $this->actingAsAdmin();
        $members = User::factory()->count(6)->create();

        foreach ($members->take(5) as $member) {
            $this->postJson($this->url($member->id))->assertOk();
        }

        $this->postJson($this->url($members->last()->id))
            ->assertTooManyRequests()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Demasiados pedidos. Tente novamente mais tarde.');

        $this->assertUntouched($members->last());
        $this->assertDatabaseCount('account_anonymizations', 5);
    }

    #[Test]
    public function throttle_resets_after_an_hour(): void
    {
        $this->actingAsAdmin();
        $members = User::factory()->count(6)->create();

        foreach ($members->take(5) as $member) {
            $this->postJson($this->url($member->id))->assertOk();
        }

        $this->travel(61)->minutes();

        $this->postJson($this->url($members->last()->id))->assertOk();
    }

    #[Test]
    public function throttle_is_counted_per_admin(): void
    {
        $this->actingAsAdmin();
        $members = User::factory()->count(6)->create();

        foreach ($members->take(5) as $member) {
            $this->postJson($this->url($member->id))->assertOk();
        }

        $this->actingAsAdmin();

        $this->postJson($this->url($members->last()->id))->assertOk();
    }

    #[Test]
    public function subscription_is_cancelled_before_the_account_is_anonymized(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();
        $member->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_active',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        $stateAtCancel = null;
        $this->mock(SubscriptionService::class, function (MockInterface $mock) use ($member, &$stateAtCancel) {
            $mock->shouldReceive('cancel')
                ->once()
                ->withArgs(fn (User $user) => $user->id === $member->id)
                ->andReturnUsing(function () use ($member, &$stateAtCancel) {
                    $stateAtCancel = [
                        'stripe_status' => DB::table('subscriptions')->where('stripe_id', 'sub_test_active')->value('stripe_status'),
                        'deleted' => User::withTrashed()->find($member->id)->trashed(),
                    ];
                });
        });

        $this->postJson($this->url($member->id))->assertOk();

        $this->assertSame(['stripe_status' => 'active', 'deleted' => false], $stateAtCancel);
        $this->assertSoftDeleted('users', ['id' => $member->id]);
    }

    #[Test]
    public function active_subscription_is_cancelled_in_stripe(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberWithProfile(User::factory()->withBilling()->create());
        $member->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_active',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelNow')
                ->once()
                ->withArgs(fn ($subscription) => $subscription->stripe_id === 'sub_test_active');
        });

        $this->postJson($this->url($member->id))->assertOk();

        $this->assertDatabaseHas('subscriptions', ['stripe_id' => 'sub_test_active', 'stripe_status' => 'canceled']);
    }

    #[Test]
    public function open_checkout_session_of_an_approved_member_is_expired(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberWithProfile(User::factory()->approved()->create());
        $member->forceFill(['stripe_checkout_session_id' => 'cs_test_open'])->save();

        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('expireCheckout')
                ->once()
                ->with('cs_test_open');
        });

        $this->postJson($this->url($member->id))->assertOk();

        $this->assertNull(User::withTrashed()->find($member->id)->stripe_checkout_session_id);
    }

    #[Test]
    public function confirmation_email_is_sent_to_the_original_address(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();
        $originalEmail = $member->email;

        $this->postJson($this->url($member->id))->assertOk();

        Notification::assertSentOnDemandTimes(AccountAnonymizedNotification::class, 1);
        Notification::assertSentOnDemand(
            AccountAnonymizedNotification::class,
            fn ($notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routeNotificationFor('mail') === $originalEmail
        );
    }

    #[Test]
    public function confirmation_email_never_goes_to_the_anonymized_address(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();

        $this->postJson($this->url($member->id))->assertOk();

        Notification::assertNotSentTo(User::withTrashed()->find($member->id), AccountAnonymizedNotification::class);
        Notification::assertSentOnDemand(
            AccountAnonymizedNotification::class,
            fn ($notification, array $channels, AnonymousNotifiable $notifiable) => ! str_starts_with((string) $notifiable->routeNotificationFor('mail'), 'deleted_')
        );
    }

    #[Test]
    public function confirmation_email_is_sent_synchronously_by_mail(): void
    {
        // Em fila, o email original ficava guardado na tabela jobs depois de a conta já estar anonimizada.
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();

        $this->postJson($this->url($member->id))->assertOk();

        Notification::assertSentOnDemand(
            AccountAnonymizedNotification::class,
            fn ($notification, array $channels) => ! $notification instanceof ShouldQueue && $channels === ['mail']
        );
    }

    #[Test]
    public function confirmation_email_has_no_personal_data(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();

        $this->postJson($this->url($member->id))->assertOk();

        Notification::assertSentOnDemand(
            AccountAnonymizedNotification::class,
            function ($notification, array $channels, AnonymousNotifiable $notifiable) {
                $rendered = (string) $notification->toMail($notifiable)->render();

                return ! str_contains($rendered, 'Ana Sofia Marques')
                    && ! str_contains($rendered, 'Marques Consultoria Lda');
            }
        );
    }

    #[Test]
    public function email_failure_does_not_change_the_response(): void
    {
        Log::spy();
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();
        $originalEmail = $member->email;

        $failingChannels = $this->mock(ChannelManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('send')->andThrow(new TransportException('Ligação SMTP recusada'));
            $mock->shouldReceive('sendNow')->andThrow(new TransportException('Ligação SMTP recusada'));
        });
        Notification::swap($failingChannels);

        $this->postJson($this->url($member->id))
            ->assertOk()
            ->assertJsonPath('message', self::SUCCESS_MESSAGE);

        $this->assertSoftDeleted('users', ['id' => $member->id]);
        $this->assertDatabaseHas('account_anonymizations', ['user_id' => $member->id]);
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($member, $originalEmail) {
                $logged = $message.json_encode($context, JSON_UNESCAPED_UNICODE);

                return ($context['user_id'] ?? null) === $member->id
                    && ! str_contains($logged, $originalEmail);
            })
            ->once();
    }

    #[Test]
    public function future_registrations_are_deleted_and_past_ones_kept(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberWithProfile();

        $pastEvent = EventInstance::factory()->create([
            'event_date' => now()->subMonth()->toDateString(),
            'registration_deadline' => now()->subMonth()->subDay()->format('Y-m-d H:i:s'),
        ]);
        $futureEvent = EventInstance::factory()->create();
        $past = EventRegistration::query()->create(['event_instance_id' => $pastEvent->id, 'user_id' => $member->id]);
        $future = EventRegistration::query()->create(['event_instance_id' => $futureEvent->id, 'user_id' => $member->id]);

        $this->postJson($this->url($member->id))->assertOk();

        $this->assertDatabaseHas('event_registrations', ['id' => $past->id]);
        $this->assertDatabaseMissing('event_registrations', ['id' => $future->id]);
    }

    /**
     * Estados que o webhook do fim da subscrição trataria se a conta continuasse como estava.
     *
     * @return array<string, array{string}>
     */
    public static function statesDeactivatedBeforeCancel(): array
    {
        return [
            'pendente' => ['pending'],
            'aprovada, à espera de ativação' => ['approved'],
            'ativa em período experimental' => ['trial'],
            'ativa em período de carência' => ['grace'],
            'inativa por falta de pagamento' => ['unpaid'],
        ];
    }

    #[Test]
    #[DataProvider('statesDeactivatedBeforeCancel')]
    public function account_is_inactive_with_reason_deleted_before_the_subscription_is_cancelled(string $state): void
    {
        $this->actingAsAdmin();
        $member = $this->memberInState($state);

        $stateAtCancel = null;
        $this->mock(SubscriptionService::class, function (MockInterface $mock) use ($member, &$stateAtCancel) {
            $mock->shouldReceive('cancel')
                ->once()
                ->andReturnUsing(function () use ($member, &$stateAtCancel) {
                    $user = User::withTrashed()->with('accountStatus')->find($member->id);
                    $stateAtCancel = [$user->accountStatus->name, $user->inactive_reason, $user->trashed()];
                });
        });

        $this->postJson($this->url($member->id))->assertOk();

        $this->assertSame(['Inactive', InactiveReason::Deleted, false], $stateAtCancel);
    }

    #[Test]
    public function blocked_account_is_still_not_active_when_the_subscription_is_cancelled(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberInState('blocked');

        $stateAtCancel = null;
        $this->mock(SubscriptionService::class, function (MockInterface $mock) use ($member, &$stateAtCancel) {
            $mock->shouldReceive('cancel')
                ->once()
                ->andReturnUsing(function () use ($member, &$stateAtCancel) {
                    $stateAtCancel = User::with('accountStatus')->find($member->id)->accountStatus->name;
                });
        });

        $this->postJson($this->url($member->id))->assertOk();

        $this->assertSame('Inactive', $stateAtCancel);
        $this->assertSame(InactiveReason::Deleted, User::withTrashed()->find($member->id)->inactive_reason);
    }

    #[Test]
    public function subscription_deleted_webhook_during_the_cancellation_sends_no_reactivation_link(): void
    {
        $this->actingAsAdmin();
        $member = $this->memberWithProfile(User::factory()->withBilling()->create());
        $member->forceFill(['stripe_id' => 'cus_test_race'])->save();
        $member->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_race',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        $this->mock(StripeSubscriptionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelNow')
                ->once()
                ->andReturnUsing(function () {
                    $this->postStripeWebhook($this->stripeEvent(
                        'customer.subscription.deleted',
                        $this->stripeSubscription('cus_test_race', 'canceled', 'sub_test_race'),
                    ))->assertOk();
                });
        });
        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('delete')->once()->with('cus_test_race');
        });

        $this->postJson($this->url($member->id))->assertOk();

        Notification::assertNotSentTo(User::withTrashed()->find($member->id), ReactivationLinkNotification::class);
        $this->assertSoftDeleted('users', ['id' => $member->id, 'inactive_reason' => InactiveReason::Deleted->value]);
    }
}
