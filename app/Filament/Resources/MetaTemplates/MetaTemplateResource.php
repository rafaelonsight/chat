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

/**
 * Somente leitura, de proposito.
 *
 * Template nao se cria aqui: quem cria e aprova e a Meta, no painel dela, com analise que
 * leva horas ou dias. Um formulario de criacao nesta tela daria a impressao de que salvar
 * basta — e o atendente sairia daqui achando que pode usar algo que a Meta nem viu.
 *
 * O que esta tela faz e mostrar a verdade da Meta e dizer, para cada template, se da para
 * enviar e por que nao.
 */
class MetaTemplateResource extends Resource
{
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
        return false;
    }

    public static function table(Table $table): Table
    {
        return MetaTemplatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMetaTemplates::route('/'),
        ];
    }
}
