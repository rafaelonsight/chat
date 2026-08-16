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
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
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
                    ->label('Plano')
                    ->placeholder('—'),

                TextColumn::make('vence_em')
                    ->label('Vence em')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading('Nenhuma licença ainda')
            ->emptyStateDescription('Toda conta nasce com uma licença — cadastre um cliente na tela de Clientes.');
    }
}
