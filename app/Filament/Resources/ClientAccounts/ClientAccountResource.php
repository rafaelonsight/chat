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
                TextInput::make('name')->label('Nome')->required()->maxLength(160),
                Select::make('type')
                    ->label('Tipo')
                    ->options(ClientAccount::TYPES)
                    ->required()
                    ->live(),
                Select::make('parent_id')
                    ->label('Parceiro responsável')
                    ->options(fn () => ClientAccount::query()
                        ->where('type', ClientAccount::PARTNER)
                        ->whereNull('archived_at')
                        ->orderBy('name')
                        ->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible(fn (Get $get): bool => $get('type') === ClientAccount::END_CLIENT)
                    ->required(fn (Get $get): bool => $get('type') === ClientAccount::END_CLIENT),
                Select::make('contact_id')
                    ->label('Contato do CRM')
                    ->options(fn () => Contact::query()->where('tipo', Contact::PESSOA)->ativos()
                        ->orderBy('nome')->limit(300)->get()
                        ->mapWithKeys(fn (Contact $contact) => [$contact->id => $contact->nomeExibicao()])->all())
                    ->searchable(),
                TextInput::make('email')->label('E-mail principal')->email()->maxLength(160),
                TextInput::make('phone')->label('Telefone')->tel()->maxLength(30),
                Textarea::make('notes')->label('Observações internas')->rows(3)->columnSpanFull(),
            ])->columns(2),

            Section::make('Solicitantes')
                ->description('Pessoas que podem ser identificadas ao abrir ou responder uma demanda.')
                ->schema([
                    Repeater::make('requesters')
                        ->relationship()
                        ->defaultItems(0)
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('name')->label('Nome')->required()->maxLength(160),
                            TextInput::make('email')->label('E-mail')->email()->maxLength(160),
                            TextInput::make('phone')->label('Telefone')->tel()->maxLength(30),
                            Toggle::make('is_admin')->label('Responsável administrativo'),
                            Toggle::make('active')->label('Ativo')->default(true),
                        ])
                        ->columns(3)
                        ->addActionLabel('Adicionar solicitante'),
                ]),

            Section::make('Projetos')
                ->description('Implantação, onboarding, treinamento ou outro trabalho estruturado.')
                ->schema([
                    Repeater::make('projects')
                        ->relationship()
                        ->defaultItems(0)
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('name')->label('Projeto')->required()->maxLength(160),
                            Select::make('status')->label('Situação')->options([
                                'active' => 'Ativo', 'paused' => 'Pausado', 'completed' => 'Concluído',
                            ])->default('active')->required(),
                            DatePicker::make('starts_on')->label('Início'),
                            DatePicker::make('ends_on')->label('Fim'),
                            Textarea::make('description')->label('Descrição')->rows(2)->columnSpanFull(),
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
                TextColumn::make('name')->label('Cliente')->searchable()->sortable(),
                TextColumn::make('type')->label('Tipo')->badge()->formatStateUsing(fn ($state) => ClientAccount::TYPES[$state] ?? $state),
                TextColumn::make('parent.name')->label('Parceiro')->placeholder('—')->searchable(),
                TextColumn::make('tickets_count')->counts('tickets')->label('Tickets')->badge(),
                TextColumn::make('email')->label('E-mail')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Tipo')->options(ClientAccount::TYPES),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('Nenhum cliente cadastrado')
            ->emptyStateDescription('Cadastre clientes diretos, parceiros e clientes finais para abrir demandas.');
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
