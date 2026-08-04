<?php

namespace App\Filament\Resources\ContactFields;

use App\Filament\Resources\ContactFields\Pages\CreateContactField;
use App\Filament\Resources\ContactFields\Pages\EditContactField;
use App\Filament\Resources\ContactFields\Pages\ListContactFields;
use App\Filament\Resources\ContactFields\Schemas\ContactFieldForm;
use App\Filament\Resources\ContactFields\Tables\ContactFieldsTable;
use App\Models\ContactField;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ContactFieldResource extends Resource
{
    protected static ?string $model = ContactField::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationLabel = 'Campos personalizados';

    protected static ?string $modelLabel = 'campo personalizado';

    protected static ?string $pluralModelLabel = 'campos personalizados';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'campos-personalizados';

    protected static ?string $recordTitleAttribute = 'nome';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->admin;
    }

    public static function form(Schema $schema): Schema
    {
        return ContactFieldForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContactFieldsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListContactFields::route('/'),
            'create' => CreateContactField::route('/create'),
            'edit'   => EditContactField::route('/{record}/edit'),
        ];
    }
}
