<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Sequencias extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Aplicações';

    protected static ?string $navigationLabel = 'Sequências';

    protected static ?string $title = 'Sequências';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'sequencias';

    protected string $view = 'filament.pages.sequencias';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }
}
