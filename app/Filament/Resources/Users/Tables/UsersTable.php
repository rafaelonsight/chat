<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable()->sortable(),
                TextColumn::make('email')->label('E-mail')->searchable()->copyable(),
                IconColumn::make('admin')->label('Admin')->boolean(),
                TextColumn::make('created_at')->label('Criado em')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('Nenhum usuario ainda');
    }
}
