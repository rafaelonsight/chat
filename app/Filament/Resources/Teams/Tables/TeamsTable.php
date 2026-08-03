<?php

namespace App\Filament\Resources\Teams\Tables;

use App\Models\Conversation;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('users'))
            ->columns([
                TextColumn::make('nome')->label('Equipe')->searchable()->sortable(),
                TextColumn::make('descricao')->label('Descrição')->placeholder('—')->wrap(),

                TextColumn::make('users_count')
                    ->label('Pessoas')
                    ->badge()
                    ->sortable(),

                TextColumn::make('membros')
                    ->label('Quem')
                    ->state(fn ($record) => $record->users->pluck('name')->join(', ') ?: '—')
                    ->wrap(),

                TextColumn::make('na_fila')
                    ->label('Na fila')
                    ->state(fn ($record) => Conversation::where('team_id', $record->id)
                        ->where('status', Conversation::NOVA)
                        ->count())
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),

                IconColumn::make('ativa')->label('Ativa')->boolean(),
            ])
            ->defaultSort('nome')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('Nenhuma equipe ainda')
            ->emptyStateDescription('Sem equipe, o atendimento funciona igual ao de hoje: todos veem tudo.');
    }
}
