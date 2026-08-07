<?php

namespace App\Filament\Resources\Channels\Tables;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChannelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nome')->label('Canal')->searchable(),
                // O tipo na lista porque a diferenca e visivel no atendimento: janela de
                // 24h, modelo aprovado e ausencia de grupo valem so num deles.
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        \App\Models\Channel::META_CLOUD => 'Oficial',
                        \App\Models\Channel::EVOLUTION  => 'Evolution',
                        default                        => (string) $state,
                    })
                    ->color(fn (?string $state) => $state === \App\Models\Channel::META_CLOUD ? 'success' : 'gray'),
                TextColumn::make('telefone_e164')->label('Numero')->placeholder('—'),
                TextColumn::make('status')
                    ->label('Conexao')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'open'       => 'success',
                        'connecting' => 'warning',
                        default      => 'danger',
                    }),
                // Qual fluxo atende este canal. Sem esta coluna, canal sem bot e
                // silencioso: descobrir que ninguem responde automaticamente ali exigiu
                // uma investigacao no banco. Uma vez basta.
                TextColumn::make('chatbot')
                    ->label('Chatbot')
                    ->badge()
                    ->state(fn (\App\Models\Channel $record) => \App\Models\Chatbot::publicadoPara($record)?->nome ?? 'nenhum')
                    ->color(fn (string $state) => $state === 'nenhum' ? 'gray' : 'success')
                    ->tooltip('Fluxo publicado que responde neste canal. "nenhum" significa que ninguem atende automaticamente aqui.'),

                TextColumn::make('conectado_em')->label('Conectado em')->dateTime('d/m/Y H:i')->placeholder('—'),
            ])
            ->recordActions([
                // Leva para a tela do QR em vez de abrir modal: mesma tela do cadastro, mesmo
                // endereco, e da para mandar o link para o cliente conectar sozinho.
                Action::make('conectar')
                    ->label(fn ($record) => $record->status === 'open' ? 'Ver conexão' : 'Conectar')
                    ->icon('heroicon-o-qr-code')
                    ->color(fn ($record) => $record->status === 'open' ? 'gray' : 'primary')
                    ->visible(fn ($record) => $record->tipo === \App\Models\Channel::EVOLUTION)
                    ->url(fn ($record) => \App\Filament\Resources\Channels\ChannelResource::getUrl('conectar', ['record' => $record])),

                EditAction::make(),
            ])
            ->emptyStateHeading('Nenhum canal ainda')
            ->emptyStateDescription('Crie um canal e conecte um numero de WhatsApp pelo QR Code.');
    }
}
