<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Avisa o endereço anterior de que o email da conta mudou, para o dono legítimo poder reagir.
 * Sem fila: com rota anónima o endereço iria no payload e ficaria nas tabelas jobs e failed_jobs,
 * que a anonimização não limpa. Sem links nem nome, porque a caixa antiga pode já ser de terceiro.
 */
class AccountEmailChangedNotification extends Notification
{
    /**
     * @param  string  $newEmail  Endereço novo; só sai mascarado no email.
     * @param  CarbonInterface  $changedAt  Momento da alteração.
     */
    public function __construct(
        public string $newEmail,
        public CarbonInterface $changedAt,
    ) {}

    /**
     * Enviada só por email.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Indica quando o email mudou, o endereço novo mascarado e o contacto da ASPEC, sem ações na conta.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $changedAt = $this->changedAt->copy()->setTimezone(config('app.event_timezone'));
        $contactEmail = config('aspec.contact_email');

        return (new MailMessage)
            ->subject('O email da sua conta ASPEC foi alterado')
            ->greeting('Olá!')
            ->line("O email de acesso à sua conta ASPEC foi alterado em {$changedAt->format('d/m/Y')} às {$changedAt->format('H:i')} (hora de Portugal).")
            ->line("Novo email: {$this->maskedNewEmail()}. O endereço anterior deixa de servir para iniciar sessão ou recuperar a palavra-passe.")
            ->line('Se foi você, não precisa de fazer nada.')
            ->line("Se não foi você, a sua palavra-passe pode ter sido descoberta. Contacte já a ASPEC em {$contactEmail}, indicando o seu nome e o nome da sua empresa. Se usa a mesma palavra-passe noutros serviços, altere-a também.")
            ->salutation("Cumprimentos,\nASPEC");
    }

    /**
     * Máscara de comprimento fixo (jo•••@g•••.com): o endereço novo não está verificado e o destinatário
     * pode ser terceiro. Usa • porque o corpo passa por Markdown e * sairia como negrito ou itálico.
     * O TLD só aparece se for mesmo um TLD: a regra email aceita texto à escolha nesse sítio, incluindo links Markdown.
     */
    private function maskedNewEmail(): string
    {
        $local = Str::beforeLast($this->newEmail, '@');
        $domain = Str::afterLast($this->newEmail, '@');
        $tld = Str::contains($domain, '.') ? Str::afterLast($domain, '.') : '';
        $topLevelDomain = preg_match('/\A[a-z]{2,10}\z/i', $tld) ? ".{$tld}" : '';

        return Str::substr($local, 0, 2).'•••@'.Str::substr($domain, 0, 1).'•••'.$topLevelDomain;
    }
}
