<?php

namespace Tests\Feature\Member;

use App\Models\BusinessHour;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\AccountAnonymizedNotification;
use App\Notifications\AccountEmailChangedNotification;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Quem tem uma sessão e a password consegue mudar o email e deixar o dono sem acesso: o aviso ao
 * endereço anterior é a forma de o dono legítimo saber e reagir. O endereço novo não está verificado,
 * por isso nunca recebe nada e só aparece mascarado; o anterior também não, daí o limite diário.
 */
class EmailChangeNoticeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/member-profile';

    private const NOTICE = AccountEmailChangedNotification::class;

    private const OLD_EMAIL = 'antigo.email@example.com';

    private const NEW_EMAIL = 'novo.email@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.name' => 'ASPEC',
            'app.event_timezone' => 'Europe/Lisbon',
            'aspec.contact_email' => 'aspec@aspec.pt',
        ]);

        Notification::fake();
    }

    private function member(string $email = self::OLD_EMAIL, array $profile = []): User
    {
        $user = User::factory()->create(['email' => $email]);
        MemberProfile::factory()->create(['user_id' => $user->id, ...$profile]);

        return $user;
    }

    private function changeEmail(User $user, string $email): void
    {
        Sanctum::actingAs($user);

        $this->putJson(self::URL, ['email' => $email, 'current_password' => 'password'])->assertOk();
    }

    /**
     * Muda o email N vezes, cada uma para um endereço diferente: o aviso de cada mudança vai para
     * o endereço da mudança anterior.
     *
     * @return array<int, string> Endereços que receberam (ou deviam ter recebido) o aviso, por ordem.
     */
    private function flipEmail(User $user, int $times, string $prefix = 'flip'): array
    {
        $previous = [];

        for ($i = 1; $i <= $times; $i++) {
            $previous[] = $user->email;
            $this->changeEmail($user, "{$prefix}{$i}@example.com");
        }

        return $previous;
    }

    private function assertNoticeSentTo(string $address): void
    {
        Notification::assertSentOnDemand(
            self::NOTICE,
            fn ($notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === $address
        );
    }

    private function assertNoticeNotSentTo(string $address): void
    {
        Notification::assertNotSentTo(
            new AnonymousNotifiable,
            self::NOTICE,
            fn ($notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === $address
        );
    }

    private function assertNoNotice(): void
    {
        Notification::assertSentOnDemandTimes(self::NOTICE, 0);
    }

    private function insertSession(string $userId): string
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

    private function notifiable(): AnonymousNotifiable
    {
        return (new AnonymousNotifiable)->route('mail', self::OLD_EMAIL);
    }

    private function renderNotice(string $newEmail = 'joao.silva@gmail.com', ?CarbonInterface $changedAt = null): string
    {
        $notice = new AccountEmailChangedNotification($newEmail, $changedAt ?? Carbon::parse('2026-11-09 22:41:00', 'UTC'));

        return (string) $notice->toMail($this->notifiable())->render();
    }

    /**
     * Texto do email tal como o leitor o vê: sem etiquetas, sem entidades e com os espaços normalizados.
     */
    private function visibleText(string $html): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('/<br\s*\/?>/i', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Corpo da mensagem, sem o cabeçalho (cujo logótipo é sempre um link para o frontend) nem o rodapé.
     */
    private function bodyCell(string $html): string
    {
        $this->assertMatchesRegularExpression('/<td[^>]*class="content-cell"[^>]*>.*?<\/td>/s', $html, 'Corpo do email não encontrado.');
        preg_match('/<td[^>]*class="content-cell"[^>]*>(.*?)<\/td>/s', $html, $matches);

        return $matches[1];
    }

    #[Test]
    public function changing_the_email_notifies_the_previous_address_once(): void
    {
        $user = $this->member();

        Sanctum::actingAs($user);
        $this->putJson(self::URL, ['email' => self::NEW_EMAIL, 'current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Perfil atualizado com sucesso.')
            ->assertJsonPath('data.email', self::NEW_EMAIL);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => self::NEW_EMAIL]);
        $this->assertNoticeSentTo(self::OLD_EMAIL);
        Notification::assertSentOnDemandTimes(self::NOTICE, 1);
    }

    #[Test]
    public function notice_carries_the_new_email_and_the_moment_of_the_change(): void
    {
        $changedAt = Carbon::parse('2026-07-15 21:30:00', 'UTC');
        $this->travelTo($changedAt);
        $user = $this->member();

        $this->changeEmail($user, self::NEW_EMAIL);

        Notification::assertSentOnDemand(
            self::NOTICE,
            fn (AccountEmailChangedNotification $notification) => $notification->newEmail === self::NEW_EMAIL
                && $notification->changedAt->equalTo($changedAt)
        );
    }

    #[Test]
    public function notice_goes_out_only_by_mail_and_is_not_queued(): void
    {
        $this->changeEmail($this->member(), self::NEW_EMAIL);

        Notification::assertSentOnDemand(
            self::NOTICE,
            fn ($notification, array $channels) => $channels === ['mail'] && ! $notification instanceof ShouldQueue
        );
    }

    #[Test]
    public function new_address_and_the_user_receive_nothing(): void
    {
        $user = $this->member();

        $this->changeEmail($user, self::NEW_EMAIL);

        $this->assertNoticeSentTo(self::OLD_EMAIL);
        $this->assertNoticeNotSentTo(self::NEW_EMAIL);
        Notification::assertNotSentTo($user, self::NOTICE);
    }

    #[Test]
    public function update_without_email_sends_nothing(): void
    {
        $user = $this->member();

        Sanctum::actingAs($user);
        $this->putJson(self::URL, ['name' => 'Nome Novo'])->assertOk();

        $this->assertNoNotice();
    }

    #[Test]
    public function same_email_with_the_correct_password_sends_nothing(): void
    {
        $user = $this->member();

        $this->changeEmail($user, self::OLD_EMAIL);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => self::OLD_EMAIL]);
        $this->assertNoNotice();
    }

    #[Test]
    public function wrong_current_password_sends_nothing(): void
    {
        $user = $this->member();

        Sanctum::actingAs($user);
        $this->putJson(self::URL, ['email' => self::NEW_EMAIL, 'current_password' => 'errada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => self::OLD_EMAIL]);
        $this->assertNoNotice();
    }

    #[Test]
    public function case_only_change_sends_nothing_but_still_ends_the_other_sessions(): void
    {
        $user = $this->member('joao.silva@example.com');
        $otherSession = $this->insertSession($user->id);

        $this->changeEmail($user, 'Joao.Silva@example.com');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'Joao.Silva@example.com']);
        $this->assertDatabaseMissing('sessions', ['id' => $otherSession]);
        $this->assertNoNotice();
    }

    #[Test]
    public function stored_kelvin_sign_is_a_different_mailbox_and_sends_the_notice(): void
    {
        $lookalike = "\u{212A}ate@x.pt";
        $user = $this->member($lookalike);

        $this->changeEmail($user, 'kate@x.pt');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'kate@x.pt']);
        $this->assertNoticeSentTo($lookalike);
        Notification::assertSentOnDemandTimes(self::NOTICE, 1);
    }

    #[Test]
    public function rolled_back_change_sends_nothing(): void
    {
        $user = $this->member();
        BusinessHour::creating(fn () => throw new RuntimeException('Falha simulada.'));

        Sanctum::actingAs($user);
        $this->putJson(self::URL, [
            'email' => self::NEW_EMAIL,
            'current_password' => 'password',
            'business_hours' => [['week_day_id' => 1, 'open_time' => '09:00', 'close_time' => '18:00']],
        ])->assertInternalServerError();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => self::OLD_EMAIL]);
        $this->assertNoNotice();
    }

    #[Test]
    public function mail_failure_keeps_the_change_and_logs_without_addresses(): void
    {
        Exceptions::fake();
        Log::spy();
        $user = $this->member();

        // A mensagem do transporte costuma trazer o destinatário: é por aqui que o endereço chegaria aos logs.
        $failure = new TransportException('Expected response code 250 but got 550 for '.self::OLD_EMAIL);
        $failingChannels = $this->mock(ChannelManager::class, function (MockInterface $mock) use ($failure) {
            $mock->shouldReceive('send')->andThrow($failure);
            $mock->shouldReceive('sendNow')->andThrow($failure);
        });
        Notification::swap($failingChannels);

        Sanctum::actingAs($user);
        $this->putJson(self::URL, ['email' => self::NEW_EMAIL, 'current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.email', self::NEW_EMAIL);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => self::NEW_EMAIL]);
        Exceptions::assertNotReported(TransportException::class);
        Log::shouldHaveReceived('error')
            ->with('Falha ao enviar o aviso de alteração de email.', ['user_id' => $user->id, 'exception' => TransportException::class])
            ->once();
    }

    #[Test]
    public function five_changes_send_five_notices_to_each_previous_address(): void
    {
        Log::spy();
        $user = $this->member();

        $previous = $this->flipEmail($user, 5);

        Notification::assertSentOnDemandTimes(self::NOTICE, 5);
        foreach ($previous as $address) {
            $this->assertNoticeSentTo($address);
        }
        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function sixth_change_within_the_window_still_applies_but_sends_nothing(): void
    {
        $user = $this->member();

        $previous = $this->flipEmail($user, 6);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'flip6@example.com']);
        Notification::assertSentOnDemandTimes(self::NOTICE, 5);
        $this->assertNoticeNotSentTo($previous[5]);
    }

    #[Test]
    public function exceeding_the_limit_logs_a_warning_with_the_user_id_and_no_addresses(): void
    {
        Log::spy();
        $user = $this->member();

        $addresses = [...$this->flipEmail($user, 6), 'flip6@example.com'];

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($user, $addresses) {
                $logged = $message.json_encode($context, JSON_UNESCAPED_UNICODE);

                foreach ($addresses as $address) {
                    if (str_contains($logged, $address)) {
                        return false;
                    }
                }

                return ($context['user_id'] ?? null) === $user->id;
            })
            ->once();
    }

    #[Test]
    public function limit_still_applies_just_before_the_24_hours_end(): void
    {
        $user = $this->member();
        $this->flipEmail($user, 5);

        $this->travel(23)->hours();
        $this->changeEmail($user, self::NEW_EMAIL);

        Notification::assertSentOnDemandTimes(self::NOTICE, 5);
    }

    #[Test]
    public function notices_resume_after_24_hours(): void
    {
        $user = $this->member();
        $this->flipEmail($user, 6);

        $this->travel(86401)->seconds();
        $this->changeEmail($user, self::NEW_EMAIL);

        Notification::assertSentOnDemandTimes(self::NOTICE, 6);
        $this->assertNoticeSentTo('flip6@example.com');
    }

    #[Test]
    public function limit_of_one_account_does_not_affect_another(): void
    {
        $this->flipEmail($this->member(), 6);
        $other = $this->member('outra.conta@example.com');

        $this->changeEmail($other, 'outra.nova@example.com');

        Notification::assertSentOnDemandTimes(self::NOTICE, 6);
        $this->assertNoticeSentTo('outra.conta@example.com');
    }

    #[Test]
    public function email_has_the_portuguese_subject_and_greeting_and_no_action(): void
    {
        $mail = (new AccountEmailChangedNotification('joao.silva@gmail.com', now()))->toMail($this->notifiable());

        $this->assertSame('O email da sua conta ASPEC foi alterado', $mail->subject);
        $this->assertSame('Olá!', $mail->greeting);
        $this->assertNull($mail->actionUrl);
    }

    #[Test]
    public function email_has_the_exact_text(): void
    {
        $text = $this->visibleText($this->renderNotice());

        $this->assertStringContainsString('O email de acesso à sua conta ASPEC foi alterado em 09/11/2026 às 22:41 (hora de Portugal).', $text);
        $this->assertStringContainsString('Novo email: jo•••@g•••.com. O endereço anterior deixa de servir para iniciar sessão ou recuperar a password.', $text);
        $this->assertStringContainsString('Se foi você, não precisa de fazer nada.', $text);
        $this->assertStringContainsString('Se não foi você, a sua password pode ter sido descoberta. Contacte já a ASPEC em aspec@aspec.pt, indicando o seu nome e o nome da sua empresa. Se usa a mesma password noutros serviços, altere-a também.', $text);
        $this->assertStringContainsString('Cumprimentos, ASPEC', $text);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function changeMoments(): array
    {
        return [
            'verão (UTC+1)' => ['2026-07-15 21:30:00', 'em 15/07/2026 às 22:30 (hora de Portugal)'],
            'inverno (UTC)' => ['2026-01-15 21:30:00', 'em 15/01/2026 às 21:30 (hora de Portugal)'],
            'verão, a passar para o dia seguinte' => ['2026-07-15 23:30:00', 'em 16/07/2026 às 00:30 (hora de Portugal)'],
        ];
    }

    #[Test]
    #[DataProvider('changeMoments')]
    public function moment_is_shown_in_portuguese_time(string $utc, string $expected): void
    {
        $text = $this->visibleText($this->renderNotice(changedAt: Carbon::parse($utc, 'UTC')));

        $this->assertStringContainsString($expected, $text);
    }

    #[Test]
    public function moment_uses_the_configured_event_timezone(): void
    {
        config(['app.event_timezone' => 'Atlantic/Azores']);

        $text = $this->visibleText($this->renderNotice(changedAt: Carbon::parse('2026-07-15 21:30:00', 'UTC')));

        $this->assertStringContainsString('em 15/07/2026 às 21:30', $text);
    }

    #[Test]
    public function contact_address_comes_from_the_config(): void
    {
        config(['aspec.contact_email' => 'secretaria@aspec.test']);

        $text = $this->visibleText($this->renderNotice());

        $this->assertStringContainsString('Contacte já a ASPEC em secretaria@aspec.test,', $text);
        $this->assertStringNotContainsString('aspec@aspec.pt', $text);
    }

    #[Test]
    public function full_new_email_never_appears(): void
    {
        $html = $this->renderNotice('joao.silva@gmail.com');

        $this->assertStringNotContainsString('joao.silva', $html);
        $this->assertStringNotContainsString('gmail', $html);
    }

    #[Test]
    public function mask_is_not_turned_into_emphasis_or_a_link(): void
    {
        $html = $this->renderNotice();

        $this->assertStringContainsString('jo•••@g•••.com', $html);
        $this->assertDoesNotMatchRegularExpression('#<(strong|em)\b[^>]*>[^<]*•••#u', $html);
        $this->assertDoesNotMatchRegularExpression('#<a\b[^>]*>[^<]*•••#u', $html);
    }

    #[Test]
    public function message_body_has_no_links_or_buttons(): void
    {
        $this->assertStringNotContainsString('<a ', $this->bodyCell($this->renderNotice()));
    }

    #[Test]
    public function email_does_not_mention_the_name_or_the_business_of_the_member(): void
    {
        $user = $this->member(profile: ['name' => 'Ana Sofia Marques', 'business_name' => 'Marques Consultoria Lda']);

        $this->changeEmail($user, self::NEW_EMAIL);

        Notification::assertSentOnDemand(
            self::NOTICE,
            function ($notification, array $channels, AnonymousNotifiable $notifiable) {
                $rendered = (string) $notification->toMail($notifiable)->render();

                return ! str_contains($rendered, 'Ana Sofia Marques')
                    && ! str_contains($rendered, 'Marques Consultoria Lda');
            }
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function maskedEmails(): array
    {
        return [
            'endereço normal' => ['joao.silva@gmail.com', 'jo•••@g•••.com'],
            'parte local com 1 carácter e subdomínio' => ['a@mail.empresa.pt', 'a•••@m•••.pt'],
            'parte local com @ entre aspas' => ['"jo@ao"@gmail.com', '"j•••@g•••.com'],
            'caracteres multibyte' => ['ção@gmail.com', 'çã•••@g•••.com'],
            'endereço longo não revela o tamanho' => ['maria.de.lurdes.ferreira@outlook-empresarial.com', 'ma•••@o•••.com'],
        ];
    }

    #[Test]
    #[DataProvider('maskedEmails')]
    public function new_email_is_masked_with_a_fixed_shape(string $email, string $expected): void
    {
        $text = $this->visibleText($this->renderNotice($email));

        $this->assertStringContainsString("Novo email: {$expected}. O endereço anterior", $text);
        $this->assertStringNotContainsString($email, $text);
    }

    /**
     * Muda o email pelo endpoint, para provar que o endereço passa a validação real do pedido,
     * e devolve o HTML do aviso que saiu, pelo mesmo caminho que o email percorre em produção.
     * Só serve para endereços que o pedido aceita; os recusados testam-se com renderNotice().
     */
    private function renderNoticeSentFor(string $newEmail): string
    {
        $this->changeEmail($this->member(), $newEmail);

        return (string) Notification::sent(new AnonymousNotifiable, self::NOTICE)->sole()->toMail($this->notifiable())->render();
    }

    #[Test]
    public function markdown_link_in_the_new_email_does_not_become_a_link(): void
    {
        $html = $this->renderNotice('a@b.[Clique aqui para recuperar a sua conta ASPEC](https://134744072/)');

        $this->assertStringNotContainsString('<a ', $this->bodyCell($html));
        $this->assertStringNotContainsString('134744072', $html);
        $this->assertStringNotContainsString('Clique aqui', $html);
        $this->assertStringContainsString('Novo email: a•••@b•••. O endereço anterior', $this->visibleText($html));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function topLevelDomains(): array
    {
        return [
            'hostil: etiqueta longa' => ['a@b.pt-'.str_repeat('x', 57), 'a•••@b•••'],
            'hostil: Markdown entre aspas' => ['"[Clique](https://x/)"@b.com', '"[•••@b•••.com'],
            'normal: .com' => ['a@b.com', 'a•••@b•••.com'],
            'normal: .pt' => ['a@b.pt', 'a•••@b•••.pt'],
            'normal: .museum' => ['a@b.museum', 'a•••@b•••.museum'],
        ];
    }

    #[Test]
    #[DataProvider('topLevelDomains')]
    public function top_level_domain_is_shown_only_when_it_looks_like_one(string $email, string $expected): void
    {
        $html = $this->renderNoticeSentFor($email);

        $this->assertStringContainsString("Novo email: {$expected}. O endereço anterior", $this->visibleText($html));
        $this->assertStringNotContainsString('<a ', $this->bodyCell($html));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function addressesTheRequestRefuses(): array
    {
        return [
            'link Markdown' => ['a@b.[Clique aqui](https://134744072/)', 'a•••@b•••'],
            'ponto de largura total' => ['a@evil．com', 'a•••@e•••'],
        ];
    }

    #[Test]
    #[DataProvider('addressesTheRequestRefuses')]
    public function notification_masks_addresses_the_request_refuses(string $email, string $expected): void
    {
        $html = $this->renderNotice($email);

        $this->assertStringContainsString("Novo email: {$expected}. O endereço anterior", $this->visibleText($html));
        $this->assertStringNotContainsString('<a ', $this->bodyCell($html));
    }

    #[Test]
    public function anonymizing_a_member_does_not_send_the_notice(): void
    {
        $member = $this->member();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/admin/users/{$member->id}/anonymize")->assertOk();

        $this->assertSoftDeleted('users', ['id' => $member->id]);
        $this->assertDatabaseMissing('users', ['id' => $member->id, 'email' => self::OLD_EMAIL]);
        Notification::assertSentOnDemand(AccountAnonymizedNotification::class);
        $this->assertNoNotice();
    }
}
