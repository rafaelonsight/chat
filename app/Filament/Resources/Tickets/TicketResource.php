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
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Table;
use UnitEnum;

class TicketResource extends Resource
{
    use \App\Filament\Concerns\PertenceAoModulo;

    protected static string $modulo = 'demandas';

    use \App\Filament\TraduzirResource;

    protected static bool $shouldRegisterNavigation = false;

    public static function canViewAny(): bool
    {
        return false;
    }
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
                TextInput::make('protocol')->label(__('ui_dd77aceafb55'))->disabled()->dehydrated(false),
                TextInput::make('title')->label(__('ui_98a5efa60beb'))->required()->maxLength(180)->columnSpan(2),
                Select::make('client_account_id')
                    ->label(__('ui_c5c7d5ee3d61'))
                    ->options(fn () => ClientAccount::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->required()->live(),
                Select::make('client_project_id')
                    ->label(__('ui_a29580282264'))
                    ->options(fn (Get $get) => ClientProject::query()
                        ->where('client_account_id', $get('client_account_id') ?: 0)
                        ->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Select::make('requester_id')
                    ->label(__('ui_0cd165d91e95'))
                    ->options(fn (Get $get) => ClientRequester::query()
                        ->where('client_account_id', $get('client_account_id') ?: 0)
                        ->where('active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Select::make('category')->label(__('ui_5966b0ce8b7e'))->options(fn () => \App\Support\Idiomas::opcoes(Ticket::CATEGORIES))->required(),
                Select::make('status')->label(__('ui_bae7d5be7082'))->options(fn () => \App\Support\Idiomas::opcoes(Ticket::STATUSES))->required(),
                Select::make('priority')->label(__('ui_c8c122c31c0c'))->options(fn () => \App\Support\Idiomas::opcoes(Ticket::PRIORITIES))->required(),
                Select::make('assignees')->label(__('ui_86ff7fd65a84'))
                    ->relationship('assignees', 'name')
                    ->multiple()->preload()->searchable()
                    ->helperText(__('ui_c7c546e96c6e')),
                Select::make('supervisor_id')->label(__('ui_2cd4fa195ed5'))
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                Textarea::make('description')->label(__('ui_469768598e0f'))->required()->rows(5)->columnSpanFull(),
                Textarea::make('cancellation_reason')->label(__('ui_cdb385fdfd02'))->rows(2)
                    ->visible(fn (Get $get) => $get('status') === Ticket::CANCELLED)
                    ->required(fn (Get $get) => $get('status') === Ticket::CANCELLED)
                    ->columnSpanFull(),
            ])->columns(3),

            Section::make('Prazos')->schema([
                DateTimePicker::make('first_response_due_at')->label(__('ui_66ef56db79d8')),
                DateTimePicker::make('resolution_due_at')->label(__('ui_0ddc0d750da9')),
                DateTimePicker::make('next_update_at')->label(__('ui_94e868a7d830')),
                Toggle::make('deadlines_paused')->label(__('ui_724f7c0b273a'))
                    ->afterStateHydrated(fn (Toggle $component, ?Ticket $record) => $component->state((bool) $record?->deadlines_paused_at)),
                Textarea::make('deadline_change_reason')->label(__('ui_482bbe1a5e58'))->rows(2)->columnSpan(2),
            ])->columns(3),

            Section::make('Etiquetas')->schema([
                Select::make('tags')
                    ->relationship('tags', 'nome', modifyQueryUsing: fn ($query) => $query->where('contexto', Tag::TICKET))
                    ->multiple()->preload()->searchable()->label(__('ui_c05c0e2e59f9')),
            ]),

            Section::make('Atualizações')
                ->description(__('ui_a539b85ad591'))
                ->schema([
                    Repeater::make('messages')
                        ->relationship()
                        ->defaultItems(0)
                        ->hiddenLabel()
                        ->schema([
                            Hidden::make('author_id')->default(fn () => auth()->id()),
                            Select::make('visibility')->label(__('ui_040c895ae90a'))->options(fn () => \App\Support\Idiomas::opcoes(TicketMessage::VISIBILITIES))
                                ->default('internal')->required(),
                            Textarea::make('body')->label(__('ui_5716e26ce5b3'))->required()->rows(4)->columnSpanFull(),
                            FileUpload::make('attachments')->label(__('ui_207caf317e35'))->multiple()->disk('local')
                                ->directory('tickets')->visibility('private')->maxSize(10240)
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf', 'text/plain', 'application/zip'])
                                ->columnSpanFull(),
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
                        TextInput::make('title')->label(__('ui_8399050162e5'))->required()->maxLength(180)->columnSpan(2),
                        Select::make('responsible_id')->label(__('ui_b1ce0b05fa02'))
                            ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                        Select::make('status')->label(__('ui_2d3abf3de54d'))->options(fn () => \App\Support\Idiomas::opcoes([
                            'pending' => 'Pendente', 'in_progress' => 'Em execução', 'completed' => 'Concluída',
                        ]))->default('pending')->required(),
                        Select::make('priority')->label(__('ui_c8c122c31c0c'))->options(fn () => \App\Support\Idiomas::opcoes(Ticket::PRIORITIES))->default('normal')->required(),
                        DateTimePicker::make('due_at')->label(__('ui_8d5d8770a687')),
                        Textarea::make('description')->label(__('ui_469768598e0f'))->rows(2)->columnSpanFull(),
                        Repeater::make('checklist')
                            ->relationship()
                            ->defaultItems(0)
                            ->label(__('ui_61b294646988'))
                            ->schema([
                                Toggle::make('done')->label(__('ui_ff0ea4d44a70')),
                                TextInput::make('text')->label(__('ui_ecdda59aea5e'))->required()->maxLength(240),
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
            ->modifyQueryUsing(fn ($query) => $query->visible()->with(['client', 'assignees']))
            ->columns([
                TextColumn::make('protocol')->label(__('ui_a7677e84b666'))->searchable()->sortable(),
                TextColumn::make('title')->label(__('ui_ac1642c3e8ae'))->searchable()->wrap()->limit(70),
                TextColumn::make('client.name')->label(__('ui_c5c7d5ee3d61'))->searchable()->sortable(),
                TextColumn::make('status')->label(__('ui_bae7d5be7082'))->badge()
                    ->formatStateUsing(fn ($state) => Ticket::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        Ticket::COMPLETED => 'success', Ticket::CANCELLED => 'danger',
                        Ticket::IN_PROGRESS => 'info', Ticket::PENDING_APPROVAL, Ticket::PENDING_VALIDATION => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('priority')->label(__('ui_c8c122c31c0c'))->badge()
                    ->formatStateUsing(fn ($state) => Ticket::PRIORITIES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'critical' => 'danger', 'high' => 'warning', 'low' => 'gray', default => 'info',
                    }),
                TextColumn::make('assignees.name')->label(__('ui_86ff7fd65a84'))->badge()->separator(',')->placeholder(__('ui_c9ebf0e72cf7')),
                TextColumn::make('resolution_due_at')->label(__('ui_8d5d8770a687'))->dateTime('d/m/Y H:i')->sortable()->placeholder('—'),
                TextColumn::make('created_at')->label(__('ui_47fd92657c4c'))->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(fn () => \App\Support\Idiomas::opcoes(Ticket::STATUSES)),
                SelectFilter::make('category')->label(__('ui_5966b0ce8b7e'))->options(fn () => \App\Support\Idiomas::opcoes(Ticket::CATEGORIES)),
                SelectFilter::make('priority')->label(__('ui_c8c122c31c0c'))->options(fn () => \App\Support\Idiomas::opcoes(Ticket::PRIORITIES)),
                SelectFilter::make('client_account_id')->label(__('ui_c5c7d5ee3d61'))->relationship('client', 'name')->searchable()->preload(),
                SelectFilter::make('assignees')->label(__('ui_b1ce0b05fa02'))->relationship('assignees', 'name')->searchable()->preload(),
                SelectFilter::make('requester_id')->label(__('ui_0cd165d91e95'))->relationship('requester', 'name')->searchable()->preload(),
                SelectFilter::make('client_project_id')->label(__('ui_a29580282264'))->relationship('project', 'name')->searchable()->preload(),
                SelectFilter::make('tags')->label(__('ui_07287eda176e'))->relationship('tags', 'nome')->searchable()->preload(),
                Filter::make('resolution_due_at')->label(__('ui_530400647d84'))->schema([
                    DatePicker::make('from')->label(__('ui_6b98c9b52824')), DatePicker::make('until')->label(__('ui_fc4548003743')),
                ])->query(fn ($query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('resolution_due_at', '>=', $date))
                    ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('resolution_due_at', '<=', $date))),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([EditAction::make()->label(__('ui_bf763df73110'))])
            ->emptyStateHeading(__('ui_e2ebaaac3629'))
            ->emptyStateDescription(__('ui_0b865ce82566'));
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
