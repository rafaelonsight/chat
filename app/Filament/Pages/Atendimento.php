<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

// Estende o Dashboard de proposito: assim o Atendimento passa a ser a home do
// painel. E um app de atendimento — a primeira tela e a conversa, nao widgets.
class Atendimento extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Atendimento';

    protected static ?string $title = 'Atendimento';

    protected string $view = 'filament.pages.atendimento';

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getWidgets(): array
    {
        return [];
    }
}
