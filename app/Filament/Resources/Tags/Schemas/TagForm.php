<?php

namespace App\Filament\Resources\Tags\Schemas;

use App\Models\Tag;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

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
                // Alimenta a previa do seletor de cor enquanto se digita.
                ->live(onBlur: true)
                ->helperText('Curto, porque vira uma pilula na tela. Ex.: Inadimplente, Cliente, Fornecedor.'),

            // Grade com as 24 cores da paleta. Paleta fechada de proposito: cor
            // livre gera etiqueta ilegivel. A validacao repete a lista porque o
            // valor chega do navegador.
            ViewField::make('cor')
                ->label('Cor')
                ->view('filament.forms.components.seletor-cor')
                ->default('cinza')
                ->required()
                ->rule(Rule::in(array_keys(Tag::PALETA)))
                ->helperText('Clique na cor. As setas do teclado também andam pela paleta.'),
        ]);
    }
}
