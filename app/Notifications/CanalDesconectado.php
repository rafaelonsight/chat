<?php

namespace App\Notifications;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use Filament\Actions\Action;
use Filament\Notifications\Notification as AvisoDoPainel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/*
 * O WhatsApp da conta caiu. Vai para quem pode reconectar: o administrador da conta.
 *
 * Na fila de proposito: quem dispara e o webhook da Evolution, e SMTP lento ou fora do ar nao pode
 * atrasar nem derrubar o processamento das mensagens que continuam chegando pelos outros canais.
 */
class CanalDesconectado extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Channel $canal) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public static function link(Channel $canal): string
    {
        return ChannelResource::getUrl('conectar', ['record' => $canal], panel: 'admin');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $produto = config('app.name');

        return (new MailMessage)
            ->subject("WhatsApp desconectado: {$this->canal->nome}")
            ->greeting("Olá, {$notifiable->name}!")
            ->line("O número {$this->canal->nome} foi desconectado do {$produto}. Enquanto ele estiver fora, as mensagens dos clientes não chegam ao painel.")
            ->line('Para voltar, abra o canal e leia o QR code com o celular desse número (WhatsApp → Aparelhos conectados).')
            ->action('Reconectar agora', self::link($this->canal))
            ->salutation("— Equipe {$produto}");
    }

    public function toDatabase(object $notifiable): array
    {
        return AvisoDoPainel::make()
            ->danger()
            ->title("WhatsApp desconectado: {$this->canal->nome}")
            ->body('As mensagens dos clientes não chegam enquanto o número estiver fora. Leia o QR code de novo para reconectar.')
            ->actions([Action::make('reconectar')->label('Reconectar')->button()->url(self::link($this->canal))])
            ->getDatabaseMessage();
    }
}
