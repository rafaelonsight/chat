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
