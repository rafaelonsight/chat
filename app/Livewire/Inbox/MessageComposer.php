<?php

namespace App\Livewire\Inbox;

use App\Jobs\SendTextMessage;
use App\Models\Conversation;
use App\Models\Message;
use Livewire\Component;

class MessageComposer extends Component
{
    public ?int $conversationId = null;

    public string $corpo = '';

    public function mount(?int $conversationId = null): void
    {
        $this->conversationId = $conversationId;
    }

    public function getListeners(): array
    {
        return ['abrir-conversa' => 'abrir'];
    }

    public function abrir(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->corpo = '';
    }

    public function enviar(): void
    {
        $this->validate(['corpo' => 'required|string|max:4000']);

        $conversa = Conversation::findOrFail($this->conversationId);

        // Criada como queued e ja exibida: o usuario ve o que digitou na hora,
        // sem esperar a Evolution responder.
        $mensagem = Message::create([
            'conversation_id' => $conversa->id,
            'channel_id'      => $conversa->channel_id,
            'direcao'         => 'out',
            'tipo'            => 'text',
            'corpo'           => $this->corpo,
            'status'          => Message::STATUS_QUEUED,
        ]);

        $conversa->update(['ultima_msg_em' => now()]);

        SendTextMessage::dispatch($mensagem->id);

        $this->corpo = '';
        $this->dispatch('abrir-conversa', conversationId: $conversa->id);
    }

    public function render()
    {
        return view('livewire.inbox.message-composer');
    }
}
