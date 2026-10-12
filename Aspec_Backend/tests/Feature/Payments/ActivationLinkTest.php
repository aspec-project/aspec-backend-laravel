<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\ActivationLinkNotification;
use App\Notifications\ReactivationLinkNotification;
use App\Notifications\SubscriptionActivatedNotification;
use App\Services\Payments\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsActivationLinks;
use Tests\TestCase;

/**
 * Links assinados de ativação/reativação (apontam para o frontend, validade configurável)
 * e os emails que os levam (em fila, sem guardar o link na fila).
 */
class ActivationLinkTest extends TestCase
{
    use BuildsActivationLinks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTestFrontendUrl();
        config([
            'subscription.trial_days' => 30,
            'subscription.price_amount' => 60.00,
            'subscription.activation_link_days' => 7,
            'subscription.reactivation_link_days' => 7,
        ]);
    }

    private function service(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    private function memberWithProfile(User $user, string $name = 'Ana Ferreira'): User
    {
        MemberProfile::factory()->create(['user_id' => $user->id, 'name' => $name]);

        return $user->fresh();
    }

    /**
     * Junta assunto, saudação e linhas do email num só texto, para procurar frases.
     */
    private function mailText(MailMessage $mail): string
    {
        return implode("\n", array_merge(
            [$mail->subject, $mail->greeting, $mail->actionText],
            $mail->introLines,
            $mail->outroLines,
        ));
    }

    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    #[Test]
    public function activation_url_points_to_the_frontend_activation_page_with_signature(): void
    {
        $user = User::factory()->approved()->create();

        $url = $this->service()->activationUrl($user);

        $this->assertStringStartsWith(self::FRONTEND_URL."/ativacao/{$user->id}?", $url);
        $query = $this->queryOf($url);
        $this->assertArrayHasKey('expires', $query);
        $this->assertArrayHasKey('signature', $query);
    }

    #[Test]
    public function activation_link_expires_after_the_configured_activation_days(): void
    {
        $user = User::factory()->approved()->create();

        $query = $this->queryOf($this->service()->activationUrl($user));

        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, (int) $query['expires'], 5);
    }

    #[Test]
    public function reactivation_link_expires_after_the_configured_reactivation_days(): void
    {
        config(['subscription.reactivation_link_days' => 3]);
        $user = User::factory()->inactive(InactiveReason::Unpaid)->create();

        $query = $this->queryOf($this->service()->activationUrl($user));

        $this->assertEqualsWithDelta(now()->addDays(3)->timestamp, (int) $query['expires'], 5);
    }

    #[Test]
    public function activation_url_does_not_depend_on_a_trailing_slash_in_the_frontend_url(): void
    {
        config(['app.frontend_url' => self::FRONTEND_URL.'/']);
        $user = User::factory()->approved()->create();

        $url = $this->service()->activationUrl($user);

        $this->assertStringStartsWith(self::FRONTEND_URL."/ativacao/{$user->id}?", $url);
    }

    #[Test]
    public function link_from_the_activation_email_is_accepted_by_the_api(): void
    {
        Notification::fake();
        $user = User::factory()->approved()->create();

        $this->service()->sendActivationLink($user);

        $url = null;
        Notification::assertSentTo($user, ActivationLinkNotification::class, function ($notification) use ($user, &$url) {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });

        $this->getJson($this->toApiPath($url))
            ->assertOk()
            ->assertJsonPath('data.type', 'activation');
    }

    #[Test]
    public function send_activation_link_queues_one_email_with_link_trial_and_price(): void
    {
        Notification::fake();
        $user = $this->memberWithProfile(User::factory()->approved()->create());

        $this->service()->sendActivationLink($user);

        Notification::assertSentToTimes($user, ActivationLinkNotification::class, 1);
        Notification::assertSentTo($user, ActivationLinkNotification::class, function ($notification, array $channels) use ($user) {
            $mail = $notification->toMail($user);
            $text = $this->mailText($mail);

            return $channels === ['mail']
                && str_starts_with($mail->actionUrl, self::FRONTEND_URL."/ativacao/{$user->id}?")
                && str_contains($mail->actionUrl, 'signature=')
                && $mail->subject === 'Ative a sua conta ASPEC'
                && $mail->greeting === 'Olá, Ana Ferreira!'
                && $mail->actionText === 'Ativar conta'
                && str_contains($text, '30 dias')
                && str_contains($text, '60,00')
                && str_contains($text, 'válido durante 7 dias');
        });
    }

    #[Test]
    public function send_reactivation_link_queues_one_email_without_a_new_trial(): void
    {
        Notification::fake();
        $user = $this->memberWithProfile(User::factory()->inactive(InactiveReason::Unpaid)->withBilling()->create());

        $this->service()->sendReactivationLink($user);

        Notification::assertNotSentTo($user, ActivationLinkNotification::class);
        Notification::assertSentToTimes($user, ReactivationLinkNotification::class, 1);
        Notification::assertSentTo($user, ReactivationLinkNotification::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);
            $text = $this->mailText($mail);

            return str_starts_with($mail->actionUrl, self::FRONTEND_URL."/ativacao/{$user->id}?")
                && $mail->subject === 'Reative a sua conta ASPEC'
                && $mail->actionText === 'Reativar conta'
                && str_contains($text, '60,00')
                && str_contains($text, 'sem novo período experimental');
        });
    }

    #[Test]
    public function activation_email_tells_the_member_to_request_a_new_link_on_the_frontend(): void
    {
        $user = $this->memberWithProfile(User::factory()->approved()->create());

        $text = $this->mailText((new ActivationLinkNotification)->toMail($user));

        $this->assertStringContainsString(self::FRONTEND_URL.'/ativacao/novo-link', $text);
        $this->assertStringContainsString('peça um novo', $text);
        $this->assertStringNotContainsString('contacte a ASPEC', $text);
    }

    #[Test]
    public function reactivation_email_tells_the_member_to_request_a_new_link_on_the_frontend(): void
    {
        $user = $this->memberWithProfile(User::factory()->inactive(InactiveReason::Unpaid)->withBilling()->create());

        $text = $this->mailText((new ReactivationLinkNotification)->toMail($user));

        $this->assertStringContainsString(self::FRONTEND_URL.'/ativacao/novo-link', $text);
        $this->assertStringContainsString('peça um novo', $text);
        $this->assertStringNotContainsString('contacte a ASPEC', $text);
    }

    #[Test]
    public function new_link_page_in_the_email_does_not_depend_on_a_trailing_slash_in_the_frontend_url(): void
    {
        config(['app.frontend_url' => self::FRONTEND_URL.'/']);
        $user = $this->memberWithProfile(User::factory()->approved()->create());

        $text = $this->mailText((new ActivationLinkNotification)->toMail($user));

        $this->assertStringContainsString(self::FRONTEND_URL.'/ativacao/novo-link', $text);
        $this->assertStringNotContainsString(self::FRONTEND_URL.'//ativacao', $text);
    }

    #[Test]
    public function queued_activation_notification_does_not_store_the_signed_link(): void
    {
        $serialized = serialize(new ActivationLinkNotification);

        $this->assertStringNotContainsString('signature', $serialized);
        $this->assertStringNotContainsString('/ativacao/', $serialized);
    }

    #[Test]
    public function queued_reactivation_notification_does_not_store_the_signed_link(): void
    {
        $serialized = serialize(new ReactivationLinkNotification);

        $this->assertStringNotContainsString('signature', $serialized);
        $this->assertStringNotContainsString('/ativacao/', $serialized);
    }

    #[Test]
    public function payment_notifications_are_queued_and_only_sent_after_commit(): void
    {
        $notifications = [
            new ActivationLinkNotification,
            new ReactivationLinkNotification,
            new SubscriptionActivatedNotification(now()->addDays(30)),
        ];

        foreach ($notifications as $notification) {
            $this->assertInstanceOf(ShouldQueue::class, $notification);
            $this->assertTrue($notification->afterCommit, $notification::class.' devia ter afterCommit.');
        }
    }

    #[Test]
    public function welcome_email_with_trial_shows_the_trial_end_date_and_price(): void
    {
        $user = $this->memberWithProfile(User::factory()->create());
        $trialEndsAt = now()->addDays(30);

        $mail = (new SubscriptionActivatedNotification($trialEndsAt))->toMail($user);
        $text = $this->mailText($mail);

        $this->assertSame('A sua conta ASPEC está ativa', $mail->subject);
        $this->assertStringContainsString($trialEndsAt->format('d/m/Y'), $text);
        $this->assertStringContainsString('60,00', $text);
        $this->assertSame(self::FRONTEND_URL.'/login', $mail->actionUrl);
    }

    #[Test]
    public function welcome_email_without_trial_says_the_account_was_reactivated(): void
    {
        $user = $this->memberWithProfile(User::factory()->create());

        $mail = (new SubscriptionActivatedNotification(null))->toMail($user);

        $this->assertStringContainsString('A sua conta foi reativada.', $this->mailText($mail));
        $this->assertSame(self::FRONTEND_URL.'/login', $mail->actionUrl);
    }
}
