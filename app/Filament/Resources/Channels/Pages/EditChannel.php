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

    public function mount(int | string $record): void
    {
        parent::mount($record);

        // Flash de reconexao que trocou de conta/Pagina em silencio (Messenger e
        // Instagram, ver MetaSocialSignupController/MetaInstagramSignupController) —
        // sem isto o aviso ficava so no log, nunca chegava a quem reconectou.
        if ($aviso = session('aviso_troca_de_conta_social')) {
            Notification::make()
                ->warning()
                ->title(__('ui_caf8ac0ce0db'))
                ->body($aviso)
                ->persistent()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // QR Code so existe no caminho nao oficial. Mostrar no oficial seria oferecer
            // um botao que nunca funciona.
            Action::make('conectar')
                ->label(__('ui_8eaf712a3e43'))
                ->icon('heroicon-o-qr-code')
                ->visible(fn () => $this->record->tipo === Channel::EVOLUTION)
                ->modalHeading(__('ui_1cf763b5f6a9'))
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
                ->label(__('ui_29bb1dc94cb6'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => $this->record->tipo === Channel::META_CLOUD)
                ->requiresConfirmation()
                ->modalHeading(__('ui_d994f514f037').($this->record->nome ?? 'este canal').'?')
                ->modalDescription(
                    __('ui_46be156ba538')
                    .'canal pelo que você escolher no Facebook a seguir. Se escolher a conta '
                    .'errada, as conversas deste canal passam a sair pelo número errado — '
                    .'confira com cuidado antes de continuar.'
                )
                ->modalSubmitActionLabel(__('ui_dd4d82f93280'))
                ->url(fn () => ChannelResource::getUrl('conectar-meta').'?canal='.$this->record->id),

            // Mesmo cuidado do reconectar_meta: escolher a Pagina/conta errada troca o
            // dono das conversas deste canal em silencio. Messenger reconecta pelo
            // Facebook (Pagina); Instagram loga direto no Instagram — sao caminhos
            // diferentes, e o rotulo/aviso precisam dizer qual dos dois.
            Action::make('reconectar_meta_social')
                ->label(fn () => $this->record->tipo === Channel::INSTAGRAM ? 'Reconectar pelo Instagram' : 'Reconectar pelo Facebook')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => in_array($this->record->tipo, [Channel::MESSENGER, Channel::INSTAGRAM], true))
                ->requiresConfirmation()
                ->modalHeading(__('ui_d994f514f037').($this->record->nome ?? 'este canal').'?')
                ->modalDescription(fn () => $this->record->tipo === Channel::INSTAGRAM
                    ? 'Isto troca a conta do Instagram e a credencial deste canal pela '
                        .'que você escolher a seguir. Se logar com a conta errada, as '
                        .'conversas deste canal passam a sair pela conta errada — confira '
                        .'com cuidado antes de continuar.'
                    : 'Isto troca a Página do Facebook e a credencial deste canal pelo que '
                        .'você escolher a seguir. Se escolher a Página errada, as conversas '
                        .'deste canal passam a sair pela conta errada — confira com cuidado '
                        .'antes de continuar.')
                ->modalSubmitActionLabel(fn () => $this->record->tipo === Channel::INSTAGRAM ? 'Continuar para o Instagram' : 'Continuar para o Facebook')
                ->url(fn () => ChannelResource::getUrl('conectar-meta-social').'?canal='.$this->record->id),

            // O equivalente do QR Code para o chat de site: nao ha nada para "conectar" —
            // o unico passo que falta e copiar o <script> para o HTML do site.
            Action::make('ver_codigo_site')
                ->label(__('ui_bf50fb4b9ba7'))
                ->icon('heroicon-o-code-bracket')
                ->visible(fn () => $this->record->tipo === Channel::SITE)
                ->modalHeading(__('ui_897619c4cf66'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar')
                ->modalContent(fn () => view('filament.channel-site-modal', ['channel' => $this->record])),

            // O equivalente do QR Code no oficial: perguntar a Meta se a configuracao
            // funciona, em vez de descobrir com cliente na linha.
            Action::make('conferir')
                ->label(__('ui_7b11d6bab5c8'))
                ->icon('heroicon-o-shield-check')
                ->visible(fn () => $this->record->tipo === Channel::META_CLOUD)
                ->action(function () {
                    $canal = $this->record->refresh();
                    $r = app(MetaCloudEnviador::class)->conferir($canal);

                    if (! $r['ok']) {
                        $canal->update(['ultimo_erro' => $r['erro']]);

                        Notification::make()
                            ->danger()
                            ->title(__('ui_e383d49db650'))
                            ->body($r['erro'])
                            ->persistent()
                            ->send();

                        return;
                    }

                    $canal->update(['status' => 'open', 'ultimo_erro' => null]);

                    Notification::make()
                        ->success()
                        ->title(__('ui_d439b3cdf8ae'))
                        ->body("{$r['numero']} · {$r['nome']} · qualidade {$r['qualidade']} · {$r['situacao']}")
                        ->persistent()
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }
}
