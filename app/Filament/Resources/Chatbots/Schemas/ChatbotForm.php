<?php

namespace App\Filament\Resources\Chatbots\Schemas;

use App\Models\Channel;
use App\Services\ChatbotEngine;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ChatbotForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificação')
                ->schema([
                    TextInput::make('nome')
                        ->label('Nome do fluxo')
                        ->required()
                        ->maxLength(60)
                        ->helperText('Só para você se organizar. O cliente não vê.'),

                    Select::make('channel_id')
                        ->label('Canal')
                        ->options(fn () => Channel::pluck('nome', 'id')->all())
                        ->placeholder('Todos os canais')
                        ->helperText('Deixe vazio para valer em todos. Um fluxo de canal específico tem prioridade sobre o geral.'),

                    Toggle::make('ativo')
                        ->label('Ativo')
                        ->helperText('Só um fluxo pode estar ativo por canal. Ativar este desliga o outro automaticamente. Enquanto nenhum está ativo, o atendimento funciona exatamente como hoje.'),
                ])
                ->columns(2),

            Section::make('Mensagens')
                ->schema([
                    Textarea::make('mensagem_boas_vindas')
                        ->label('Primeira mensagem')
                        ->required()
                        ->rows(3)
                        ->helperText('As opções do menu são acrescentadas automaticamente abaixo deste texto, na mesma mensagem.'),

                    Textarea::make('mensagem_nao_entendi')
                        ->label('Quando não entender')
                        ->required()
                        ->rows(2),

                    Textarea::make('mensagem_transferindo')
                        ->label('Ao encaminhar para uma pessoa')
                        ->rows(2)
                        ->placeholder('Um momento, já vou te encaminhar para um atendente.'),

                    Textarea::make('mensagem_fora_horario')
                        ->label('Fora do horário de atendimento')
                        ->rows(2)
                        ->helperText('Se preencher, fora do horário o bot manda só isto e encerra — sem mostrar o menu. Se deixar vazio, o bot atende 24h normalmente.'),
                ]),

            Section::make('Comportamento')
                ->schema([
                    TextInput::make('max_tentativas')
                        ->label('Tentativas antes de chamar uma pessoa')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(5)
                        ->default(2)
                        ->required()
                        ->helperText('Prender o cliente num robô que não entende é o pior resultado possível.'),

                    TextInput::make('palavra_escape')
                        ->label('Palavra para falar com uma pessoa')
                        ->required()
                        ->maxLength(30)
                        ->default('atendente')
                        ->helperText('Funciona a qualquer momento, em qualquer nível do menu.'),
                ])
                ->columns(2),

            Section::make('Prévia')
                ->schema([
                    Placeholder::make('previa')
                        ->label('Exatamente o que o cliente recebe')
                        ->content(fn ($record) => $record
                            ? new HtmlString(
                                '<div style="white-space:pre-wrap;font-size:0.875rem;line-height:1.5">'
                                .e(app(ChatbotEngine::class)->previa($record))
                                .'</div>'
                            )
                            : '—'),
                ])
                ->visible(fn ($record) => $record !== null)
                ->description('Salve para atualizar a prévia depois de mexer nas opções.'),
        ]);
    }
}
