<?php

namespace App\Filament\Resources\ClientAccounts\Pages;

use App\Filament\Resources\ClientAccounts\ClientAccountResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditClientAccount extends EditRecord
{
    protected static string $resource = ClientAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('archive')
                ->label(fn () => $this->record->archived_at ? 'Reativar' : 'Arquivar')
                ->color('gray')
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['archived_at' => $this->record->archived_at ? null : now()]);
                }),
        ];
    }
}
