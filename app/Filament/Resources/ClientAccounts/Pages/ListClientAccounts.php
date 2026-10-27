<?php

namespace App\Filament\Resources\ClientAccounts\Pages;

use App\Filament\Resources\ClientAccounts\ClientAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClientAccounts extends ListRecords
{
    protected static string $resource = ClientAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('ui_a0cf533c2cba'))];
    }
}
