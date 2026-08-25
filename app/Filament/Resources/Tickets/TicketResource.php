<?php

namespace App\Filament\Resources\Tickets;

use App\Filament\Resources\Tickets\Pages\CreateTicket;
use App\Filament\Resources\Tickets\Pages\EditTicket;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Models\ClientAccount;
use App\Models\ClientProject;
use App\Models\ClientRequester;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'CRM';

    protected static ?string $navigationLabel = 'Demandas';

    protected static ?string $modelLabel = 'demanda';

    protected static ?string $pluralModelLabel = 'demandas';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'protocol';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Demanda')->schema([
                TextInput::make('protocol')->label('Protocolo')->disabled()->dehydrated(false),
                TextInput::make('title')->label('Título')->required()->maxLength(180)->columnSpan(2),
                Select::make('client_account_id')
                    ->label('Cliente')
                    ->options(fn () => ClientAccount::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->required()->live(),
                Select::make('client_project_id')
                    ->label('Projeto')
                    ->options(fn (Get $get) => ClientProject::query()
                        ->where('client_account_id', $get('client_account_id') ?: 0)
                        ->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Select::make('requester_id')
                    ->label('Solicitante')
                    ->options(fn (Get $get) => ClientRequester::query()
                        ->where('client_account_id', $get('client_account_id') ?: 0)
                        ->where('active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Select::make('category')->label('Categoria')->options(Ticket::CATEGORIES)->required(),
                Select::make('status')->label('Status')->options(Ticket::STATUSES)->required(),
                Select::make('priority')->label('Prioridade')->options(Ticket::PRIORITIES)->required(),
                Select::make('responsible_id')->label('Responsável')
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                Select::make('supervisor_id')->label('Supervisor')
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                Textarea::make('description')->label('Descrição')->required()->rows(5)->columnSpanFull(),
                Textarea::make('cancellation_reason')->label('Motivo do cancelamento')->rows(2)
                    ->visible(fn (Get $get) => $get('status') === Ticket::CANCELLED)
                    ->required(fn (Get $get) => $get('status') === Ticket::CANCELLED)
                    ->columnSpanFull(),
            ])->columns(3),

            Section::make('Prazos')->schema([
                DateTimePicker::make('first_response_due_at')->label('Primeira resposta'),
                DateTimePicker::make('resolution_due_at')->label('Solução / entrega'),
                DateTimePicker::make('next_update_at')->label('Próxima atualização'),
                Toggle::make('deadlines_paused')->label('Prazos pausados')
                    ->afterStateHydrated(fn (Toggle $component, ?Ticket $record) => $component->state((bool) $record?->deadlines_paused_at)),
                Textarea::make('deadline_change_reason')->label('Justificativa de prazo')->rows(2)->columnSpan(2),
            ])->columns(3),

            Section::make('Etiquetas')->schema([
                Select::make('tags')
                    ->relationship('tags', 'nome', modifyQueryUsing: fn ($query) => $query->where('contexto', Tag::TICKET))
                    ->multiple()->preload()->searchable()->label('Etiquetas da demanda'),
            ]),

            Section::make('Atualizações')
                ->description('Interna é o padrão. Parceiro e pública serão exibidas nos portais nas próximas fases.')
                ->schema([
                    Repeater::make('messages')
                        ->relationship()
                        ->defaultItems(0)
                        ->hiddenLabel()
                        ->schema([
                            Hidden::make('author_id')->default(fn () => auth()->id()),
                            Select::make('visibility')->label('Visibilidade')->options(TicketMessage::VISIBILITIES)
                                ->default('internal')->required(),
                            Textarea::make('body')->label('Atualização')->required()->rows(4)->columnSpanFull(),
                            FileUpload::make('attachments')->label('Anexos')->multiple()->disk('local')
                                ->directory('tickets')->visibility('private')->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->collapsed()
                        ->itemLabel(fn (array $state): ?string => isset($state['body']) ? mb_strimwidth($state['body'], 0, 70, '…') : null)
                        ->addActionLabel('Adicionar atualização'),
                ]),

            Section::make('Tarefas internas')->schema([
                Repeater::make('tasks')
                    ->relationship()
                    ->defaultItems(0)
                    ->hiddenLabel()
                    ->schema([
                        Hidden::make('created_by')->default(fn () => auth()->id()),
                        TextInput::make('title')->label('Tarefa')->required()->maxLength(180)->columnSpan(2),
                        Select::make('responsible_id')->label('Responsável')
                            ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                        Select::make('status')->label('Situação')->options([
                            'pending' => 'Pendente', 'in_progress' => 'Em execução', 'completed' => 'Concluída',
                        ])->default('pending')->required(),
                        Select::make('priority')->label('Prioridade')->options(Ticket::PRIORITIES)->default('normal')->required(),
                        DateTimePicker::make('due_at')->label('Prazo'),
                        Textarea::make('description')->label('Descrição')->rows(2)->columnSpanFull(),
                        Repeater::make('checklist')
                            ->relationship()
                            ->defaultItems(0)
                            ->label('Checklist')
                            ->schema([
                                Toggle::make('done')->label('Feito'),
                                TextInput::make('text')->label('Item')->required()->maxLength(240),
                                Hidden::make('position')->default(0),
                            ])->columns(2)->columnSpanFull()->addActionLabel('Adicionar item'),
                    ])
                    ->columns(3)
                    ->collapsed()
                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                    ->addActionLabel('Adicionar tarefa'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->visible()->with(['client', 'responsible']))
            ->columns([
                TextColumn::make('protocol')->label('Ticket')->searchable()->sortable(),
                TextColumn::make('title')->label('Demanda')->searchable()->wrap()->limit(70),
                TextColumn::make('client.name')->label('Cliente')->searchable()->sortable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => Ticket::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        Ticket::COMPLETED => 'success', Ticket::CANCELLED => 'danger',
                        Ticket::IN_PROGRESS => 'info', Ticket::PENDING_APPROVAL, Ticket::PENDING_VALIDATION => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('priority')->label('Prioridade')->badge()
                    ->formatStateUsing(fn ($state) => Ticket::PRIORITIES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'critical' => 'danger', 'high' => 'warning', 'low' => 'gray', default => 'info',
                    }),
                TextColumn::make('responsible.name')->label('Responsável')->placeholder('Não atribuído'),
                TextColumn::make('resolution_due_at')->label('Prazo')->dateTime('d/m/Y H:i')->sortable()->placeholder('—'),
                TextColumn::make('created_at')->label('Recebida em')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(Ticket::STATUSES),
                SelectFilter::make('category')->label('Categoria')->options(Ticket::CATEGORIES),
                SelectFilter::make('priority')->label('Prioridade')->options(Ticket::PRIORITIES),
                SelectFilter::make('client_account_id')->label('Cliente')->relationship('client', 'name')->searchable()->preload(),
                SelectFilter::make('responsible_id')->label('Responsável')->relationship('responsible', 'name')->searchable()->preload(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([EditAction::make()->label('Abrir')])
            ->emptyStateHeading('Nenhuma demanda recebida')
            ->emptyStateDescription('Abra uma demanda interna ou aguarde uma solicitação pelo portal.');
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'create' => CreateTicket::route('/create'),
            'edit' => EditTicket::route('/{record}/edit'),
        ];
    }
}
