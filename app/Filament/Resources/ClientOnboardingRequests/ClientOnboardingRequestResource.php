<?php

namespace App\Filament\Resources\ClientOnboardingRequests;

use App\Filament\Resources\ClientOnboardingRequests\Pages\EditClientOnboardingRequest;
use App\Filament\Resources\ClientOnboardingRequests\Pages\ListClientOnboardingRequests;
use App\Models\ClientAccount;
use App\Models\ClientOnboardingRequest;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ClientOnboardingRequestResource extends Resource
{
    use \App\Filament\Concerns\PertenceAoModulo;

    protected static string $modulo = 'demandas';

    use \App\Filament\TraduzirResource;

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $model = ClientOnboardingRequest::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;
    protected static string|UnitEnum|null $navigationGroup = 'CRM';
    protected static ?string $navigationLabel = 'Novos cadastros';
    protected static ?string $modelLabel = 'solicitação de cadastro';
    protected static ?string $pluralModelLabel = 'solicitações de cadastro';
    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Análise')->schema([
                Select::make('company_type')->label(__('ui_d197d5671878'))->options(fn () => \App\Support\Idiomas::opcoes([ClientAccount::DIRECT => 'Cliente direto', ClientAccount::PARTNER => 'Parceiro']))->required()->live(),
                TextInput::make('status')->label(__('ui_2d3abf3de54d'))->formatStateUsing(fn ($state) => ClientOnboardingRequest::STATUSES[$state] ?? $state)->disabled()->dehydrated(false),
                TextInput::make('public_url')->label(__('ui_7258b492fc15'))->formatStateUsing(fn (?ClientOnboardingRequest $record) => $record?->url())->disabled()->dehydrated(false)->columnSpanFull(),
            ])->columns(2),
            Section::make('Empresa')->statePath('payload.company')->schema(self::companyFields())->columns(2),
            Section::make('Administradores')->schema([
                Repeater::make('payload.admins')->hiddenLabel()->schema([
                    TextInput::make('name')->label(__('ui_13030dd962f4'))->required(), TextInput::make('email')->label(__('ui_0d01e2e86669'))->email()->required(),
                ])->columns(2)->minItems(1)->maxItems(fn (Get $get) => $get('company_type') === ClientAccount::PARTNER ? 3 : 1),
            ]),
            Section::make('Clientes finais do parceiro')->visible(fn (Get $get) => $get('company_type') === ClientAccount::PARTNER)->schema([
                Repeater::make('payload.clients')->hiddenLabel()->schema([
                    ...self::companyFields(false),
                    Section::make('Acesso principal')->statePath('admin')->schema([
                        TextInput::make('name')->label(__('ui_13030dd962f4'))->required(), TextInput::make('email')->label(__('ui_0d01e2e86669'))->email()->required(),
                    ])->columns(2)->columnSpanFull(),
                ])->columns(2)->addActionLabel('Adicionar cliente final'),
            ]),
        ]);
    }

    private static function companyFields(bool $withAddress = true): array
    {
        $fields = [
            TextInput::make('document')->label(__('ui_a2d95835ad26'))->required(), TextInput::make('legal_name')->label(__('ui_e20fa88c92d0'))->required(),
            TextInput::make('trade_name')->label(__('ui_92d22e2084db')), TextInput::make('email')->label(__('ui_0d01e2e86669'))->email()->required(),
            TextInput::make('phone')->label(__('ui_04e0410282ed')),
        ];
        if ($withAddress) array_push($fields, TextInput::make('cep')->label(__('ui_2cbe9f1f9067'))->required(), TextInput::make('street')->label(__('ui_6f26397a6564'))->required(), TextInput::make('number')->label(__('ui_1d91f4b8d8d6'))->required(), TextInput::make('complement')->label(__('ui_ba75e2e6719b')), TextInput::make('district')->label(__('ui_49f7179a65c6'))->required(), TextInput::make('city')->label(__('ui_1a3285047365'))->required(), TextInput::make('state')->label(__('ui_e81f7a385039'))->required()->maxLength(2));
        return $fields;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('payload.company.trade_name')->label(__('ui_6eb94b7d136e'))->default(fn ($record) => data_get($record->payload, 'company.legal_name', 'Link ainda não preenchido'))->searchable(),
            TextColumn::make('company_type')->label(__('ui_d197d5671878'))->badge()->formatStateUsing(fn ($state) => $state === ClientAccount::PARTNER ? 'Parceiro' : 'Cliente direto'),
            TextColumn::make('status')->label(__('ui_2d3abf3de54d'))->badge()->formatStateUsing(fn ($state) => ClientOnboardingRequest::STATUSES[$state] ?? $state)->color(fn ($state) => match($state){'submitted'=>'warning','approved'=>'success','rejected'=>'danger',default=>'gray'}),
            TextColumn::make('submitted_at')->label(__('ui_adafc74e3843'))->dateTime('d/m/Y H:i')->placeholder('—')->sortable(),
        ])->filters([SelectFilter::make('status')->options(fn () => \App\Support\Idiomas::opcoes(ClientOnboardingRequest::STATUSES))])->defaultSort('created_at', 'desc')->recordUrl(fn ($record) => static::getUrl('edit', ['record' => $record]));
    }

    public static function canCreate(): bool { return false; }
    public static function canViewAny(): bool { return false; }
    public static function canView($record): bool { return static::canViewAny() && auth()->user()->tenant_id === $record->tenant_id; }
    public static function canEdit($record): bool { return static::canView($record); }
    public static function canDelete($record): bool { return false; }
    public static function getPages(): array { return ['index' => ListClientOnboardingRequests::route('/'), 'edit' => EditClientOnboardingRequest::route('/{record}/edit')]; }
}
