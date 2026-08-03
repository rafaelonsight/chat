<?php

namespace App\Filament\Resources\Chatbots\Tables;

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
            ->modifyQueryUsing(fn ($query) => $query->withCount('nodes'))
            ->columns([
                TextColumn::make('nome')->label('Fluxo')->searchable()->sortable(),

                TextColumn::make('channel.nome')
                    ->label('Canal')
                    ->placeholder('Todos'),

                TextColumn::make('nodes_count')
                    ->label('Opções')
                    ->badge()
                    ->sortable(),

                IconColumn::make('ativo')->label('Ativo')->boolean(),
            ])
            ->defaultSort('nome')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('Nenhum fluxo ainda')
            ->emptyStateDescription('Sem fluxo ativo, o atendimento funciona igual ao de hoje: a mensagem cai em Novos e uma pessoa responde.');
    }
}
