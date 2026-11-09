<?php

namespace Tests\Feature\Deploy;

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Tests\TestCase;
use Throwable;

/**
 * Mailer "brevo": o Render Free bloqueia SMTP de saída, por isso no deploy o email sai pela API HTTP do
 * Brevo (porta 443). O mailer só é escolhido com MAIL_MAILER=brevo; local e testes ficam como estavam.
 *
 * Nenhum teste envia email: só se resolve o transporte, o que não faz pedidos HTTP.
 */
class BrevoMailerTest extends TestCase
{
    #[Test]
    public function brevo_mailer_is_defined(): void
    {
        $this->assertSame('brevo', config('mail.mailers.brevo.transport'));
    }

    #[Test]
    public function brevo_mailer_uses_the_brevo_api_transport(): void
    {
        config(['services.brevo.key' => 'xkeysib-chave-de-teste']);

        $transport = Mail::mailer('brevo')->getSymfonyTransport();

        $this->assertInstanceOf(BrevoApiTransport::class, $transport);
        $this->assertStringStartsWith('brevo+api://', (string) $transport);
    }

    #[Test]
    public function missing_api_key_only_fails_when_the_brevo_mailer_is_used(): void
    {
        config(['services.brevo.key' => null]);
        $this->assertSame('brevo', config('mail.mailers.brevo.transport'));

        Mail::mailer()->getSymfonyTransport();

        try {
            Mail::mailer('brevo');
        } catch (Throwable $e) {
            $this->assertStringNotContainsString('not defined', $e->getMessage());

            return;
        }

        $this->fail('O mailer brevo sem chave da API devia falhar ao ser resolvido.');
    }

    #[Test]
    public function default_mailer_in_tests_is_still_array(): void
    {
        $this->assertSame('array', config('mail.default'));
        $this->assertInstanceOf(ArrayTransport::class, Mail::mailer()->getSymfonyTransport());
    }
}
