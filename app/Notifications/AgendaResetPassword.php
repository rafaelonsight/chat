<?php

namespace App\Notifications;

class AgendaResetPassword extends \Illuminate\Auth\Notifications\ResetPassword
{
    public string $url;

    public function toMail($notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        // Account security follows the interface language, not WhatsApp service language.
        $english = \App\Support\Idiomas::interface($notifiable) === 'en_GB';
        $copy = $english ? [
            'subject'=>'Reset your password · Virtus Agenda', 'title'=>'Set a new password',
            'intro'=>'We received a request to reset your Virtus Agenda password.',
            'button'=>'Reset password', 'expiry'=>'This link expires in :minutes minutes and can only be used once.',
            'ignore'=>'If you did not request this change, ignore this email. Your password will remain unchanged.',
            'alternative'=>'If the button does not work, copy and paste this address into your browser:',
            'minimum'=>'Your new password must contain at least 8 characters.',
        ] : [
            'subject'=>'Redefinir sua senha · Virtus Agenda', 'title'=>'Defina uma nova senha',
            'intro'=>'Recebemos uma solicitação para redefinir sua senha do Virtus Agenda.',
            'button'=>'Redefinir senha', 'expiry'=>'Este link expira em :minutes minutos e pode ser usado uma única vez.',
            'ignore'=>'Se você não solicitou esta alteração, ignore este e-mail. Sua senha continuará a mesma.',
            'alternative'=>'Se o botão não funcionar, copie e cole este endereço no navegador:',
            'minimum'=>'Sua nova senha deve ter pelo menos 8 caracteres.',
        ];
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
        $copy['expiry'] = str_replace(':minutes', (string) $minutes, $copy['expiry']);
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject($copy['subject'])
            ->view('emails.agenda-reset-password', [
                'copy'=>$copy, 'url'=>$this->resetUrl($notifiable), 'language'=>$english ? 'en-GB' : 'pt-BR',
            ]);
    }

    protected function resetUrl($notifiable): string
    {
        return $this->url;
    }
}
