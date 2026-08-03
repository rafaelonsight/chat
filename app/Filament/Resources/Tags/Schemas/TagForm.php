<?php

namespace App\Filament\Resources\Tags\Schemas;

use App\Models\Tag;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nome')
                ->label('Nome')
                ->required()
                ->maxLength(40)
                ->unique(ignoreRecord: true)
                ->helperText('Curto, porque vira uma pilula na tela. Ex.: Inadimplente, Cliente, Fornecedor.'),

            Select::make('cor')
                ->label('Cor')
                ->options(Tag::CORES)
                ->default('cinza')
                ->required()
                ->native(false)
                ->helperText('Paleta fechada de propósito: cor livre gera etiqueta ilegível.'),
        ]);
    }
}
