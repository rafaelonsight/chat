<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListChannels extends ListRecords
{
    protected static string $resource = ChannelResource::class;

    /**
     * A ESCOLHA DO TIPO VEM ANTES DO FORMULARIO.
     *
     * Antes havia um botao so, e um Select "tipo" dentro do formulario mostrando e escondendo
     * campos. Um formulario que serve dois casos nao serve bem nenhum: quem vem conectar pelo
     * QR encara campos de Phone Number ID antes de entender que nao sao dele.
     *
     * Dois botoes com nome do que a pessoa quer fazer, e cada caminho vira uma tela feita para
     * ele.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('novo_qr')
                ->label('Conectar por QR Code')
                ->icon('heroicon-o-qr-code')
                ->url(static::getResource()::getUrl('create').'?tipo='.Channel::EVOLUTION),

            Action::make('novo_oficial')
                ->label('WhatsApp oficial (Meta)')
                ->icon('heroicon-o-check-badge')
                ->color('gray')
                ->url(static::getResource()::getUrl('create').'?tipo='.Channel::META_CLOUD),
        ];
    }
}
