<?php

namespace App\Filament\Resources\ContactFields\Pages;

use App\Filament\Resources\ContactFields\ContactFieldResource;
use Filament\Resources\Pages\ListRecords;

class ListContactFields extends ListRecords
{
    protected static string $resource = ContactFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()->label("Novo campo")];
    }
}
