<?php

namespace App\Filament\Resources\ContactFields\Tables;

use App\Models\ContactField;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContactFieldsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('values'))
            ->columns([
                TextColumn::make('ordem')->label('#')->sortable()->width('1%'),

                TextColumn::make('nome')->label(__('ui_9c034704bcab'))->searchable()->sortable(),

                TextColumn::make('tipo')
                    ->label(__('ui_6cc6198d9e75'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => ContactField::TIPOS[$state] ?? $state),

                TextColumn::make('opcoes')
                    ->label(__('ui_4c8df52c061d'))
                    ->state(fn (ContactField $r) => $r->usaOpcoes() ? implode(' · ', $r->opcoes ?? []) : '—')
                    ->wrap()
                    ->limit(60),

                IconColumn::make('obrigatorio')->label(__('ui_5463a000147b'))->boolean(),

                TextColumn::make('values_count')
                    ->label(__('ui_67b3a9d51f5d'))
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'primary' : 'gray'),
            ])
            ->defaultSort('ordem')
            ->reorderable('ordem')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading(__('ui_67f3701dc9ec'))
            ->emptyStateDescription(__('ui_46d9d646c6f1'));
    }
}
