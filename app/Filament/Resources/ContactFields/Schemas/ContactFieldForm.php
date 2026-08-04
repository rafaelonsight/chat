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
                ->label('Nome do campo')
                ->required()
                ->maxLength(60)
                ->unique(ignoreRecord: true)
                ->helperText('É o rótulo que aparece no cadastro do contato. Ex.: Contrato, Plano, Vencimento.'),

            Select::make('tipo')
                ->label('Tipo')
                ->options(ContactField::TIPOS)
                ->default(ContactField::TEXTO_CURTO)
                ->required()
                ->native(false)
                ->live()
                ->helperText('O tipo decide o que a tela desenha e o que é aceito. Mudar depois não converte o que já foi preenchido.'),

            // Só aparece para os tipos que dependem de opcoes: lista vazia num
            // select deixaria um campo impossivel de preencher.
            Repeater::make('opcoes')
                ->label('Opções')
                ->simple(TextInput::make('opcao')->required()->maxLength(60))
                ->visible(fn (Get $get) => in_array($get('tipo'), ContactField::COM_OPCOES, true))
                ->required(fn (Get $get) => in_array($get('tipo'), ContactField::COM_OPCOES, true))
                ->minItems(1)
                ->reorderable()
                ->addActionLabel('Adicionar opção'),

            TextInput::make('ajuda')
                ->label('Texto de ajuda')
                ->maxLength(120)
                ->helperText('Opcional. Aparece embaixo do campo, para quem preenche.'),

            Toggle::make('obrigatorio')
                ->label('Obrigatório')
                ->helperText('Contato não pode ser salvo sem este campo preenchido.'),

            TextInput::make('ordem')
                ->label('Ordem')
                ->numeric()
                ->default(0)
                ->required()
                ->helperText('Menor aparece primeiro no cadastro.'),
        ]);
    }
}
