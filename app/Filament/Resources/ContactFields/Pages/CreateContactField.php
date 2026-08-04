<?php

namespace App\Filament\Resources\ContactFields\Pages;

use App\Filament\Resources\ContactFields\ContactFieldResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContactField extends CreateRecord
{
    protected static string $resource = ContactFieldResource::class;
}
