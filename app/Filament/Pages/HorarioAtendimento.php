<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class HorarioAtendimento extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationParentItem = 'Conta';

    protected static ?string $navigationLabel = 'Horário de Atendimento';

    protected static ?string $title = 'Horário de Atendimento';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'horario-atendimento';

    protected string $view = 'filament.pages.horario-atendimento';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }
}
