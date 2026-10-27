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
        // Chat entra por codigo enviado por e-mail; a edicao Agenda entra por senha.
        $senha = (bool) config('agenda.edition');

        if ($notifiable->preferredLocale()==='en_GB') {
            return (new MailMessage)->mailer(config('acesso.mailer'))
                ->subject('Your access to '.$produto)->greeting('Hello, '.$notifiable->name.'!')
                ->line($senha ? 'Your account is ready. Set your password to sign in with your email address.' : 'Your company account is ready. Use your registered email address to receive an access code.')
                ->action($senha ? 'Set password' : 'Sign in with a code',$this->link)
                ->line($senha ? 'This link expires in sixty minutes and can only be used once.' : 'Each code is valid for ten minutes and can only be used once.')
                ->line('If you were not expecting this email, please ignore it.')
                ->salutation('— The '.$produto.' team');
        }
        return (new MailMessage)
            ->mailer(config('acesso.mailer'))
            ->subject(__('ui_c93958b88724').$produto)
            ->greeting(__('ui_d79eb7b9e43a').$notifiable->name.'!')
            ->line($senha ? 'Sua conta no '.$produto.' está criada. Defina sua senha para entrar com seu e-mail.' : __('ui_f45d1cc212d8').$produto.' está criada. Use seu e-mail cadastrado para receber um código de acesso.')
            ->action($senha ? 'Definir senha' : __('ui_78ddfa5d2b6e'), $this->link)
            ->line($senha ? 'Este link expira em sessenta minutos e pode ser usado uma única vez.' : __('ui_f27f6240afba'))
            ->line(__('ui_df2679b2d341'))
            ->salutation(__('ui_6f3aac4aa145').$produto);
    }
}
