<?php

namespace App\Filament\Revenda\Resources\Licenses\Pages;

use App\Filament\Revenda\Resources\Licenses\LicenseResource;
use Filament\Resources\Pages\EditRecord;

class EditLicense extends EditRecord
{
    protected static string $resource = LicenseResource::class;

    /** Quem mudou fica registrado — para quem olhar depois não precisar perguntar. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['alterada_por'] = auth()->id();

        return $data;
    }
}
