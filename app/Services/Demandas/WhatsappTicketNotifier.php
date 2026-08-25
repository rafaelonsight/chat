<?php

namespace App\Services\Demandas;

use App\Jobs\SendTextMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\WhatsappDelivery;

class WhatsappTicketNotifier
{
    public function send(Ticket $ticket, string $summary, string $kind = 'manual'): WhatsappDelivery
    {
        $conversation = $this->conversationFor($ticket);
        $delivery = WhatsappDelivery::create([
            'tenant_id' => $ticket->tenant_id, 'ticket_id' => $ticket->id,
            'conversation_id' => $conversation?->id, 'kind' => $kind,
            'status' => $conversation ? 'queued' : 'failed',
            'payload' => ['summary' => $summary],
            'error' => $conversation ? null : 'Cliente sem contato/conversa de WhatsApp vinculada.',
        ]);

        if (! $conversation) {
            return $delivery;
        }

        $portal = $ticket->client->portal ?: \App\Models\ClientPortal::ensureFor($ticket->client);
        $message = Message::create([
            'tenant_id' => $ticket->tenant_id, 'conversation_id' => $conversation->id,
            'channel_id' => $conversation->channel_id, 'direcao' => 'out', 'tipo' => 'text',
            'corpo' => "*{$ticket->protocol} — {$ticket->title}*\nStatus: ".(Ticket::STATUSES[$ticket->status] ?? $ticket->status)."\n\n{$summary}\n\nVer e responder: {$portal->url()}",
            'status' => Message::STATUS_QUEUED, 'automatica' => true,
        ]);
        $delivery->update(['message_id' => $message->id]);
        SendTextMessage::dispatch($message->id);

        return $delivery;
    }

    private function conversationFor(Ticket $ticket): ?Conversation
    {
        $contactId = $ticket->client->contact_id;
        if (! $contactId && $ticket->client->parent_id) {
            $contactId = $ticket->client->parent?->contact_id;
        }

        return $contactId ? Conversation::withoutGlobalScopes()->where('tenant_id', $ticket->tenant_id)
            ->where('contact_id', $contactId)->latest('ultima_msg_em')->first() : null;
    }
}
