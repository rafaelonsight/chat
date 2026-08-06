<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * O primeiro e-mail que um cliente novo recebe.
 *
 * Nao reaproveita o "esqueci minha senha" do Laravel de proposito: aquele texto diz que
 * "recebemos um pedido de redefinicao de senha", o que para quem acabou de ser cadastrado e
 * simplesmente falso — e um e-mail que comeca mentindo e um e-mail que vira denuncia de spam.
 */
class ConviteDeAcesso extends Notification
{
    public function __construct(private string $link) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // O nome do produto vem do APP_NAME e nao esta escrito aqui: ele ainda vai mudar.
        $produto = config('app.name');
        $minutos = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Seu acesso ao '.$produto)
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('A conta da sua empresa no '.$produto.' está criada. Falta só você escolher a sua senha.')
            ->action('Definir minha senha', $this->link)
            // Dizer o prazo evita o pior caso do link expirado: a pessoa achar que o sistema
            // esta quebrado em vez de pedir outro.
            ->line('Este link vale por '.$minutos.' minutos. Se ele expirar, use "Esqueci minha senha" na tela de entrada — ou peça um novo convite.')
            ->line('Se você não esperava este e-mail, pode ignorá-lo: sem definir a senha, ninguém entra.')
            ->salutation('— Equipe '.$produto);
    }
}
