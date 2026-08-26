<?php

namespace App\Filament\Pages;

use App\Models\Ticket;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class CentralDemandas extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return false;
    }
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;
    protected static string|UnitEnum|null $navigationGroup = 'CRM';
    protected static ?string $navigationLabel = 'Central de demandas';
    protected static ?string $title = 'Central de demandas';
    protected static ?int $navigationSort = 2;
    protected string $view = 'filament.pages.central-demandas';
    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getViewData(): array
    {
        $tickets = Ticket::visible()->with(['client', 'assignees', 'tags'])->orderBy('resolution_due_at')->latest()->get()->groupBy('status');
        return ['columns' => collect(Ticket::STATUSES)->map(fn ($label, $status) => ['status' => $status, 'label' => $label, 'tickets' => $tickets->get($status, collect())])];
    }
}
