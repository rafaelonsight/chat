<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\TicketResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditTicket extends EditRecord
{
    protected static string $resource = TicketResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $paused = (bool) ($data['deadlines_paused'] ?? false);
        unset($data['deadlines_paused']);
        $data['deadlines_paused_at'] = $paused ? ($this->record->deadlines_paused_at ?? now()) : null;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('archive')
                ->label(fn () => $this->record->archived_at ? 'Reabrir arquivo' : 'Arquivar')
                ->color('gray')->requiresConfirmation()
                ->action(fn () => $this->record->update(['archived_at' => $this->record->archived_at ? null : now()])),
        ];
    }
}
