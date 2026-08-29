<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EncerrarChatDoSitePorInatividade implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $conversationId, public int $visitorMessageId) {}

    public function handle(): void
    {
        $conversa = Conversation::withoutGlobalScope('tenant')->find($this->conversationId);

        if (! $conversa) return;

        TenantContext::runAs($conversa->tenant_id, function () use ($conversa) {
            $conversa->refresh();

            if ($conversa->status === Conversation::ARQUIVADA) return;

            $ultimaEntrada = $conversa->messages()->where('direcao', 'in')->latest('id')->first();
            if (! $ultimaEntrada || $ultimaEntrada->id !== $this->visitorMessageId) return;

            $mensagem = Message::firstOrCreate(
                ['external_id' => 'site_inatividade_'.$conversa->id.'_'.$this->visitorMessageId],
                [
                    'tenant_id' => $conversa->tenant_id,
                    'conversation_id' => $conversa->id,
                    'channel_id' => $conversa->channel_id,
                    'direcao' => 'out',
                    'tipo' => 'text',
                    'corpo' => 'Encerramos este atendimento por inatividade. Quando precisar, é só iniciar uma nova conversa por aqui.',
                    'status' => Message::STATUS_SENT,
                    'enviada_em' => now(),
                ],
            );

            $conversa->forceFill(['status' => Conversation::ARQUIVADA, 'ultima_msg_em' => now()])->save();
            broadcast(new MessageStored($mensagem->refresh()));
        });
    }
}
