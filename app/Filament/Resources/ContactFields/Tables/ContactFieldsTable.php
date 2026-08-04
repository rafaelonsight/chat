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

                TextColumn::make('nome')->label('Campo')->searchable()->sortable(),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => ContactField::TIPOS[$state] ?? $state),

                TextColumn::make('opcoes')
                    ->label('Opções')
                    ->state(fn (ContactField $r) => $r->usaOpcoes() ? implode(' · ', $r->opcoes ?? []) : '—')
                    ->wrap()
                    ->limit(60),

                IconColumn::make('obrigatorio')->label('Obrigatório')->boolean(),

                TextColumn::make('values_count')
                    ->label('Preenchidos')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'primary' : 'gray'),
            ])
            ->defaultSort('ordem')
            ->reorderable('ordem')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('Nenhum campo personalizado')
            ->emptyStateDescription('Serve para o que é do seu negócio e não cabe nos campos fixos: número do pedido, plano contratado, dia de vencimento, código do cliente.');
    }
}
