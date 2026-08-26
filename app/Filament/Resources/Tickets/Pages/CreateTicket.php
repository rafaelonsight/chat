<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use Filament\Resources\Pages\CreateRecord;

class CreateTicket extends CreateRecord
{
    protected static string $resource = TicketResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = Ticket::RECEIVED;
        $data['created_by'] = auth()->id();
        $data['deadlines_paused_at'] = null;

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->updateQuietly(['responsible_id' => $this->record->assignees()->value('users.id')]);
    }
}
