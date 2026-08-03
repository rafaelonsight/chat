<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Campanhas extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Aplicações';

    protected static ?string $navigationLabel = 'Campanhas';

    protected static ?string $title = 'Campanhas';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'campanhas';

    protected string $view = 'filament.pages.campanhas';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }
}
