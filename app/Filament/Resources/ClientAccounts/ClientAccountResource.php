<?php

namespace App\Filament\Resources\ClientAccounts;

use App\Filament\Resources\ClientAccounts\Pages\CreateClientAccount;
use App\Filament\Resources\ClientAccounts\Pages\EditClientAccount;
use App\Filament\Resources\ClientAccounts\Pages\ListClientAccounts;
use App\Models\ClientAccount;
use App\Models\Contact;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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

class ClientAccountResource extends Resource
{
    use \App\Filament\TraduzirResource;

    protected static bool $shouldRegisterNavigation = false;

    public static function canViewAny(): bool
    {
        return false;
    }
    protected static ?string $model = ClientAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'CRM';

    protected static ?string $navigationLabel = 'Clientes';

    protected static ?string $modelLabel = 'cliente';

    protected static ?string $pluralModelLabel = 'clientes';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cliente')->schema([
                TextInput::make('name')->label(__('ui_13030dd962f4'))->required()->maxLength(160),
                Select::make('type')
                    ->label(__('ui_6cc6198d9e75'))
                    ->options(fn () => \App\Support\Idiomas::opcoes(ClientAccount::TYPES))
                    ->required()
                    ->live(),
                Select::make('parent_id')
                    ->label(__('ui_bcd04c3f0ac7'))
                    ->options(fn () => ClientAccount::query()
                        ->where('type', ClientAccount::PARTNER)
                        ->whereNull('archived_at')
                        ->orderBy('name')
                        ->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible(fn (Get $get): bool => $get('type') === ClientAccount::END_CLIENT)
                    ->required(fn (Get $get): bool => $get('type') === ClientAccount::END_CLIENT),
                Select::make('contact_id')
                    ->label(__('ui_a0d3bd112bc0'))
                    ->options(fn () => Contact::query()->ativos()
                        ->orderBy('nome')->limit(300)->get()
                        ->mapWithKeys(fn (Contact $contact) => [$contact->id => $contact->nomeExibicao()])->all())
                    ->searchable()
                    ->helperText(__('ui_1de937dabb40')),
                TextInput::make('email')->label(__('ui_eb232be8fef7'))->email()->maxLength(160),
                TextInput::make('phone')->label(__('ui_04e0410282ed'))->tel()->maxLength(30),
                Textarea::make('notes')->label(__('ui_e945de19340f'))->rows(3)->columnSpanFull(),
            ])->columns(2),

            Section::make('Portal e administradores')
                ->description(__('ui_03e5e37a327d'))
                ->schema([
                    TextInput::make('portal_url')->label(__('ui_6067209f2b29'))->disabled()->dehydrated(false)
                        ->formatStateUsing(fn (?ClientAccount $record) => $record?->portal?->url()),
                    Repeater::make('admins')->relationship()->defaultItems(0)->schema([
                        TextInput::make('name')->label(__('ui_13030dd962f4'))->required()->maxLength(160),
                        TextInput::make('email')->label(__('ui_0d01e2e86669'))->email()->required()->maxLength(160),
                        Toggle::make('is_primary')->label(__('ui_0083a99bb9ba')),
                        Toggle::make('active')->label(__('ui_8263fde6ccc4'))->default(true),
                    ])->columns(2)->addActionLabel('Adicionar administrador'),
                ]),

            Section::make('Solicitantes')
                ->description(__('ui_48001dbc9057'))
                ->schema([
                    Repeater::make('requesters')
                        ->relationship()
                        ->defaultItems(0)
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('name')->label(__('ui_13030dd962f4'))->required()->maxLength(160),
                            TextInput::make('email')->label(__('ui_0d01e2e86669'))->email()->maxLength(160),
                            TextInput::make('phone')->label(__('ui_04e0410282ed'))->tel()->maxLength(30),
                            Toggle::make('is_admin')->label(__('ui_aad34779789c')),
                            Toggle::make('active')->label(__('ui_8263fde6ccc4'))->default(true),
                        ])
                        ->columns(3)
                        ->addActionLabel('Adicionar solicitante'),
                ]),

            Section::make('Projetos')
                ->description(__('ui_8413360ae52e'))
                ->schema([
                    Repeater::make('projects')
                        ->relationship()
                        ->defaultItems(0)
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('name')->label(__('ui_a29580282264'))->required()->maxLength(160),
                            Select::make('status')->label(__('ui_2d3abf3de54d'))->options(fn () => \App\Support\Idiomas::opcoes([
                                'active' => 'Ativo', 'paused' => 'Pausado', 'completed' => 'Concluído',
                            ]))->default('active')->required(),
                            DatePicker::make('starts_on')->label(__('ui_fc2c74003cb1')),
                            DatePicker::make('ends_on')->label(__('ui_1b0369058b98')),
                            Textarea::make('description')->label(__('ui_469768598e0f'))->rows(2)->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->addActionLabel('Adicionar projeto'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('ui_c5c7d5ee3d61'))->searchable()->sortable(),
                TextColumn::make('type')->label(__('ui_6cc6198d9e75'))->badge()->formatStateUsing(fn ($state) => ClientAccount::TYPES[$state] ?? $state),
                TextColumn::make('parent.name')->label(__('ui_9e703250755e'))->placeholder('—')->searchable(),
                TextColumn::make('tickets_count')->counts('tickets')->label(__('ui_5c622a517089'))->badge(),
                TextColumn::make('email')->label(__('ui_0d01e2e86669'))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('ui_6cc6198d9e75'))->options(fn () => \App\Support\Idiomas::opcoes(ClientAccount::TYPES)),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()])
            ->emptyStateHeading(__('ui_8936a2e44c3f'))
            ->emptyStateDescription(__('ui_c8364e2ebe30'));
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClientAccounts::route('/'),
            'create' => CreateClientAccount::route('/create'),
            'edit' => EditClientAccount::route('/{record}/edit'),
        ];
    }
}
