<?php

namespace App\Services;

use App\Events\MessageStored;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;

/** Mantém o encerramento do painel e do widget do site como uma única operação. */
class EncerramentoChatDoSite
{
    public function avisar(Conversation $conversa): void
    {
        if ($conversa->channel?->tipo !== Channel::SITE) {
            return;
        }

        $mensagem = Message::firstOrCreate(
            ['external_id' => 'site_encerramento_manual_'.$conversa->id],
            [
                'tenant_id' => $conversa->tenant_id,
                'conversation_id' => $conversa->id,
                'channel_id' => $conversa->channel_id,
                'direcao' => 'out',
                'tipo' => 'text',
                'corpo' => 'Este atendimento foi finalizado. Quando precisar, inicie uma nova conversa por aqui.',
                'automatica' => true,
                'status' => Message::STATUS_SENT,
                'enviada_em' => now(),
            ],
        );

        // Message::create aciona a transicao normal de mensagem de saida, que põe a conversa
        // em atendimento. Aqui o encerramento e intencional e deve vencer essa transicao.
        $conversa->forceFill([
            'status' => Conversation::ARQUIVADA,
            'ultima_msg_em' => now(),
        ])->save();
        broadcast(new MessageStored($mensagem->refresh()));
    }
}
