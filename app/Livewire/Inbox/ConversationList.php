<?php

namespace App\Livewire\Inbox;

use App\Models\Conversation;
use Livewire\Component;

// Separado da janela de proposito: mensagem em conversa fechada so precisa
// atualizar esta lista. Um componente unico re-renderizaria a tela toda a
// cada mensagem que chega.
class ConversationList extends Component
{
    public string $aba = Conversation::NOVA;

    public ?int $selecionada = null;

    public function getListeners(): array
    {
        $listeners = [
            'abrir-conversa'      => 'marcarSelecionada',
            'conversa-atualizada' => '$refresh',
        ];

        if ($tenantId = auth()->user()?->tenant_id) {
            $listeners['echo-private:tenant.'.$tenantId.'.conversations,.message.stored'] = '$refresh';
        }

        return $listeners;
    }

    public function selecionarAba(string $aba): void
    {
        if (! array_key_exists($aba, Conversation::ROTULOS)) {
            return;
        }

        $this->aba = $aba;
    }

    public function selecionar(int $id): void
    {
        // findOrFail sob o escopo global: conversa de outro tenant nao existe aqui
        $conversa = Conversation::findOrFail($id);

        $this->selecionada = $conversa->id;
        $conversa->update(['nao_lidas' => 0]);

        $this->dispatch('abrir-conversa', conversationId: $conversa->id);
    }

    public function marcarSelecionada(int $conversationId): void
    {
        $this->selecionada = $conversationId;
    }

    public function render()
    {
        $brutos = Conversation::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $contadores = [];
        foreach (array_keys(Conversation::ROTULOS) as $estado) {
            $contadores[$estado] = (int) $brutos->get($estado, 0);
        }

        return view('livewire.inbox.conversation-list', [
            'conversas' => Conversation::with(['contact', 'ultimaMensagem', 'atendente'])
                ->withCount('messages')
                ->where('status', $this->aba)
                ->orderByDesc('ultima_msg_em')
                ->limit(50)
                ->get(),
            'contadores' => $contadores,
            'rotulos'    => Conversation::ROTULOS,
        ]);
    }
}
