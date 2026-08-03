<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255),

            TextInput::make('email')
                ->label('E-mail')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),

            // Na edicao fica em branco: preencher so quando for trocar a senha.
            TextInput::make('password')
                ->label('Senha')
                ->password()
                ->revealable()
                ->minLength(8)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->helperText(fn (string $operation) => $operation === 'edit'
                    ? 'Deixe em branco para manter a senha atual.'
                    : 'Minimo de 8 caracteres.'),

            Toggle::make('admin')
                ->label('Administrador')
                ->helperText('Administrador enxerga e altera Configuracoes (usuarios e canais).'),
        ]);
    }
}
