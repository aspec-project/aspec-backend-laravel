<?php

namespace Tests\Feature\Member;

use App\Enums\InactiveReason;
use App\Jobs\DeleteStripeCustomerJob;
use App\Models\AccountStatus;
use App\Models\Invoice;
use App\Models\MemberProfile;
use App\Models\Portfolio;
use App\Models\SocialPlatform;
use App\Models\User;
use App\Models\WeekDay;
use App\Services\Payments\StripeCustomerService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Stripe\Exception\ApiConnectionException;
use Tests\TestCase;

class AnonymizeAndDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function memberWithFullProfile(): User
    {
        $user = User::factory()->create();
        $profile = MemberProfile::factory()->create([
            'user_id' => $user->id,
            'logo_path' => "logos/{$user->id}/logo.png",
        ]);

        $profile->weekDays()->attach(WeekDay::find(1), ['open_time' => '09:00', 'close_time' => '18:00']);
        $profile->socialPlatforms()->attach(SocialPlatform::first(), ['url' => 'https://example.com/perfil']);
        Portfolio::create(['profile_id' => $profile->id, 'image_path' => "portfolios/{$user->id}/a.jpg"]);

        Storage::disk('public')->put("logos/{$user->id}/logo.png", 'conteudo');
        Storage::disk('public')->put("portfolios/{$user->id}/a.jpg", 'conteudo');

        return $user;
    }

    private function insertSession(?string $userId): string
    {
        $id = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => now()->getTimestamp(),
        ]);

        return $id;
    }

    #[Test]
    public function business_hours_social_links_and_portfolio_are_hard_deleted(): void
    {
        $user = $this->memberWithFullProfile();
        $profileId = $user->memberProfile->id;

        $user->anonymizeAndDelete();

        $this->assertDatabaseMissing('business_hours', ['profile_id' => $profileId]);
        $this->assertDatabaseMissing('social_links', ['profile_id' => $profileId]);
        $this->assertDatabaseMissing('portfolios', ['profile_id' => $profileId]);
    }

    #[Test]
    public function logo_and_portfolio_folders_are_removed(): void
    {
        $user = $this->memberWithFullProfile();

        $user->anonymizeAndDelete();

        Storage::disk('public')->assertMissing("logos/{$user->id}/logo.png");
        Storage::disk('public')->assertMissing("portfolios/{$user->id}/a.jpg");
        $this->assertFalse(Storage::disk('public')->directoryExists("logos/{$user->id}"));
        $this->assertFalse(Storage::disk('public')->directoryExists("portfolios/{$user->id}"));
    }

    #[Test]
    public function other_members_files_are_kept(): void
    {
        $user = $this->memberWithFullProfile();
        $other = $this->memberWithFullProfile();

        $user->anonymizeAndDelete();

        Storage::disk('public')->assertExists("logos/{$other->id}/logo.png");
        Storage::disk('public')->assertExists("portfolios/{$other->id}/a.jpg");
    }

    #[Test]
    public function profile_is_anonymized_and_soft_deleted(): void
    {
        $user = $this->memberWithFullProfile();
        $profile = $user->memberProfile;

        $user->anonymizeAndDelete();

        $this->assertSoftDeleted('member_profiles', [
            'id' => $profile->id,
            'name' => 'Utilizador Anónimo',
            'business_name' => 'Empresa Removida',
            'logo_path' => null,
            'description' => null,
            'website_url' => null,
            'commercial_contacts' => null,
            'address' => null,
        ]);
    }

    #[Test]
    public function user_is_anonymized_and_soft_deleted(): void
    {
        $user = $this->memberWithFullProfile();
        $originalEmail = $user->email;

        $user->anonymizeAndDelete();

        $this->assertSoftDeleted('users', ['id' => $user->id, 'phone' => '000000000']);

        $fresh = User::withTrashed()->find($user->id);
        $this->assertNotSame($originalEmail, $fresh->email);
        $this->assertMatchesRegularExpression('/^deleted_.+@aspec\.local$/', $fresh->email);
        // A factory cria os users com a password "password".
        $this->assertFalse(Hash::check('password', $fresh->password));
    }

    #[Test]
    public function user_without_profile_is_anonymized_without_exception(): void
    {
        $admin = User::factory()->admin()->create();
        $originalEmail = $admin->email;

        $admin->anonymizeAndDelete();

        $this->assertSoftDeleted('users', ['id' => $admin->id, 'phone' => '000000000']);
        $this->assertNotSame($originalEmail, User::withTrashed()->find($admin->id)->email);
    }

    #[Test]
    public function user_without_profile_has_logo_folder_removed(): void
    {
        $admin = User::factory()->admin()->create();
        Storage::disk('public')->put("logos/{$admin->id}/logo.png", 'conteudo');

        $admin->anonymizeAndDelete();

        Storage::disk('public')->assertMissing("logos/{$admin->id}/logo.png");
    }

    #[Test]
    public function all_tokens_are_revoked(): void
    {
        $user = $this->memberWithFullProfile();
        $other = $this->memberWithFullProfile();
        $user->createToken('web');
        $user->createToken('mobile');
        $other->createToken('web');

        $user->anonymizeAndDelete();

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $other->id]);
    }

    #[Test]
    public function account_fields_are_cleared_and_status_is_inactive(): void
    {
        $user = $this->memberWithFullProfile();
        $user->forceFill(['remember_token' => 'abc123', 'trial_ends_at' => now()->addDays(30)])->save();

        $user->anonymizeAndDelete();

        $fresh = User::withTrashed()->find($user->id);
        $this->assertNull($fresh->remember_token);
        $this->assertNull($fresh->email_verified_at);
        $this->assertNull($fresh->trial_ends_at);
        $this->assertSame(AccountStatus::where('name', 'Inactive')->value('id'), $fresh->account_status_id);
    }

    #[Test]
    public function billing_and_payment_state_are_cleared_and_reason_is_deleted(): void
    {
        $user = $this->memberWithFullProfile();
        $user->forceFill([
            'billing_name' => 'Silva Contabilidade Lda',
            'nif' => '245678901',
            'billing_address' => 'Rua da Faturação 10',
            'billing_postal_code' => '1000-001',
            'billing_city' => 'Lisboa',
            'grace_ends_at' => now()->addDays(7),
            'stripe_checkout_session_id' => 'cs_test_open_session',
            'inactive_reason' => InactiveReason::Blocked,
        ])->save();

        $user->anonymizeAndDelete();

        $fresh = User::withTrashed()->find($user->id);
        $this->assertNull($fresh->billing_name);
        $this->assertNull($fresh->nif);
        $this->assertNull($fresh->billing_address);
        $this->assertNull($fresh->billing_postal_code);
        $this->assertNull($fresh->billing_city);
        $this->assertNull($fresh->grace_ends_at);
        $this->assertNull($fresh->stripe_checkout_session_id);
        // "deleted" substitui qualquer motivo anterior, incluindo "blocked": a conta deixou de existir.
        $this->assertSame(InactiveReason::Deleted, $fresh->inactive_reason);
    }

    #[Test]
    public function already_soft_deleted_profile_is_anonymized(): void
    {
        $user = $this->memberWithFullProfile();
        $profile = $user->memberProfile;
        $profile->delete();

        $user->refresh()->anonymizeAndDelete();

        $this->assertSoftDeleted('member_profiles', ['id' => $profile->id, 'name' => 'Utilizador Anónimo']);
        $this->assertDatabaseMissing('business_hours', ['profile_id' => $profile->id]);
        $this->assertDatabaseMissing('social_links', ['profile_id' => $profile->id]);
        $this->assertDatabaseMissing('portfolios', ['profile_id' => $profile->id]);
    }

    #[Test]
    public function files_are_kept_when_an_outer_transaction_rolls_back(): void
    {
        $user = $this->memberWithFullProfile();

        DB::beginTransaction();
        $user->anonymizeAndDelete();
        DB::rollBack();

        Storage::disk('public')->assertExists("logos/{$user->id}/logo.png");
        Storage::disk('public')->assertExists("portfolios/{$user->id}/a.jpg");
        $this->assertNotSoftDeleted('users', ['id' => $user->id]);
    }

    #[Test]
    public function other_members_data_is_untouched(): void
    {
        $user = $this->memberWithFullProfile();
        $other = $this->memberWithFullProfile();
        $otherProfile = $other->memberProfile;

        $user->anonymizeAndDelete();

        $this->assertNotSoftDeleted('users', ['id' => $other->id, 'email' => $other->email]);
        $this->assertNotSoftDeleted('member_profiles', ['id' => $otherProfile->id, 'name' => $otherProfile->name]);
        $this->assertSame(1, $otherProfile->weekDays()->count());
        $this->assertSame(1, $otherProfile->socialPlatforms()->count());
        $this->assertSame(1, $otherProfile->portfolios()->count());
    }

    #[Test]
    public function all_sessions_of_the_user_are_deleted(): void
    {
        $user = $this->memberWithFullProfile();
        $this->insertSession($user->id);
        $this->insertSession($user->id);

        $user->anonymizeAndDelete();

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    #[Test]
    public function other_users_sessions_are_kept(): void
    {
        $user = $this->memberWithFullProfile();
        $other = $this->memberWithFullProfile();
        $this->insertSession($user->id);
        $otherSessionId = $this->insertSession($other->id);
        // Sessão de visitante (user_id null): não pode ser apanhada pelo delete.
        $guestSessionId = $this->insertSession(null);

        $user->anonymizeAndDelete();

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => $otherSessionId, 'user_id' => $other->id]);
        $this->assertDatabaseHas('sessions', ['id' => $guestSessionId, 'user_id' => null]);
    }

    #[Test]
    public function sessions_are_kept_when_an_outer_transaction_rolls_back(): void
    {
        $user = $this->memberWithFullProfile();
        $sessionId = $this->insertSession($user->id);

        DB::beginTransaction();
        $user->anonymizeAndDelete();
        DB::rollBack();

        $this->assertDatabaseHas('sessions', ['id' => $sessionId, 'user_id' => $user->id]);
        $this->assertNotSoftDeleted('users', ['id' => $user->id]);
    }

    private function memberWithStripeCustomer(): User
    {
        $user = $this->memberWithFullProfile();
        $user->forceFill([
            'stripe_id' => 'cus_test123',
            'pm_type' => 'visa',
            'pm_last_four' => '4242',
            'billing_name' => 'Silva Contabilidade Lda',
            'nif' => '245678901',
            'billing_address' => 'Rua da Faturação 10',
            'billing_postal_code' => '1000-001',
            'billing_city' => 'Lisboa',
        ])->save();

        return $user;
    }

    #[Test]
    public function user_without_stripe_customer_never_calls_stripe(): void
    {
        Queue::fake();
        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('delete');
        });
        $admin = User::factory()->admin()->create();
        $pending = User::factory()->pending()->create();

        $admin->anonymizeAndDelete();
        $pending->anonymizeAndDelete();

        Queue::assertNotPushed(DeleteStripeCustomerJob::class);
        $this->assertSoftDeleted('users', ['id' => $admin->id]);
        $this->assertSoftDeleted('users', ['id' => $pending->id]);
    }

    #[Test]
    public function stripe_customer_is_deleted_and_stripe_columns_are_cleared(): void
    {
        Queue::fake();
        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('delete')->once()->with('cus_test123');
        });
        $user = $this->memberWithStripeCustomer();

        $user->anonymizeAndDelete();

        $fresh = User::withTrashed()->find($user->id);
        $this->assertNull($fresh->stripe_id);
        $this->assertNull($fresh->pm_type);
        $this->assertNull($fresh->pm_last_four);
        Queue::assertNotPushed(DeleteStripeCustomerJob::class);
    }

    #[Test]
    public function stripe_failure_still_anonymizes_and_schedules_the_fallback_job(): void
    {
        Queue::fake();
        Log::spy();
        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('delete')->once()->with('cus_test123')
                ->andThrow(ApiConnectionException::factory('falha'));
        });
        $user = $this->memberWithStripeCustomer();

        $user->anonymizeAndDelete();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $fresh = User::withTrashed()->find($user->id);
        $this->assertMatchesRegularExpression('/^deleted_.+@aspec\.local$/', $fresh->email);
        $this->assertNull($fresh->billing_name);
        $this->assertNull($fresh->nif);
        $this->assertNull($fresh->billing_address);
        $this->assertNull($fresh->pm_type);
        $this->assertNull($fresh->pm_last_four);
        // O job de recurso precisa do stripe_id para voltar a tentar apagar o cliente.
        $this->assertSame('cus_test123', $fresh->stripe_id);
        Queue::assertPushed(DeleteStripeCustomerJob::class, fn ($job) => $job->userId === $user->id);
    }

    #[Test]
    public function fallback_job_is_dispatched_only_after_commit(): void
    {
        Queue::fake();
        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('delete')->andThrow(ApiConnectionException::factory('falha'));
        });
        $user = $this->memberWithStripeCustomer();

        $user->anonymizeAndDelete();

        Queue::assertPushed(DeleteStripeCustomerJob::class, fn ($job) => $job->afterCommit === true
            || $job instanceof ShouldQueueAfterCommit);
    }

    #[Test]
    public function stripe_failure_is_logged_without_personal_data(): void
    {
        Queue::fake();
        Log::spy();
        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('delete')->andThrow(ApiConnectionException::factory('falha'));
        });
        $user = $this->memberWithStripeCustomer();
        $originalEmail = $user->email;

        $user->anonymizeAndDelete();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($user, $originalEmail) {
                $logged = $message.json_encode($context, JSON_UNESCAPED_UNICODE);

                return ($context['user_id'] ?? null) === $user->id
                    && ! str_contains($logged, $originalEmail)
                    && ! str_contains($logged, '245678901');
            })
            ->once();
    }

    private function subscribe(User $user, string $stripeId, string $status): void
    {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => $stripeId,
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'trial_ends_at' => $status === 'trialing' ? now()->addDays(30) : null,
        ]);
    }

    private function assertAllSubscriptionsCanceled(User $user): void
    {
        $subscriptions = DB::table('subscriptions')->where('user_id', $user->id)->get();

        $this->assertCount(2, $subscriptions);
        foreach ($subscriptions as $subscription) {
            $this->assertSame('canceled', $subscription->stripe_status, "{$subscription->stripe_id} devia estar cancelada");
            $this->assertNotNull($subscription->ends_at, "{$subscription->stripe_id} devia ter ends_at");
        }
    }

    #[Test]
    public function local_subscriptions_are_canceled_when_the_account_is_anonymized(): void
    {
        Queue::fake();
        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('delete')->once()->with('cus_test123');
        });
        $user = $this->memberWithStripeCustomer();
        $this->subscribe($user, 'sub_test_active', 'active');
        $this->subscribe($user, 'sub_test_trialing', 'trialing');

        $user->anonymizeAndDelete();

        $this->assertAllSubscriptionsCanceled($user);
        $this->assertFalse(User::withTrashed()->find($user->id)->subscribed());
    }

    #[Test]
    public function local_subscriptions_are_canceled_even_when_stripe_fails(): void
    {
        Queue::fake();
        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('delete')->andThrow(ApiConnectionException::factory('falha'));
        });
        $user = $this->memberWithStripeCustomer();
        $this->subscribe($user, 'sub_test_active', 'active');
        $this->subscribe($user, 'sub_test_trialing', 'trialing');

        $user->anonymizeAndDelete();

        $this->assertAllSubscriptionsCanceled($user);
        Queue::assertPushed(DeleteStripeCustomerJob::class);
    }

    #[Test]
    public function other_members_subscriptions_are_untouched(): void
    {
        Queue::fake();
        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('delete');
        });
        $user = $this->memberWithStripeCustomer();
        $this->subscribe($user, 'sub_test_active', 'active');
        $other = $this->memberWithFullProfile();
        $this->subscribe($other, 'sub_test_other', 'active');

        $user->anonymizeAndDelete();

        $this->assertDatabaseHas('subscriptions', [
            'stripe_id' => 'sub_test_other',
            'user_id' => $other->id,
            'stripe_status' => 'active',
            'ends_at' => null,
        ]);
        $this->assertTrue($other->fresh()->subscribed());
    }

    #[Test]
    public function invoices_are_kept_after_anonymization(): void
    {
        // Obrigação fiscal: as faturas sobrevivem ao apagamento dos dados pessoais.
        Queue::fake();
        $this->mock(StripeCustomerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('delete')->once()->with('cus_test123');
        });
        $user = $this->memberWithStripeCustomer();
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'issued', 'number' => 'LOG-2026-AAAAAAAA']);

        $user->anonymizeAndDelete();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'user_id' => $user->id,
            'stripe_invoice_id' => $invoice->stripe_invoice_id,
            'number' => 'LOG-2026-AAAAAAAA',
            'status' => 'issued',
        ]);
    }
}
