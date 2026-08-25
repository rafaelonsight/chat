<?php

namespace App\Services\Demandas;

use App\Jobs\SendTextMessage;
use App\Models\ClientAccount;
use App\Models\ClientPortal;
use App\Models\Message;

class SupportCommandResponder
{
    public function handle(Message $incoming): void
    {
        if (! $incoming->entrada() || $incoming->tipo !== 'text' || trim((string) $incoming->corpo) !== '#suporte') {
            return;
        }

        $conversation = $incoming->conversation;
        $client = ClientAccount::withoutGlobalScope('tenant')->where('tenant_id', $incoming->tenant_id)
            ->where('contact_id', $conversation?->contact_id)->first();

        if (! $client || ! $conversation) {
            return;
        }

        $portal = ClientPortal::ensureFor($client);
        $reply = Message::create([
            'tenant_id' => $incoming->tenant_id, 'conversation_id' => $conversation->id,
            'channel_id' => $conversation->channel_id, 'direcao' => 'out', 'tipo' => 'text',
            'corpo' => "Olá! Para abrir ou acompanhar uma demanda de suporte, acesse:\n{$portal->url()}\n\nAs respostas e arquivos ficam organizados dentro de cada ticket.",
            'status' => Message::STATUS_QUEUED, 'automatica' => true, 'responde_a_id' => $incoming->id,
        ]);
        SendTextMessage::dispatch($reply->id);
    }
}
