<?php

namespace App\Livewire\Inbox;

use App\Models\Conversation;
use Livewire\Component;

// Separado da janela de proposito: mensagem em conversa fechada so precisa
// atualizar esta lista. Um componente unico re-renderizaria a tela toda a
// cada mensagem que chega.
class ConversationList extends Component
{
    public ?int $selecionada = null;

    public function getListeners(): array
    {
        $tenantId = auth()->user()?->tenant_id;

        $listeners = ['abrir-conversa' => 'marcarSelecionada'];

        if ($tenantId) {
            $listeners['echo-private:tenant.'.$tenantId.'.conversations,.message.stored'] = '$refresh';
        }

        return $listeners;
    }

    public function selecionar(int $id): void
    {
        // findOrFail sob o escopo global: conversa de outro tenant nao existe aqui
        $conversa = Conversation::findOrFail($id);

        $this->selecionada = $conversa->id;
        $conversa->update(['nao_lidas' => 0]);

        $this->dispatch('abrir-conversa', conversationId: $conversa->id);
    }

    public function render()
    {
        return view('livewire.inbox.conversation-list', [
            'conversas' => Conversation::with(['contact', 'ultimaMensagem'])
                ->orderByDesc('ultima_msg_em')
                ->limit(50)
                ->get(),
        ]);
    }

    // Conversa aberta por outro componente (o botao Nova conversa) tambem
    // precisa aparecer e ficar destacada aqui. Sem este listener a conversa
    // nova so surgiria no proximo refresh da pagina.
    public function marcarSelecionada(int $conversationId): void
    {
        $this->selecionada = $conversationId;
    }
}
