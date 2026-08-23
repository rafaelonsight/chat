<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Services\Canais\MetaCloudEnviador;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditChannel extends EditRecord
{
    protected static string $resource = ChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // QR Code so existe no caminho nao oficial. Mostrar no oficial seria oferecer
            // um botao que nunca funciona.
            Action::make('conectar')
                ->label('Conectar numero')
                ->icon('heroicon-o-qr-code')
                ->visible(fn () => $this->record->tipo === Channel::EVOLUTION)
                ->modalHeading('Conectar numero de WhatsApp')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar')
                ->modalContent(fn () => view('filament.channel-qr-modal', ['channel' => $this->record])),

            // Token expira ou e revogado (cliente trocou a senha, removeu o app do
            // Business Manager etc.) — sem isto a unica saida seria apagar o canal e
            // recriar, perdendo o id e desconectando o historico de conversas dele.
            //
            // Confirmacao antes de sair, de proposito: escolher a WABA errada no popup
            // sobrescreve waba_id/phone_number_id/token deste canal em silencio — o
            // mesmo numero de mensagens do cliente errado, sem aviso nenhum na hora.
            Action::make('reconectar_meta')
                ->label('Reconectar pelo Facebook')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => $this->record->tipo === Channel::META_CLOUD)
                ->requiresConfirmation()
                ->modalHeading('Reconectar '.($this->record->nome ?? 'este canal').'?')
                ->modalDescription(
                    'Isto troca a conta do WhatsApp Business, o número e a credencial deste '
                    .'canal pelo que você escolher no Facebook a seguir. Se escolher a conta '
                    .'errada, as conversas deste canal passam a sair pelo número errado — '
                    .'confira com cuidado antes de continuar.'
                )
                ->modalSubmitActionLabel('Continuar para o Facebook')
                ->url(fn () => ChannelResource::getUrl('conectar-meta').'?canal='.$this->record->id),

            // Mesmo cuidado do reconectar_meta: escolher a Pagina errada troca o dono
            // das conversas deste canal em silencio.
            Action::make('reconectar_meta_social')
                ->label('Reconectar pelo Facebook')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => in_array($this->record->tipo, [Channel::MESSENGER, Channel::INSTAGRAM], true))
                ->requiresConfirmation()
                ->modalHeading('Reconectar '.($this->record->nome ?? 'este canal').'?')
                ->modalDescription(
                    'Isto troca a Página do Facebook e a credencial deste canal pelo que '
                    .'você escolher a seguir. Se escolher a Página errada, as conversas '
                    .'deste canal passam a sair pela conta errada — confira com cuidado '
                    .'antes de continuar.'
                )
                ->modalSubmitActionLabel('Continuar para o Facebook')
                ->url(fn () => ChannelResource::getUrl('conectar-meta-social').'?canal='.$this->record->id),

            // O equivalente do QR Code para o chat de site: nao ha nada para "conectar" —
            // o unico passo que falta e copiar o <script> para o HTML do site.
            Action::make('ver_codigo_site')
                ->label('Ver código de instalação')
                ->icon('heroicon-o-code-bracket')
                ->visible(fn () => $this->record->tipo === Channel::SITE)
                ->modalHeading('Código de instalação')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar')
                ->modalContent(fn () => view('filament.channel-site-modal', ['channel' => $this->record])),

            // O equivalente do QR Code no oficial: perguntar a Meta se a configuracao
            // funciona, em vez de descobrir com cliente na linha.
            Action::make('conferir')
                ->label('Conferir na Meta')
                ->icon('heroicon-o-shield-check')
                ->visible(fn () => $this->record->tipo === Channel::META_CLOUD)
                ->action(function () {
                    $canal = $this->record->refresh();
                    $r = app(MetaCloudEnviador::class)->conferir($canal);

                    if (! $r['ok']) {
                        $canal->update(['ultimo_erro' => $r['erro']]);

                        Notification::make()
                            ->danger()
                            ->title('A Meta recusou')
                            ->body($r['erro'])
                            ->persistent()
                            ->send();

                        return;
                    }

                    $canal->update(['status' => 'open', 'ultimo_erro' => null]);

                    Notification::make()
                        ->success()
                        ->title('A configuracao confere')
                        ->body("{$r['numero']} · {$r['nome']} · qualidade {$r['qualidade']} · {$r['situacao']}")
                        ->persistent()
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }
}
