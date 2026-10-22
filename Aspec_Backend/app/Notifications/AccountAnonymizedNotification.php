<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirma ao titular que os dados pessoais foram eliminados (RGPD art. 12(3) e 19). Enviada para o
 * email original, que a conta já não guarda. Sem fila: em fila, esse email ficava gravado na tabela
 * jobs depois da anonimização. Sem dados pessoais no texto.
 */
class AccountAnonymizedNotification extends Notification
{
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
     * Saudação sem nome (o perfil já foi anonimizado), confirmação, faturas mantidas e nova candidatura.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A sua conta ASPEC foi eliminada')
            ->greeting('Olá!')
            ->line('Conforme o seu pedido, os seus dados pessoais foram eliminados da plataforma ASPEC.')
            ->line('As faturas já emitidas são mantidas durante o prazo legal, por obrigação fiscal.')
            ->line('Se quiser voltar a ser membro, pode fazer uma nova candidatura.')
            ->salutation("Cumprimentos,\nASPEC");
    }
}
