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
                ->label(__('ui_98a5efa60beb'))
                ->required()
                ->maxLength(120)
                ->helperText(__('ui_0287f0cc9ea7')),

            TextInput::make('atalho')
                ->label(__('ui_fd3728e73db3'))
                ->required()
                ->maxLength(40)
                ->unique(ignoreRecord: true)
                ->helperText(__('ui_e0819bf0f4cf'))
                ->dehydrateStateUsing(fn ($state) => mb_strtolower(trim((string) $state))),

            Textarea::make('corpo')
                ->label(__('ui_eaffb6306dc5'))
                ->required()
                ->rows(6)
                ->maxLength(4000)
                ->helperText(__('ui_f8cb11c6694b').$marcadores)
                ->columnSpanFull(),

            Toggle::make('ativo')
                ->label(__('ui_8263fde6ccc4'))
                ->default(true)
                ->helperText(__('ui_e83624472209')),
        ]);
    }
}
