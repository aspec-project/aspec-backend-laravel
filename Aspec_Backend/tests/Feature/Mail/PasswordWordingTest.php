<?php

namespace Tests\Feature\Mail;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\SubscriptionCancelledNotification;
use App\Notifications\SubscriptionResumedNotification;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Todo o texto pt-PT que chega ao utilizador diz "palavra-passe", nunca "password": as mensagens
 * de validação e os emails que falam da palavra-passe. As chaves e os nomes dos campos continuam
 * em inglês (não são mostrados).
 */
class PasswordWordingTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD_WORD = '/\bpassword\b/i';

    private function mailText(MailMessage $mail): string
    {
        return implode("\n", array_merge(
            [$mail->subject, $mail->greeting, $mail->actionText],
            $mail->introLines,
            $mail->outroLines,
        ));
    }

    /**
     * @return array<string, array{Closure(): Notification}>
     */
    public static function passwordRelatedNotifications(): array
    {
        return [
            'palavra-passe alterada' => [fn () => new PasswordChangedNotification],
            'recuperação da palavra-passe' => [fn () => new ResetPasswordNotification('token-de-teste')],
            'cancelamento agendado' => [fn () => new SubscriptionCancelledNotification(now()->addDays(20))],
            'subscrição retomada' => [fn () => new SubscriptionResumedNotification(now()->addDays(20))],
        ];
    }

    #[Test]
    #[DataProvider('passwordRelatedNotifications')]
    public function email_never_says_password(Closure $makeNotification): void
    {
        $text = $this->mailText($makeNotification()->toMail(User::factory()->create()));

        $this->assertDoesNotMatchRegularExpression(self::PASSWORD_WORD, $text);
        $this->assertStringContainsString('palavra-passe', $text);
    }

    #[Test]
    public function password_changed_email_has_the_exact_texts(): void
    {
        $mail = (new PasswordChangedNotification)->toMail(User::factory()->create());

        $this->assertSame('Palavra-passe alterada', $mail->subject);
        $this->assertContains('A sua palavra-passe foi alterada. Se não foi você, contacte a ASPEC.', $mail->introLines);
    }

    #[Test]
    public function reset_password_email_has_the_exact_texts(): void
    {
        $mail = (new ResetPasswordNotification('token-de-teste'))->toMail(User::factory()->create());

        $this->assertSame('Recuperação da palavra-passe', $mail->subject);
        $this->assertContains('Recebemos um pedido para redefinir a palavra-passe da sua conta ASPEC.', $mail->introLines);
        $this->assertSame('Redefinir palavra-passe', $mail->actionText);
    }

    #[Test]
    public function subscription_cancelled_email_tells_to_change_the_palavra_passe(): void
    {
        $mail = (new SubscriptionCancelledNotification(now()->addDays(20)))->toMail(User::factory()->create());

        $this->assertContains('Se não foi você, altere a palavra-passe e retome a subscrição.', $mail->outroLines);
    }

    #[Test]
    public function subscription_resumed_email_tells_to_change_the_palavra_passe(): void
    {
        $mail = (new SubscriptionResumedNotification(now()->addDays(20)))->toMail(User::factory()->create());

        $this->assertContains('Se não foi você, altere a palavra-passe e cancele de novo a subscrição.', $mail->outroLines);
    }

    /**
     * Guarda contra regressões: uma mensagem ou um atributo novo com "password" falha aqui.
     */
    #[Test]
    public function portuguese_validation_messages_never_say_password(): void
    {
        $messages = trans('validation', [], 'pt_PT');
        $this->assertIsArray($messages);

        $offending = [];

        array_walk_recursive($messages, function (mixed $value, string|int $key) use (&$offending) {
            if (is_string($value) && preg_match(self::PASSWORD_WORD, $value)) {
                $offending[] = "{$key}: {$value}";
            }
        });

        $this->assertSame([], $offending);
    }

    #[Test]
    public function password_attributes_are_translated(): void
    {
        $this->assertSame('palavra-passe', trans('validation.attributes.password', [], 'pt_PT'));
        $this->assertSame('confirmação da palavra-passe', trans('validation.attributes.password_confirmation', [], 'pt_PT'));
        $this->assertSame('palavra-passe atual', trans('validation.attributes.current_password', [], 'pt_PT'));
        $this->assertSame('link de recuperação', trans('validation.attributes.token', [], 'pt_PT'));
    }
}
