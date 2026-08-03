<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ConsumoConversas extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationParentItem = 'Conta';

    protected static ?string $navigationLabel = 'Consumo de conversas';

    protected static ?string $title = 'Consumo de conversas';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'consumo-conversas';

    protected string $view = 'filament.pages.consumo-conversas';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }
}
