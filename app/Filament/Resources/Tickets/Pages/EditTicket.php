<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\TicketResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

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

    protected function afterSave(): void
    {
        $this->record->updateQuietly(['responsible_id' => $this->record->assignees()->value('users.id')]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send_update')
                ->label(__('ui_5175f26b24e2'))->icon('heroicon-o-paper-airplane')
                ->schema([Textarea::make('summary')->label(__('ui_e3bf3a7e1a0e'))->required()->rows(5)])
                ->action(function (array $data): void {
                    \App\Models\TicketMessage::create([
                        'tenant_id' => $this->record->tenant_id, 'ticket_id' => $this->record->id,
                        'author_id' => auth()->id(), 'visibility' => 'public', 'body' => $data['summary'],
                    ]);
                    $delivery = app(\App\Services\Demandas\WhatsappTicketNotifier::class)->send($this->record, $data['summary']);
                    Notification::make()->title($delivery->status === 'failed' ? 'Atualização salva; WhatsApp não enviado' : 'Atualização enviada')
                        ->body($delivery->error)->color($delivery->status === 'failed' ? 'warning' : 'success')->send();
                }),
            Action::make('archive')
                ->label(fn () => $this->record->archived_at ? 'Reabrir arquivo' : 'Arquivar')
                ->color('gray')->requiresConfirmation()
                ->action(fn () => $this->record->update(['archived_at' => $this->record->archived_at ? null : now()])),
        ];
    }
}
