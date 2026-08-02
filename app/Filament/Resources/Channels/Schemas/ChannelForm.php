<?php

namespace App\Filament\Resources\Channels\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ChannelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nome')
                ->label('Nome do canal')
                ->helperText('Como voce identifica este numero. Ex.: Comercial, Cobranca.')
                ->required()
                ->maxLength(255),

            // Tudo abaixo e derivado: tenant vem do usuario logado, o segredo e
            // o nome da instancia sao gerados, e o status vem do WhatsApp.
            Placeholder::make('instance_name')
                ->label('Instancia na Evolution')
                ->content(fn ($record) => $record?->instance_name ?? 'gerada ao salvar')
                ->visibleOn('edit'),

            Placeholder::make('status')
                ->label('Conexao')
                ->content(fn ($record) => match ($record?->status) {
                    'open'       => 'conectado',
                    'connecting' => 'conectando',
                    null         => '—',
                    default      => $record->status,
                })
                ->visibleOn('edit'),

            Placeholder::make('telefone_e164')
                ->label('Numero')
                ->content(fn ($record) => $record?->telefone_e164 ?? 'aparece apos conectar')
                ->visibleOn('edit'),

            Placeholder::make('ultimo_erro')
                ->label('Ultimo erro')
                ->content(fn ($record) => $record?->ultimo_erro ?: 'nenhum')
                ->visibleOn('edit')
                ->columnSpanFull(),
        ]);
    }
}
