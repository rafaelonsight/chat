<?php

namespace App\Filament\Resources\MessageTemplates\Schemas;

use App\Models\MessageTemplate;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class MessageTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        $marcadores = collect(MessageTemplate::MARCADORES)
            ->map(fn ($desc, $tag) => $tag.' = '.$desc)
            ->implode(' · ');

        return $schema->components([
            TextInput::make('titulo')
                ->label('Título')
                ->required()
                ->maxLength(120)
                ->helperText('Como o atendente reconhece o modelo na lista.'),

            TextInput::make('atalho')
                ->label('Atalho')
                ->required()
                ->maxLength(40)
                ->unique(ignoreRecord: true)
                ->helperText('Palavra curta para achar rápido. Ex.: boleto, senha, agendamento.')
                ->dehydrateStateUsing(fn ($state) => mb_strtolower(trim((string) $state))),

            Textarea::make('corpo')
                ->label('Mensagem')
                ->required()
                ->rows(6)
                ->maxLength(4000)
                ->helperText('Marcadores disponíveis: '.$marcadores)
                ->columnSpanFull(),

            Toggle::make('ativo')
                ->label('Ativo')
                ->default(true)
                ->helperText('Desativado continua salvo, mas não aparece para o atendente.'),
        ]);
    }
}
