<?php

namespace App\Filament\Resources\MessageTemplates\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MessageTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('titulo')->label('Título')->searchable()->sortable(),
                TextColumn::make('atalho')->label('Atalho')->badge()->searchable(),
                TextColumn::make('corpo')->label('Mensagem')->limit(60)->wrap(),
                IconColumn::make('ativo')->label('Ativo')->boolean(),
            ])
            ->defaultSort('titulo')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('Nenhum modelo ainda')
            ->emptyStateDescription('Modelos aparecem no compositor do atendimento, para responder em um clique.');
    }
}
