<?php

namespace App\Filament\Resources\ContactFields\Pages;

use App\Filament\Resources\ContactFields\ContactFieldResource;
use Filament\Resources\Pages\EditRecord;

class EditContactField extends EditRecord
{
    protected static string $resource = ContactFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
