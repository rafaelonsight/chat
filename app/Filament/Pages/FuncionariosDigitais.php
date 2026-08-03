<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class FuncionariosDigitais extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Aplicações';

    protected static ?string $navigationLabel = 'Funcionários Digitais';

    protected static ?string $title = 'Funcionários Digitais';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'funcionarios-digitais';

    protected string $view = 'filament.pages.funcionarios-digitais';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }
}
