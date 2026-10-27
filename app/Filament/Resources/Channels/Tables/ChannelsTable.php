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
                TextColumn::make('nome')->label(__('ui_61f21e6ff9d6'))->searchable(),
                // O tipo na lista porque a diferenca e visivel no atendimento: janela de
                // 24h, modelo aprovado e ausencia de grupo valem so num deles.
                TextColumn::make('tipo')
                    ->label(__('ui_6cc6198d9e75'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        \App\Models\Channel::META_CLOUD => __('Oficial'),
                        \App\Models\Channel::EVOLUTION  => 'Evolution',
                        default                        => (string) $state,
                    })
                    ->color(fn (?string $state) => $state === \App\Models\Channel::META_CLOUD ? 'success' : 'gray'),
                TextColumn::make('telefone_e164')->label(__('ui_6d069f697319'))->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('ui_deed29689ce7'))->formatStateUsing(fn (string $state) => match ($state) { 'open' => __('Connected'), 'connecting' => __('Connecting'), default => __('Disconnected') })
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
                    ->label(__('ui_5da7f04ce2e1'))
                    ->badge()
                    ->state(fn (\App\Models\Channel $record) => \App\Models\Chatbot::publicadoPara($record)?->nome ?? __('nenhum'))
                    ->color(fn (string $state) => $state === __('nenhum') ? 'gray' : 'success')
                    ->tooltip(__('ui_8c9b0b79ca63')),

                TextColumn::make('conectado_em')->label(__('ui_abd5794abec9'))->dateTime('d/m/Y H:i')->timezone(fn () => auth()->user()?->tenant?->fuso_horario ?? 'America/Sao_Paulo')->placeholder('—'),
            ])
            ->recordActions([
                // Leva para a tela do QR em vez de abrir modal: mesma tela do cadastro, mesmo
                // endereco, e da para mandar o link para o cliente conectar sozinho.
                Action::make('conectar')
                    ->label(fn ($record) => $record->status === 'open' ? __('Ver conexão') : __('Conectar'))
                    ->icon('heroicon-o-qr-code')
                    ->color(fn ($record) => $record->status === 'open' ? 'gray' : 'primary')
                    ->visible(fn ($record) => $record->tipo === \App\Models\Channel::EVOLUTION)
                    ->url(fn ($record) => \App\Filament\Resources\Channels\ChannelResource::getUrl('conectar', ['record' => $record])),

                EditAction::make(),
            ])
            ->emptyStateHeading(__('ui_2891b2f3d4f5'))
            ->emptyStateDescription(__('ui_e7838322579b'));
    }
}
