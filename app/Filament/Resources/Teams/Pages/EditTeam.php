<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\TeamResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTeam extends EditRecord
{
    protected static string $resource = TeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Mesma razao da lista: a guarda vive no modelo, e a tela apenas nao oferece.
            DeleteAction::make()->hidden(fn () => (bool) $this->record->padrao),
        ];
    }
}
