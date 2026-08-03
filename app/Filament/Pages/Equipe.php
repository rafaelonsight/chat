<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Equipe extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';



    protected static ?string $navigationLabel = 'Equipe';

    protected static ?string $title = 'Equipe';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'equipe';

    protected string $view = 'filament.pages.equipe';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }
}
