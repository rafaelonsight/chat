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
                TextColumn::make('titulo')->label(__('ui_98a5efa60beb'))->searchable()->sortable(),
                TextColumn::make('atalho')->label(__('ui_fd3728e73db3'))->badge()->searchable(),
                TextColumn::make('corpo')->label(__('ui_eaffb6306dc5'))->limit(60)->wrap(),
                IconColumn::make('ativo')->label(__('ui_8263fde6ccc4'))->boolean(),
            ])
            ->defaultSort('titulo')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading(__('ui_b7ed7161f101'))
            ->emptyStateDescription(__('ui_405c5bd44b4b'));
    }
}
