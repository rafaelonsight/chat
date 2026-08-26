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
                Select::make('company_type')->label('Perfil')->options([ClientAccount::DIRECT => 'Cliente direto', ClientAccount::PARTNER => 'Parceiro'])->required()->live(),
                TextInput::make('status')->label('Situação')->formatStateUsing(fn ($state) => ClientOnboardingRequest::STATUSES[$state] ?? $state)->disabled()->dehydrated(false),
                TextInput::make('public_url')->label('Link enviado ao cliente')->formatStateUsing(fn (?ClientOnboardingRequest $record) => $record?->url())->disabled()->dehydrated(false)->columnSpanFull(),
            ])->columns(2),
            Section::make('Empresa')->statePath('payload.company')->schema(self::companyFields())->columns(2),
            Section::make('Administradores')->schema([
                Repeater::make('payload.admins')->hiddenLabel()->schema([
                    TextInput::make('name')->label('Nome')->required(), TextInput::make('email')->label('E-mail')->email()->required(),
                ])->columns(2)->minItems(1)->maxItems(fn (Get $get) => $get('company_type') === ClientAccount::PARTNER ? 3 : 1),
            ]),
            Section::make('Clientes finais do parceiro')->visible(fn (Get $get) => $get('company_type') === ClientAccount::PARTNER)->schema([
                Repeater::make('payload.clients')->hiddenLabel()->schema([
                    ...self::companyFields(false),
                    Section::make('Acesso principal')->statePath('admin')->schema([
                        TextInput::make('name')->label('Nome')->required(), TextInput::make('email')->label('E-mail')->email()->required(),
                    ])->columns(2)->columnSpanFull(),
                ])->columns(2)->addActionLabel('Adicionar cliente final'),
            ]),
        ]);
    }

    private static function companyFields(bool $withAddress = true): array
    {
        $fields = [
            TextInput::make('document')->label('CNPJ')->required(), TextInput::make('legal_name')->label('Razão social')->required(),
            TextInput::make('trade_name')->label('Nome fantasia'), TextInput::make('email')->label('E-mail')->email()->required(),
            TextInput::make('phone')->label('Telefone'),
        ];
        if ($withAddress) array_push($fields, TextInput::make('cep')->label('CEP')->required(), TextInput::make('street')->label('Logradouro')->required(), TextInput::make('number')->label('Número')->required(), TextInput::make('complement')->label('Complemento'), TextInput::make('district')->label('Bairro')->required(), TextInput::make('city')->label('Cidade')->required(), TextInput::make('state')->label('UF')->required()->maxLength(2));
        return $fields;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('payload.company.trade_name')->label('Empresa')->default(fn ($record) => data_get($record->payload, 'company.legal_name', 'Link ainda não preenchido'))->searchable(),
            TextColumn::make('company_type')->label('Perfil')->badge()->formatStateUsing(fn ($state) => $state === ClientAccount::PARTNER ? 'Parceiro' : 'Cliente direto'),
            TextColumn::make('status')->label('Situação')->badge()->formatStateUsing(fn ($state) => ClientOnboardingRequest::STATUSES[$state] ?? $state)->color(fn ($state) => match($state){'submitted'=>'warning','approved'=>'success','rejected'=>'danger',default=>'gray'}),
            TextColumn::make('submitted_at')->label('Enviado em')->dateTime('d/m/Y H:i')->placeholder('—')->sortable(),
        ])->filters([SelectFilter::make('status')->options(ClientOnboardingRequest::STATUSES)])->defaultSort('created_at', 'desc')->recordUrl(fn ($record) => static::getUrl('edit', ['record' => $record]));
    }

    public static function canCreate(): bool { return false; }
    public static function canViewAny(): bool { return false; }
    public static function canView($record): bool { return static::canViewAny() && auth()->user()->tenant_id === $record->tenant_id; }
    public static function canEdit($record): bool { return static::canView($record); }
    public static function canDelete($record): bool { return false; }
    public static function getPages(): array { return ['index' => ListClientOnboardingRequests::route('/'), 'edit' => EditClientOnboardingRequest::route('/{record}/edit')]; }
}
