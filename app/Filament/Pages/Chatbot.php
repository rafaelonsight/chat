<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Chatbot extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Aplicações';

    protected static ?string $navigationLabel = 'Chatbot';

    protected static ?string $title = 'Chatbot';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'chatbot';

    protected string $view = 'filament.pages.chatbot';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }
}
