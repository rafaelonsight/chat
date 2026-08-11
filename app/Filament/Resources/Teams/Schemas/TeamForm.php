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
                ->label('Nome da equipe')
                ->required()
                ->maxLength(60)
                ->unique(ignoreRecord: true)
                ->helperText('É para cá que o chatbot vai entregar a conversa. Ex.: Suporte, Financeiro, Comercial.'),

            TextInput::make('descricao')
                ->label('Descrição')
                ->maxLength(160)
                ->helperText('O que essa equipe atende. Opcional.'),

            // Quem faz parte. O papel no pivô fica em atendente por padrão;
            // supervisor existe no modelo e ganha poderes de tela mais adiante.
            Select::make('users')
                ->label('Quem faz parte')
                ->relationship('users', 'name')
                ->multiple()
                ->preload()
                ->searchable()
                ->helperText('Uma pessoa pode estar em várias equipes — em equipe pequena é o normal.'),

            Toggle::make('ativa')
                ->label('Ativa')
                ->default(true)
                // Na padrao o botao vem travado: o modelo recusaria a mudanca, e oferecer para
                // depois negar e pior que nao oferecer.
                ->disabled(fn (?\App\Models\Team $record) => (bool) $record?->padrao)
                ->helperText('Equipe desativada não aparece para transferência nem no filtro do atendimento.'),
        ]);
    }
}
