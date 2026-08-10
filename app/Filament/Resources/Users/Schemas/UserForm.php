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
                ->helperText('Administrador enxerga e altera Configuracoes (usuarios e canais) — e ve TODOS os canais e times, sem restricao.'),

            /*
             * O ACESSO. Vazio quer dizer "sem restricao", e nao "sem permissao" — e o texto de
             * ajuda diz isso, porque um campo vazio que significa "tudo" e exatamente o tipo de
             * regra que se descobre errando.
             */
            Select::make('canais')
                ->label('Canais que pode atender')
                ->relationship('canais', 'nome')
                ->multiple()
                ->preload()
                ->searchable()
                ->helperText('Deixe vazio para liberar todos. Marcando algum, a pessoa passa a ver so as conversas desses canais.'),

            Select::make('teams')
                ->label('Times')
                ->relationship('teams', 'nome')
                ->multiple()
                ->preload()
                ->searchable()
                ->helperText('Deixe vazio para liberar tudo. Marcando algum, a pessoa ve so as conversas direcionadas a esses times — e NAO ve as que ainda estao sem time. Quem precisa pegar a fila de entrada tem de estar no time Triagem.'),
        ]);
    }
}
