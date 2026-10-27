<?php

namespace App\Filament\Resources\Teams\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TeamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nome')
                ->label(__('ui_3cdcae7fed5d'))
                ->required()
                ->maxLength(60)
                ->unique(ignoreRecord: true)
                ->helperText(__('ui_c130847f55d7')),

            TextInput::make('descricao')
                ->label(__('ui_469768598e0f'))
                ->maxLength(160)
                ->helperText(__('ui_ff037618f8cd')),

            // Quem faz parte. O papel no pivô fica em atendente por padrão;
            // supervisor existe no modelo e ganha poderes de tela mais adiante.
            Select::make('users')
                ->label(__('ui_ca98d21caa18'))
                ->relationship('users', 'name')
                ->multiple()
                ->preload()
                ->searchable()
                ->helperText(__('ui_2da76d7b94c5')),

            Toggle::make('ativa')
                ->label(__('ui_f21c4ff2bbb3'))
                ->default(true)
                // Na padrao o botao vem travado: o modelo recusaria a mudanca, e oferecer para
                // depois negar e pior que nao oferecer.
                ->disabled(fn (?\App\Models\Team $record) => (bool) $record?->padrao)
                ->helperText(__('ui_ee491e271c95')),
        ]);
    }
}
