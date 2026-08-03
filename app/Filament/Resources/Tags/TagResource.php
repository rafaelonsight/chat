<?php

namespace App\Filament\Resources\Tags;

use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Filament\Resources\Tags\Pages\EditTag;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Resources\Tags\Schemas\TagForm;
use App\Filament\Resources\Tags\Tables\TagsTable;
use App\Models\Tag;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationLabel = 'Etiquetas';

    protected static ?string $modelLabel = 'etiqueta';

    protected static ?string $pluralModelLabel = 'etiquetas';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'nome';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->admin;
    }

    public static function form(Schema $schema): Schema
    {
        return TagForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TagsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListTags::route('/'),
            'create' => CreateTag::route('/create'),
            'edit'   => EditTag::route('/{record}/edit'),
        ];
    }
}
