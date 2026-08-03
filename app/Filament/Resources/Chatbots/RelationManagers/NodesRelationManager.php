<?php

namespace App\Filament\Resources\Chatbots\RelationManagers;

use App\Models\ChatbotNode;
use App\Models\Team;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NodesRelationManager extends RelationManager
{
    protected static string $relationship = 'nodes';

    protected static ?string $title = 'Opções do menu';

    protected static ?string $modelLabel = 'opção';

    protected static ?string $pluralModelLabel = 'opções';

    public function form(Schema $schema): Schema
    {
        $botId = $this->getOwnerRecord()->getKey();

        return $schema->components([
            Select::make('parent_id')
                ->label('Aparece dentro de')
                ->placeholder('Menu principal')
                ->options(function ($record) use ($botId) {
                    // Só nó do tipo "menu" pode ter filhos — filho de uma resposta
                    // de texto seria inalcançável. E o próprio nó e seus
                    // descendentes ficam de fora, senão a árvore viraria um ciclo
                    // e o cliente entraria num laço infinito.
                    $proibidos = $record ? $record->idsProibidosComoPai() : [];

                    return ChatbotNode::where('chatbot_id', $botId)
                        ->where('tipo', ChatbotNode::MENU)
                        ->whereNotIn('id', $proibidos ?: [0])
                        ->get()
                        ->mapWithKeys(fn (ChatbotNode $n) => [$n->id => $n->caminho()])
                        ->all();
                })
                ->helperText('Somente opções do tipo "Abre outro menu" podem conter outras.'),

            TextInput::make('gatilho')
                ->label('O cliente digita')
                ->required()
                ->maxLength(20)
                ->rule('not_in:0')
                ->validationMessages(['not_in' => 'O 0 é reservado para "Voltar".'])
                ->helperText('Normalmente um número: 1, 2, 3. O cliente também pode digitar o rótulo por extenso.'),

            TextInput::make('rotulo')
                ->label('Rótulo')
                ->required()
                ->maxLength(60)
                ->helperText('Como aparece na lista: "1 - Suporte técnico".'),

            Select::make('tipo')
                ->label('O que acontece ao escolher')
                ->options(ChatbotNode::TIPOS)
                ->default(ChatbotNode::MENSAGEM)
                ->required()
                ->live(),

            Select::make('team_id')
                ->label('Equipe que recebe')
                ->options(fn () => Team::ativas()->orderBy('nome')->pluck('nome', 'id')->all())
                ->placeholder('Qualquer atendente')
                ->visible(fn (Get $get) => $get('tipo') === ChatbotNode::EQUIPE)
                ->helperText('A conversa vai para a fila de Novos dessa equipe, sem atendente definido.'),

            Textarea::make('mensagem')
                ->label(fn (Get $get) => match ($get('tipo')) {
                    ChatbotNode::MENU   => 'Texto do submenu',
                    ChatbotNode::EQUIPE => 'Aviso antes de encaminhar',
                    default             => 'Resposta',
                })
                ->rows(4)
                ->required(fn (Get $get) => $get('tipo') !== ChatbotNode::EQUIPE)
                ->helperText(fn (Get $get) => $get('tipo') === ChatbotNode::MENSAGEM
                    ? 'O menu atual é repetido abaixo desta resposta, na mesma mensagem.'
                    : null),

            TextInput::make('ordem')
                ->label('Ordem na lista')
                ->numeric()
                ->default(0)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // nulls first agrupa o menu principal no topo; depois cada submenu.
            ->modifyQueryUsing(fn ($query) => $query->orderByRaw('parent_id nulls first, ordem'))
            ->columns([
                TextColumn::make('gatilho')->label('Digita'),

                TextColumn::make('rotulo')
                    ->label('Opção')
                    ->state(fn (ChatbotNode $record) => str_repeat('— ', $record->profundidade()).$record->rotulo)
                    ->description(fn (ChatbotNode $record) => $record->profundidade() > 0 ? $record->caminho() : null),

                TextColumn::make('tipo')
                    ->label('Ação')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ChatbotNode::TIPOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        ChatbotNode::EQUIPE => 'success',
                        ChatbotNode::MENU   => 'info',
                        default             => 'gray',
                    }),

                TextColumn::make('team.nome')->label('Equipe')->placeholder('—'),

                TextColumn::make('mensagem')->label('Texto')->limit(40)->placeholder('—')->wrap(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('Nenhuma opção')
            ->emptyStateDescription('Sem opções, o bot manda só a primeira mensagem e nada mais acontece.');
    }
}
