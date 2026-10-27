<?php

namespace App\Filament\Revenda\Resources\Licenses\Tables;

use App\Models\License;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LicensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // License nao usa BelongsToTenant de proposito (ver o model): sem escopo
            // global para tirar, a consulta ja enxerga todo tenant sozinha.
            ->modifyQueryUsing(fn ($query) => $query->with('tenant'))
            ->columns([
                TextColumn::make('tenant.nome')
                    ->label(__('ui_c5c7d5ee3d61'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('ui_bae7d5be7082'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => License::STATUS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        License::ATIVA     => 'success',
                        License::TRIAL     => 'info',
                        License::EM_ATRASO => 'warning',
                        License::SUSPENSA, License::CANCELADA => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('plano')
                    ->label(__('ui_eac9e53a1678'))
                    ->placeholder('—'),

                TextColumn::make('vence_em')
                    ->label(__('ui_39e35d115789'))
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading(__('ui_59d766a8c286'))
            ->emptyStateDescription(__('ui_6c8a1240a3f7'));
    }
}
