<?php

namespace App\Filament\Resources\MetaTemplates;

use App\Filament\Resources\MetaTemplates\Pages\ListMetaTemplates;
use App\Filament\Resources\MetaTemplates\Tables\MetaTemplatesTable;
use App\Models\MetaTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Rascunhos locais e estado confirmado pela Meta. */
class MetaTemplateResource extends Resource
{
    use \App\Filament\TraduzirResource;

    protected static ?string $model = MetaTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationLabel = 'Templates da Meta';

    protected static ?string $modelLabel = 'template da Meta';

    protected static ?string $pluralModelLabel = 'templates da Meta';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'nome';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->admin;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::canViewAny() && $record->tenant_id === \App\Support\TenantContext::get() && $record->status === 'DRAFT';
    }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return static::canEdit($record); }
    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return \App\Filament\Resources\MetaTemplates\Schemas\MetaTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MetaTemplatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMetaTemplates::route('/'),
            'create' => Pages\CreateMetaTemplate::route('/create'),
            'edit' => Pages\EditMetaTemplate::route('/{record}/edit'),
        ];
    }
}
