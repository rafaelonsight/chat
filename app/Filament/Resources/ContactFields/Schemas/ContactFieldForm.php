<?php

namespace App\Filament\Resources\ContactFields\Schemas;

use App\Models\ContactField;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ContactFieldForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nome')
                ->label(__('ui_7a6dd3377d75'))
                ->required()
                ->maxLength(60)
                ->unique(ignoreRecord: true)
                ->helperText(__('ui_1f10d4cb84c5')),

            Select::make('tipo')
                ->label(__('ui_6cc6198d9e75'))
                ->options(fn () => \App\Support\Idiomas::opcoes(ContactField::TIPOS))
                ->default(ContactField::TEXTO_CURTO)
                ->required()
                ->native(false)
                ->live()
                ->helperText(__('ui_1fe484f5bab9')),

            // Só aparece para os tipos que dependem de opcoes: lista vazia num
            // select deixaria um campo impossivel de preencher.
            Repeater::make('opcoes')
                ->label(__('ui_4c8df52c061d'))
                ->simple(TextInput::make('opcao')->required()->maxLength(60))
                ->visible(fn (Get $get) => in_array($get('tipo'), ContactField::COM_OPCOES, true))
                ->required(fn (Get $get) => in_array($get('tipo'), ContactField::COM_OPCOES, true))
                ->minItems(1)
                ->reorderable()
                ->addActionLabel('Adicionar opção'),

            TextInput::make('ajuda')
                ->label(__('ui_6019faa6dc2c'))
                ->maxLength(120)
                ->helperText(__('ui_4edaf3352fd9')),

            Toggle::make('obrigatorio')
                ->label(__('ui_5463a000147b'))
                ->helperText(__('ui_52f87492ff53')),

            TextInput::make('ordem')
                ->label(__('ui_bb15f40ef54b'))
                ->numeric()
                ->default(0)
                ->required()
                ->helperText(__('ui_85bc7db02bc7')),
        ]);
    }
}
