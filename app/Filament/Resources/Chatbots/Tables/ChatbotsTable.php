<?php

namespace App\Filament\Resources\Chatbots\Tables;

use App\Filament\Resources\Chatbots\ChatbotResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChatbotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('steps'))
            ->columns([
                TextColumn::make('nome')->label(__('ui_f7cf4eda51a1'))->searchable()->sortable(),

                TextColumn::make('canais')->label(__('ui_5f86be3deae7'))
                    ->getStateUsing(fn ($record) => $record->channel_ids !== null
                        ? \App\Models\Channel::whereIn('id', $record->channel_ids)->pluck('nome')->implode(', ')
                        : ($record->channel?->nome ?? 'Todos (legado)')),

                // Passos do fluxo, e nao "opcoes" da arvore antiga. A coluna antiga
                // contava nodes, que estavam sempre em zero: a lista mostrava "Opções: 0"
                // para um fluxo com quatro passos funcionando.
                TextColumn::make('steps_count')
                    ->label(__('ui_b63cd9edd6fe'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('ui_2d3abf3de54d'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state === 'publicado' ? 'Publicado' : 'Rascunho')
                    ->color(fn (?string $state) => $state === 'publicado' ? 'success' : 'warning'),

                IconColumn::make('ativo')->label(__('ui_8263fde6ccc4'))->boolean(),
            ])
            ->defaultSort('nome')
            ->recordActions([
                // O fluxo e onde o trabalho acontece; abrir direto da lista evita
                // que o usuario tenha que descobrir onde fica.
                Action::make('fluxo')
                    ->label(__('ui_91c6e6351e39'))
                    ->icon('heroicon-o-share')
                    ->url(fn ($record) => ChatbotResource::getUrl('fluxo', ['record' => $record])),

                EditAction::make()->label(__('ui_9751f876e9e7')),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('ui_ee77da152677'))
            ->emptyStateDescription(__('ui_4b5a08fdb93a'));
    }
}
