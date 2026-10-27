<?php

namespace App\Filament\Resources\Chatbots\Schemas;

use App\Models\Channel;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ChatbotForm
{
    public static function creationFields(): array
    {
        return [
            TextInput::make('nome')->label(__('ui_5c04d57f21fd'))->required()->maxLength(60),
            Select::make('channel_ids')->label(__('ui_5f86be3deae7'))->multiple()->searchable()->preload()
                ->options(fn () => Channel::pluck('nome', 'id')->all())
                ->required()->minItems(1)
                ->helperText(__('ui_00ba5a3a7531'))
                ->hintAction(\Filament\Actions\Action::make('cadastrarCanal')->label(__('ui_c4ead6f4d9f5'))->url('/admin/channels/create')),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificação')
                ->schema([
                    TextInput::make('nome')
                        ->label(__('ui_07da7e1cf97b'))
                        ->required()
                        ->maxLength(60)
                        ->helperText(__('ui_5b56724f1d13')),

                    Select::make('channel_ids')->label(__('ui_5f86be3deae7'))->multiple()->searchable()->preload()
                        ->options(fn () => Channel::pluck('nome', 'id')->all())
                        ->required()->minItems(1)
                        ->afterStateHydrated(function ($component, $state, $record) {
                            if ($state === null && $record) $component->state($record->channel_id ? [$record->channel_id] : Channel::pluck('id')->all());
                        })
                        ->helperText(__('ui_e27ebe97c367')),
                ])
                ->columns(2),

            Section::make('Mensagens')
                ->schema([
                    Textarea::make('mensagem_boas_vindas')
                        ->label(__('ui_0b42ed594a1c'))
                        ->required()
                        ->rows(3)
                        ->helperText(__('ui_a3c25b03013a')),

                    Textarea::make('mensagem_nao_entendi')
                        ->label(__('ui_9bf0247a2a45'))
                        ->required()
                        ->rows(2),

                    Textarea::make('mensagem_transferindo')
                        ->label(__('ui_a5a4a6986459'))
                        ->rows(2)
                        ->placeholder(__('ui_a3d7c72cbb20')),

                    Textarea::make('mensagem_fora_horario')
                        ->label(__('ui_2d36e3680ad0'))
                        ->rows(2)
                        ->helperText(__('ui_c433864acc45')),
                ]),

            Section::make('Comportamento')
                ->schema([
                    TextInput::make('max_tentativas')
                        ->label(__('ui_f2ab8a233672'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(5)
                        ->default(2)
                        ->required()
                        ->helperText(__('ui_efad817106b2')),

                    TextInput::make('palavra_escape')
                        ->label(__('ui_be4831bbd554'))
                        ->maxLength(30)
                        ->default('atendente')
                        ->dehydrateStateUsing(fn ($state) => trim((string) $state) === '' ? null : trim((string) $state))
                        ->helperText(__('ui_7dcc81fabd01')),
                ])
                ->columns(2),

            Section::make('Ritmo da conversa')
                ->description(__('ui_128782664eef'))
                ->schema([
                    TextInput::make('tolerancia_segundos')
                        ->label(__('ui_fd8ded9e1ff7'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(60)
                        ->default(8)
                        ->required()
                        ->helperText(__('ui_65196eb85f8c')),

                    TextInput::make('espera_segundos')
                        ->label(__('ui_3a73e340ac92'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(86400)
                        ->default(0)
                        ->required()
                        ->helperText(__('ui_695c99bbbbe3')),

                    Select::make('espera_acao')
                        ->label(__('ui_ae393bbcc0c9'))
                        ->options(fn () => \App\Support\Idiomas::opcoes([
                            'atendente' => 'Encaminhar para um atendente',
                            'concluir'  => 'Encerrar a conversa',
                        ]))
                        ->default('atendente')
                        ->native(false)
                        ->required()
                        ->helperText(__('ui_02efcf1f1ee7')),

                    Textarea::make('mensagem_sem_resposta')
                        ->label(__('ui_8d2c394820dd'))
                        ->rows(2)
                        ->maxLength(500)
                        ->helperText(__('ui_dad1801faea2'))
                        ->columnSpanFull(),
                ])
                ->columns(3),

        ]);
    }
}
