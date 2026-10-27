<?php

namespace App\Filament\Revenda\Resources\Licenses\Schemas;

use App\Models\License;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LicenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')
                ->label(__('ui_bae7d5be7082'))
                ->options(fn () => \App\Support\Idiomas::opcoes(License::STATUS))
                ->required(),

            Select::make('plano')
                ->label(__('ui_eac9e53a1678'))
                ->options(fn () => collect(config('modulos.planos'))->map(fn ($p) => $p['nome'])->all())
                ->placeholder(config('modulos.planos.'.config('modulos.plano_padrao').'.nome').' (padrão)')
                ->live(),

            \Filament\Forms\Components\CheckboxList::make('modulos')
                ->label('Módulos a mais')
                ->helperText('Somados ao que o plano já inclui.')
                ->options(fn ($get) => collect(config('modulos.catalogo'))
                    ->except(config('modulos.planos.'.($get('plano') ?: config('modulos.plano_padrao')).'.modulos', []))
                    ->all())
                ->columns(2),

            DateTimePicker::make('vence_em')
                ->label(__('ui_39e35d115789'))
                ->native(false)
                ->helperText(__('ui_62d5cdba1504')),

            Textarea::make('motivo')
                ->label(__('ui_7130b14b6c3e'))
                ->rows(3)
                ->helperText(__('ui_37f6dfc1272e')),
        ]);
    }
}
