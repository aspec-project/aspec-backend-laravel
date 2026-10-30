<?php

namespace App\Notifications;

use App\Models\EventInstance;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de que o membro recebeu um cartão de oração na distribuição de um evento. Nunca leva o
 * conteúdo nem o nome do autor: são dados de crenças religiosas (RGPD art. 9.º) e não devem sair da
 * plataforma (nem ficar no laravel.log com MAIL_MAILER=log). Não depende das preferências de email.
 */
class PrayerCardReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Se o evento for apagado antes do envio, o email é descartado sem erro.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Recebe o evento onde o cartão foi escrito (só o nome e a data aparecem no email).
     */
    public function __construct(public EventInstance $event) {}

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
     * Volta a confirmar o destinatário no momento do envio: se a conta deixou de estar ativa (ou
     * ficou sem perfil) enquanto o email esperava na fila, não é enviado.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return User::query()
            ->activeWithProfile()
            ->whereKey($notifiable->getKey())
            ->exists();
    }

    /**
     * Assunto, evento e botão para "Os meus cartões de oração" no frontend. Sem saudação com o nome,
     * para o email não ter dados pessoais além do endereço.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url'), '/').'/perfil/cartoes-oracao';

        return (new MailMessage)
            ->subject('Recebeu um cartão de oração')
            ->greeting('Olá!')
            ->line("Recebeu um cartão de oração de outro participante no evento **{$this->event->name}** ({$this->event->event_date->format('d/m/Y')}).")
            ->line('Nos próximos '.config('prayer_cards.prayer_days').' dias, ore pelos pedidos de quem o escreveu. O cartão fica disponível na plataforma durante '.config('prayer_cards.retention_months').' meses a contar da data do evento.')
            ->action('Ver os meus cartões de oração', $url)
            ->salutation("Cumprimentos,\nASPEC");
    }
}
