<?php

namespace App\Livewire\Inbox;

use App\Models\Conversation;
use Livewire\Component;

class ConversationWindow extends Component
{
    public ?int $conversationId = null;

    public int $limite = 30;

    public function mount(?int $conversationId = null): void
    {
        $this->conversationId = $conversationId;
    }

    public function abrir(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->limite = 30;
    }

    public function getListeners(): array
    {
        $listeners = ['abrir-conversa' => 'abrir'];

        if ($this->conversationId) {
            $listeners['echo-private:conversation.'.$this->conversationId.',.message.stored'] = '$refresh';
        }

        return $listeners;
    }

    public function carregarMais(): void
    {
        $this->limite += 30;
    }

    public function render()
    {
        $conversa = $this->conversationId
            ? Conversation::with('contact')->find($this->conversationId)
            : null;

        $mensagens = $conversa
            ? $conversa->messages()->orderByDesc('id')->limit($this->limite)->get()->reverse()->values()
            : collect();

        return view('livewire.inbox.conversation-window', compact('conversa', 'mensagens'));
    }

    public function assumir(): void
    {
        $conversa = \App\Models\Conversation::findOrFail($this->conversationId);
        $conversa->assumir();

        $this->dispatch('conversa-atualizada');
    }

    public function finalizar(): void
    {
        $conversa = \App\Models\Conversation::findOrFail($this->conversationId);
        $conversa->arquivar();

        $this->dispatch('conversa-atualizada');
    }

    public function reabrir(): void
    {
        $conversa = \App\Models\Conversation::findOrFail($this->conversationId);

        if (! $conversa->reabrir()) {
            $this->addError('reabrir', 'Ja existe uma conversa aberta com este contato. Use aquela.');

            return;
        }

        $this->dispatch('conversa-atualizada');
    }

    public function verDetalhes(): void
    {
        $this->dispatch('abrir-detalhes');
    }
}
