<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Paineis extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = 'CRM';

    protected static ?string $navigationLabel = 'Painéis';

    protected static ?string $title = 'Painéis';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'paineis';

    protected string $view = 'filament.pages.paineis';
}
