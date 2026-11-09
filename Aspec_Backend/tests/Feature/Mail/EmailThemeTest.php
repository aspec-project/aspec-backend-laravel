<?php

namespace Tests\Feature\Mail;

use App\Mail\DemoInvoiceMail;
use App\Models\EventInstance;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\ActivationLinkNotification;
use App\Notifications\EventCancelledNotification;
use App\Notifications\NewEventAnnouncementNotification;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\PrayerCardReceivedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\SubscriptionActivatedNotification;
use App\Notifications\UserBlockedNotification;
use App\Notifications\UserRejectedNotification;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Attachment;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tema dos emails com a imagem da ASPEC: todas as notificações e o email da fatura demo saem com
 * as cores da ASPEC e, quando MAIL_LOGO_URL está definido, com o logótipo (URL público absoluto,
 * com width/height para o Outlook e alt para quando as imagens estão bloqueadas). Sem logótipo,
 * o cabeçalho mostra o nome da app em texto. O cabeçalho aponta para o frontend, não para a API.
 */
class EmailThemeTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND = 'https://app.aspec.test';

    private const API = 'https://api.aspec.test';

    private const LOGO = 'https://pub-teste.r2.dev/branding/logo-email.png';

    /**
     * Cores que existem em qualquer email: cabeçalho e títulos, texto e fundo.
     */
    private const THEME_COLOURS = ['#0D1F35', '#334155', '#F8FAFC'];

    /**
     * Dourado dos links e do botão: só aparece nos emails que têm um botão.
     */
    private const ACCENT_COLOUR = '#8A7043';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.name' => 'ASPEC',
            'app.url' => self::API,
            'app.frontend_url' => self::FRONTEND,
            'mail.logo_url' => null,
            'subscription.price_amount' => 30.00,
            'subscription.interval' => 'year',
            'subscription.trial_days' => 30,
            'subscription.activation_link_days' => 7,
            'subscription.reactivation_link_days' => 7,
        ]);
    }

    private function member(?User $user = null): User
    {
        $user ??= User::factory()->withBilling()->create();
        MemberProfile::factory()->create(['user_id' => $user->id, 'name' => 'Ana Ferreira']);

        return $user->fresh();
    }

    private function renderNotification(Notification $notification, ?User $user = null): string
    {
        return (string) $notification->toMail($user ?? $this->member())->render();
    }

    /**
     * Conteúdo da célula do cabeçalho (td.header), já com o CSS inline.
     */
    private function headerCell(string $html): string
    {
        $this->assertMatchesRegularExpression('/<td[^>]*class="header"[^>]*>(.*?)<\/td>/s', $html, 'Cabeçalho do email não encontrado.');
        preg_match('/<td[^>]*class="header"[^>]*>(.*?)<\/td>/s', $html, $matches);

        return $matches[1];
    }

    private function assertThemed(string $html, string $context = '', bool $hasButton = true): void
    {
        $colours = $hasButton ? [...self::THEME_COLOURS, self::ACCENT_COLOUR] : self::THEME_COLOURS;

        foreach ($colours as $colour) {
            $this->assertStringContainsStringIgnoringCase($colour, $html, "Cor {$colour} em falta. {$context}");
        }
        $this->assertStringNotContainsString('notification-logo', $html, $context);
    }

    #[Test]
    public function header_shows_the_logo_image_when_the_logo_url_is_configured(): void
    {
        config(['mail.logo_url' => self::LOGO]);

        $header = $this->headerCell($this->renderNotification(new UserBlockedNotification));

        $this->assertMatchesRegularExpression('/<img[^>]*src="'.preg_quote(self::LOGO, '/').'"/', $header);
        $this->assertStringContainsString('width="240"', $header);
        $this->assertStringContainsString('height="80"', $header);
        $this->assertStringContainsString('alt="ASPEC"', $header);
    }

    #[Test]
    public function header_shows_the_app_name_as_text_when_the_logo_url_is_empty(): void
    {
        config(['mail.logo_url' => '']);

        $html = $this->renderNotification(new UserBlockedNotification);
        $header = $this->headerCell($html);

        $this->assertStringContainsString('ASPEC', strip_tags($header));
        $this->assertStringNotContainsString('<img', $header);
        $this->assertStringNotContainsString('logo-email.png', $html);
    }

    #[Test]
    public function header_shows_the_app_name_as_text_when_the_logo_url_is_not_set(): void
    {
        $header = $this->headerCell($this->renderNotification(new UserBlockedNotification));

        $this->assertStringContainsString('ASPEC', strip_tags($header));
        $this->assertStringNotContainsString('<img', $header);
    }

    #[Test]
    public function rendered_email_uses_the_aspec_colours_and_not_the_laravel_logo(): void
    {
        $this->assertThemed($this->renderNotification(new PaymentFailedNotification(now()->addDays(7))));
    }

    #[Test]
    public function email_without_a_button_still_uses_the_aspec_colours(): void
    {
        $this->assertThemed($this->renderNotification(new UserBlockedNotification), hasButton: false);
    }

    #[Test]
    public function header_background_has_the_bgcolor_attribute_for_outlook(): void
    {
        $html = $this->renderNotification(new UserBlockedNotification);

        $this->assertMatchesRegularExpression('/<td[^>]*class="header"[^>]*bgcolor="#0D1F35"|<td[^>]*bgcolor="#0D1F35"[^>]*class="header"/i', $html);
    }

    #[Test]
    public function header_link_points_to_the_frontend_and_not_to_the_api(): void
    {
        $html = $this->renderNotification(new UserBlockedNotification);
        $header = $this->headerCell($html);

        $this->assertMatchesRegularExpression('/<a[^>]*href="'.preg_quote(self::FRONTEND, '/').'\/?"/', $header);
        $this->assertStringNotContainsString(self::API, $html);
    }

    #[Test]
    public function footer_keeps_the_portuguese_copyright_line(): void
    {
        $html = $this->renderNotification(new UserBlockedNotification);

        $this->assertStringContainsString('Todos os direitos reservados.', $html);
        $this->assertStringContainsString('ASPEC', $html);
    }

    #[Test]
    public function new_event_announcement_renders_with_the_theme_and_the_logo(): void
    {
        config(['mail.logo_url' => self::LOGO]);
        $event = EventInstance::factory()->create(['name' => 'Jantar de Networking Porto']);

        $html = $this->renderNotification(new NewEventAnnouncementNotification($event));

        $this->assertThemed($html);
        $this->assertStringContainsString(self::LOGO, $this->headerCell($html));
        $this->assertStringContainsString('Jantar de Networking Porto', $html);
    }

    #[Test]
    public function payment_failed_email_renders_with_the_theme_and_the_logo(): void
    {
        config(['mail.logo_url' => self::LOGO]);

        $html = $this->renderNotification(new PaymentFailedNotification(now()->addDays(7)));

        $this->assertThemed($html);
        $this->assertStringContainsString(self::LOGO, $this->headerCell($html));
        $this->assertStringContainsString('Atualizar cartão', $html);
    }

    /**
     * Uma amostra das notificações (contas, pagamentos, eventos, cartões de oração): nenhuma tem
     * vista própria, por isso todas têm de sair com o mesmo tema.
     *
     * @return array<string, array{Closure}>
     */
    public static function notifications(): array
    {
        return [
            'ativação' => [fn () => new ActivationLinkNotification],
            'boas-vindas' => [fn () => new SubscriptionActivatedNotification(now()->addDays(30))],
            'password alterada' => [fn () => new PasswordChangedNotification],
            'recuperar password' => [fn () => new ResetPasswordNotification('token-de-teste')],
            'candidatura rejeitada' => [fn () => new UserRejectedNotification('Dados incompletos.')],
            'conta bloqueada' => [fn () => new UserBlockedNotification('Motivo de teste.')],
            'evento cancelado' => [fn () => new EventCancelledNotification('Jantar', '20/11/2026', '19:00', 'Motivo.')],
            'cartão de oração' => [fn () => new PrayerCardReceivedNotification(EventInstance::factory()->create())],
        ];
    }

    #[Test]
    #[DataProvider('notifications')]
    public function every_notification_renders_with_the_theme(Closure $make): void
    {
        config(['mail.logo_url' => self::LOGO]);
        $notification = $make();
        $user = $notification instanceof ActivationLinkNotification
            ? $this->member(User::factory()->approved()->create())
            : $this->member();

        $html = (string) ($mail = $notification->toMail($user))->render();

        $this->assertThemed($html, $notification::class, hasButton: $mail->actionUrl !== null);
        $this->assertStringContainsString(self::LOGO, $this->headerCell($html), $notification::class);
        $this->assertMatchesRegularExpression('/<a[^>]*href="'.preg_quote(self::FRONTEND, '/').'\/?"/', $this->headerCell($html), $notification::class);
    }

    #[Test]
    public function demo_invoice_email_renders_with_the_theme_and_the_invoice_number(): void
    {
        config(['mail.logo_url' => self::LOGO]);

        $html = (string) (new DemoInvoiceMail('DEMO-2026-ABCDEF12', 'invoices/x/DEMO-2026-ABCDEF12.pdf'))->render();

        $this->assertThemed($html, hasButton: false);
        $this->assertStringContainsString(self::LOGO, $this->headerCell($html));
        $this->assertStringContainsString('DEMO-2026-ABCDEF12', $html);
        $this->assertStringContainsString('Documento de demonstração, sem validade fiscal.', $html);
    }

    #[Test]
    public function demo_invoice_email_still_attaches_the_pdf_from_the_invoices_disk(): void
    {
        Storage::fake('local');
        config(['filesystems.invoices_disk' => 'local']);
        Storage::disk('local')->put('invoices/x/DEMO-2026-ABCDEF12.pdf', '%PDF-1.4 teste');

        $mail = new DemoInvoiceMail('DEMO-2026-ABCDEF12', 'invoices/x/DEMO-2026-ABCDEF12.pdf');

        $expected = Attachment::fromStorageDisk('local', 'invoices/x/DEMO-2026-ABCDEF12.pdf')
            ->as('fatura-DEMO-2026-ABCDEF12.pdf')
            ->withMime('application/pdf');

        $this->assertCount(1, $mail->attachments());
        $this->assertTrue($mail->hasAttachment($expected));
    }

    #[Test]
    public function email_logo_file_is_a_600_by_200_png(): void
    {
        $path = resource_path('images/aspec-logo-email.png');

        $this->assertFileExists($path);
        $size = getimagesize($path);
        $this->assertNotFalse($size);
        $this->assertSame([600, 200, IMAGETYPE_PNG], [$size[0], $size[1], $size[2]]);
    }

    #[Test]
    public function email_logo_file_has_an_opaque_navy_background_and_no_gamma_chunk(): void
    {
        $path = resource_path('images/aspec-logo-email.png');
        $this->assertFileExists($path);

        $this->assertStringNotContainsString('gAMA', (string) file_get_contents($path));

        $image = imagecreatefrompng($path);
        $colour = imagecolorsforindex($image, imagecolorat($image, 0, 0));
        $this->assertSame(
            ['red' => 0x0D, 'green' => 0x1F, 'blue' => 0x35, 'alpha' => 0],
            $colour,
        );
    }
}
