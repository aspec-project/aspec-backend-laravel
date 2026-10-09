<?php

namespace Tests\Feature\Payments;

use App\Enums\InactiveReason;
use App\Jobs\ResendActivationLinkJob;
use App\Models\User;
use App\Notifications\ActivationLinkNotification;
use App\Notifications\ReactivationLinkNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Concerns\BuildsActivationLinks;
use Tests\TestCase;

/**
 * POST /api/account-activations/resend: pedido público de um novo link de ativação/reativação.
 *
 * A resposta é sempre a mesma, exista ou não a conta, para o endpoint não revelar quem é membro
 * da ASPEC. O trabalho (procurar a conta e enviar o email) corre num job depois da resposta,
 * que no kernel de teste corre no terminate, por isso o Notification::fake() vê o envio.
 */
class ResendActivationLinkTest extends TestCase
{
    use BuildsActivationLinks, RefreshDatabase;

    private const URL = '/api/account-activations/resend';

    private const MESSAGE = 'Se a conta existir e aguardar ativação, enviámos um novo link.';

    private const TOO_MANY = 'Demasiados pedidos. Tente novamente mais tarde.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTestFrontendUrl();
        Notification::fake();
    }

    private function resend(string $email, string $ip = '127.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(self::URL, ['email' => $email]);
    }

    private function expectedResponse(): array
    {
        return ['success' => true, 'message' => self::MESSAGE, 'data' => null];
    }

    #[Test]
    public function approved_account_receives_a_new_activation_link(): void
    {
        $user = User::factory()->approved()->create();

        $this->resend($user->email)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', self::MESSAGE)
            ->assertJsonPath('data', null);

        Notification::assertSentToTimes($user, ActivationLinkNotification::class, 1);
        Notification::assertNotSentTo($user, ReactivationLinkNotification::class);
    }

    #[Test]
    public function unpaid_inactive_account_receives_a_new_reactivation_link(): void
    {
        $user = User::factory()->inactive(InactiveReason::Unpaid)->withBilling()->create();

        $this->resend($user->email)->assertOk()->assertJsonPath('message', self::MESSAGE);

        Notification::assertSentToTimes($user, ReactivationLinkNotification::class, 1);
        Notification::assertNotSentTo($user, ActivationLinkNotification::class);
    }

    #[Test]
    public function unknown_email_gets_the_same_answer_and_nothing_is_sent(): void
    {
        $this->resend('ninguem@exemplo.pt')
            ->assertOk()
            ->assertJsonPath('message', self::MESSAGE);

        Notification::assertNothingSent();
    }

    public static function accountsThatCannotPay(): array
    {
        return [
            'pending' => ['pending'],
            'active' => ['active'],
            'inactive blocked' => ['blocked'],
            'inactive rejected' => ['rejected'],
            'inactive deleted' => ['deleted'],
            'inactive without reason' => ['inactive'],
            'admin' => ['admin'],
        ];
    }

    private function accountInState(string $state): User
    {
        $factory = User::factory();

        return match ($state) {
            'pending' => $factory->pending()->create(),
            'active' => $factory->create(),
            'blocked' => $factory->inactive(InactiveReason::Blocked)->create(),
            'rejected' => $factory->inactive(InactiveReason::Rejected)->create(),
            'deleted' => $factory->inactive(InactiveReason::Deleted)->create(),
            'inactive' => $factory->inactive()->create(),
            'admin' => $factory->admin()->create(),
        };
    }

    #[Test]
    #[DataProvider('accountsThatCannotPay')]
    public function accounts_that_cannot_pay_get_the_same_answer_and_no_link(string $state): void
    {
        $user = $this->accountInState($state);

        $this->resend($user->email)
            ->assertOk()
            ->assertExactJson($this->expectedResponse());

        Notification::assertNothingSent();
    }

    #[Test]
    public function soft_deleted_approved_account_gets_no_link(): void
    {
        $user = User::factory()->approved()->create();
        $user->delete();

        $this->resend($user->email)->assertOk();

        Notification::assertNothingSent();
    }

    #[Test]
    public function answer_for_an_eligible_account_is_identical_to_an_unknown_email(): void
    {
        $user = User::factory()->approved()->create();

        $eligible = $this->resend($user->email);
        $unknown = $this->resend('ninguem@exemplo.pt');

        $this->assertSame($eligible->getStatusCode(), $unknown->getStatusCode());
        $eligible->assertOk()->assertExactJson($this->expectedResponse());
        $unknown->assertOk()->assertExactJson($this->expectedResponse());
    }

    /**
     * Prova que o pedido faz o mesmo trabalho para qualquer email: a pesquisa na BD fica no job,
     * por isso o tempo de resposta não denuncia se a conta existe.
     */
    #[Test]
    public function job_is_dispatched_after_the_response_for_any_valid_email(): void
    {
        Bus::fake();
        $user = User::factory()->approved()->create();

        $this->resend($user->email)->assertOk();
        $this->resend('ninguem@exemplo.pt')->assertOk();

        Bus::assertDispatchedAfterResponse(ResendActivationLinkJob::class, fn ($job) => $job->email === $user->email);
        Bus::assertDispatchedAfterResponse(ResendActivationLinkJob::class, fn ($job) => $job->email === 'ninguem@exemplo.pt');
    }

    /**
     * O email do pedido é um dado pessoal: o job não vai para a fila, por isso nunca fica
     * guardado nas tabelas jobs/failed_jobs.
     */
    #[Test]
    public function resend_job_is_not_queued_so_the_email_never_reaches_the_jobs_table(): void
    {
        $this->assertFalse(
            (new ReflectionClass(ResendActivationLinkJob::class))->implementsInterface(ShouldQueue::class),
        );

        Queue::fake();
        $user = User::factory()->approved()->create();

        $this->resend($user->email)->assertOk();

        Queue::assertNotPushed(ResendActivationLinkJob::class);
    }

    public static function invalidEmails(): array
    {
        return [
            'missing' => [null],
            'not an email' => ['nao-e-email'],
        ];
    }

    #[Test]
    #[DataProvider('invalidEmails')]
    public function invalid_email_is_rejected_and_nothing_is_dispatched(?string $email): void
    {
        Bus::fake();

        $this->postJson(self::URL, $email === null ? [] : ['email' => $email])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Os dados enviados são inválidos.')
            ->assertJsonValidationErrors(['email']);

        Bus::assertNotDispatchedAfterResponse(ResendActivationLinkJob::class);
        Bus::assertNotDispatched(ResendActivationLinkJob::class);
    }

    #[Test]
    public function fourth_request_for_the_same_email_and_ip_within_15_minutes_is_throttled(): void
    {
        $user = User::factory()->approved()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->resend($user->email)->assertOk();
        }

        $this->resend($user->email)
            ->assertTooManyRequests()
            ->assertExactJson(['success' => false, 'message' => self::TOO_MANY]);

        $this->travel(16)->minutes();

        $this->resend($user->email)->assertOk();
    }

    #[Test]
    public function email_case_does_not_bypass_the_per_email_limit(): void
    {
        $this->resend('Ana@X.pt')->assertOk();
        $this->resend('ana@x.pt')->assertOk();
        $this->resend('ANA@X.PT')->assertOk();

        $this->resend('ana@x.pt')->assertTooManyRequests();
    }

    #[Test]
    public function eleventh_request_from_the_same_ip_within_an_hour_is_throttled_even_with_different_emails(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->resend("pessoa{$i}@exemplo.pt")->assertOk();
        }

        $this->resend('pessoa11@exemplo.pt')
            ->assertTooManyRequests()
            ->assertJsonPath('message', self::TOO_MANY);

        $this->resend('pessoa11@exemplo.pt', '10.0.0.2')->assertOk();
    }

    /**
     * Sem um teto só por email, um atacante com IPs rotativos enchia a caixa de correio de
     * qualquer pessoa: no máximo 5 reenvios por email por dia, venham de onde vierem.
     */
    #[Test]
    public function sixth_request_for_the_same_email_in_a_day_is_throttled_even_from_different_ips(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->resend('ana@x.pt', "10.0.0.{$i}")->assertOk();
        }

        $this->resend('ana@x.pt', '10.0.0.6')
            ->assertTooManyRequests()
            ->assertExactJson(['success' => false, 'message' => self::TOO_MANY]);

        $this->travel(1)->days();
        $this->travel(1)->seconds();

        $this->resend('ana@x.pt', '10.0.0.6')->assertOk();
    }

    public static function emailsForTheDailyLimit(): array
    {
        return [
            'approved account' => ['approved'],
            'unknown email' => ['unknown'],
        ];
    }

    /**
     * O limite diário não consulta a BD e responde com o mesmo corpo dos limites por IP: o 429
     * não revela se o email pertence a alguém.
     */
    #[Test]
    #[DataProvider('emailsForTheDailyLimit')]
    public function daily_email_limit_answers_the_same_for_existing_and_unknown_emails(string $kind): void
    {
        $email = $kind === 'approved'
            ? User::factory()->approved()->create()->email
            : 'ninguem@exemplo.pt';

        for ($i = 1; $i <= 5; $i++) {
            $this->resend($email, "10.0.0.{$i}")->assertOk();
        }

        $daily = $this->resend($email, '10.0.0.6');

        for ($i = 0; $i < 3; $i++) {
            $this->resend('outra@exemplo.pt', '10.0.0.7')->assertOk();
        }
        $perIp = $this->resend('outra@exemplo.pt', '10.0.0.7');

        $daily->assertStatus(429)->assertExactJson(['success' => false, 'message' => self::TOO_MANY]);
        $perIp->assertStatus(429);
        $this->assertSame($perIp->json(), $daily->json());
    }

    #[Test]
    public function email_case_and_spaces_do_not_bypass_the_daily_email_limit(): void
    {
        $variants = [' Ana@X.pt ', 'ANA@x.pt', 'ana@x.pt', ' Ana@X.pt ', 'ANA@x.pt'];

        foreach ($variants as $i => $email) {
            $this->resend($email, '10.0.0.'.($i + 1))->assertOk();
        }

        $this->resend('ana@x.pt', '10.0.0.6')->assertTooManyRequests();
    }

    /**
     * Um pedido recusado pelos limites de IP nunca chega ao limite diário: senão um atacante que
     * esgotou o próprio IP conseguia gastar o teto diário de uma vítima. O 6.º pedido de outro IP
     * prova que contaram exatamente 5, não 6.
     */
    #[Test]
    public function request_refused_by_the_ip_limit_does_not_count_towards_the_daily_email_limit(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->resend("pessoa{$i}@exemplo.pt", '10.0.0.1')->assertOk();
        }

        $this->resend('x@x.pt', '10.0.0.1')->assertTooManyRequests();

        for ($i = 1; $i <= 5; $i++) {
            $this->resend('x@x.pt', "10.0.1.{$i}")->assertOk();
        }

        $this->resend('x@x.pt', '10.0.1.6')
            ->assertTooManyRequests()
            ->assertJsonPath('message', self::TOO_MANY);
    }

    /**
     * Os cabeçalhos X-RateLimit-* mostram só contadores do próprio IP, nunca quantos reenvios
     * outras pessoas pediram para aquele email. O 429 do limite diário também não traz
     * Retry-After/X-RateLimit-Reset, que diriam quando foi o 1.º pedido do dia para o email.
     */
    #[Test]
    public function rate_limit_headers_never_reflect_requests_from_other_ips_for_the_same_email(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->resend('x@x.pt', "10.0.0.{$i}")->assertOk();
        }

        $target = $this->resend('x@x.pt', '10.0.0.5')->assertOk();
        $reference = $this->resend('nunca-usado@x.pt', '10.0.0.6')->assertOk();

        $target->assertHeader('X-RateLimit-Limit', '3')->assertHeader('X-RateLimit-Remaining', '2');
        $reference->assertHeader('X-RateLimit-Limit', '3')->assertHeader('X-RateLimit-Remaining', '2');
        $this->assertNotSame('5', $target->headers->get('X-RateLimit-Limit'));

        $this->resend('x@x.pt', '10.0.0.7')
            ->assertTooManyRequests()
            ->assertJsonPath('message', self::TOO_MANY)
            ->assertHeaderMissing('Retry-After')
            ->assertHeaderMissing('X-RateLimit-Reset')
            ->assertHeader('X-RateLimit-Limit', '3')
            ->assertHeader('X-RateLimit-Remaining', '2');
    }

    /**
     * O limite diário é verificado depois da validação: pedidos com email inválido não gastam
     * o contador de ninguém.
     */
    #[Test]
    public function invalid_requests_do_not_count_towards_the_daily_email_limit(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->resend('nao-e-email', "10.0.0.{$i}")->assertUnprocessable();
        }

        for ($i = 1; $i <= 5; $i++) {
            $this->resend('x@x.pt', "10.0.1.{$i}")->assertOk();
        }

        $this->resend('x@x.pt', '10.0.1.6')->assertTooManyRequests();
    }

    /**
     * A rota de reenvio não está atrás do middleware signed: sem expires/signature nunca dá
     * 403 "link inválido" (não é confundida com /account-activations/{user}).
     */
    #[Test]
    public function resend_route_does_not_require_a_signed_link(): void
    {
        $response = $this->postJson(self::URL, ['email' => 'ninguem@exemplo.pt']);

        $this->assertNotSame(403, $response->getStatusCode());
        $response->assertOk()->assertJsonPath('message', self::MESSAGE);
    }

    #[Test]
    public function link_from_the_resent_email_is_accepted_by_the_activation_endpoint(): void
    {
        $user = User::factory()->approved()->create();

        $this->resend($user->email)->assertOk();

        $url = null;
        Notification::assertSentTo($user, ActivationLinkNotification::class, function ($notification) use ($user, &$url) {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });

        $this->getJson($this->toApiPath($url))
            ->assertOk()
            ->assertJsonPath('data.type', 'activation');
    }
}
