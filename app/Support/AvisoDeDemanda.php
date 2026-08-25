<?php

namespace App\Support;

use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class AvisoDeDemanda
{
    public static function nova(Ticket $ticket): void
    {
        User::withoutGlobalScope('tenant')->where('tenant_id', $ticket->tenant_id)->get()->each(function (User $user) use ($ticket) {
            Notification::make()
                ->title('Nova demanda '.$ticket->protocol)
                ->body($ticket->client->name.' — '.$ticket->title)
                ->icon('heroicon-o-ticket')->iconColor('warning')
                ->actions([Action::make('abrir')->label('Abrir demanda')->url(TicketResource::getUrl('edit', ['record' => $ticket]))->markAsRead()])
                ->sendToDatabase($user);
        });
    }
}
