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
                TextColumn::make('name')->label(__('ui_13030dd962f4'))->searchable()->sortable(),
                TextColumn::make('email')->label(__('ui_0d01e2e86669'))->searchable()->copyable(),
                TextColumn::make('perfil')->label(__('ui_d197d5671878'))->formatStateUsing(fn ($state) => \App\Models\User::PERFIS[$state] ?? 'Atendente')->badge(),
                IconColumn::make('super_admin')->label(__('ui_7da1c9a46614'))->boolean(),
                TextColumn::make('created_at')->label(__('ui_16379961e450'))->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()])
            ->emptyStateHeading(__('ui_1f7c3a7eed00'));
    }
}
