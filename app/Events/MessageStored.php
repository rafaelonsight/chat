<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Dois canais: a janela da conversa aberta e a lista lateral do tenant. Sem o
// segundo, mensagem em conversa fechada nao atualizaria o contador.
class MessageStored implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->message->conversation_id),
            new PrivateChannel('tenant.'.$this->message->tenant_id.'.conversations'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.stored';
    }

    public function broadcastWith(): array
    {
        return [
            'id'              => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'direcao'         => $this->message->direcao,
            'corpo'           => $this->message->corpo,
            'status'          => $this->message->status,
        ];
    }
}
