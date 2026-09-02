<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
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
                ->required(fn (?\App\Models\User $record): bool => ! $record?->super_admin)
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
                ->helperText('Papel administrativo nao libera conversas. O acesso continua limitado aos canais selecionados abaixo.'),

            /*
             * O ACESSO e sempre inclusivo: so entra o canal selecionado. Vazio bloqueia todos.
             */
            Select::make('canais')
                ->label('Canais que pode atender')
                ->relationship('canais', 'nome')
                ->multiple()
                ->preload()
                ->searchable()
                ->required()
                ->helperText('Selecione explicitamente cada canal permitido. Sem selecao, a pessoa nao ve nenhuma conversa.'),

            Select::make('teams')
                ->label('Times')
                ->relationship('teams', 'nome')
                ->multiple()
                ->preload()
                ->searchable()
                ->helperText('Opcional: os times restringem ainda mais os canais selecionados. Quem precisa pegar a fila de entrada deve estar no time Triagem.'),
        ]);
    }
}
