<?php

namespace App\Filament\Revenda\Resources\Licenses;

use App\Filament\Revenda\Resources\Licenses\Pages\EditLicense;
use App\Filament\Revenda\Resources\Licenses\Pages\ListLicenses;
use App\Filament\Revenda\Resources\Licenses\Schemas\LicenseForm;
use App\Filament\Revenda\Resources\Licenses\Tables\LicensesTable;
use App\Models\License;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LicenseResource extends Resource
{
    protected static ?string $model = License::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'Licenças';

    protected static ?string $modelLabel = 'licença';

    protected static ?string $pluralModelLabel = 'licenças';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return LicenseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LicensesTable::configure($table);
    }

    /**
     * Sem rota 'create': a licença nasce sozinha quando o cliente é cadastrado (ver
     * CriarCliente), sempre em trial. Não existe caso de criar uma solta, sem tenant dono.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListLicenses::route('/'),
            'edit'  => EditLicense::route('/{record}/edit'),
        ];
    }
}
