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

        $equipes = \App\Models\Team::ativas()->orderBy('nome')->get();

        // So os eventos que caem dentro do trecho de mensagens carregado. Sem
        // esse corte, evento antigo apareceria acima do "carregar anteriores" e a
        // linha do tempo ficaria mentindo sobre a ordem.
        $desde = $mensagens->first()?->created_at;

        $eventos = $conversa
            ? $conversa->events()
                ->with('user')
                ->when($desde, fn ($q) => $q->where('created_at', '>=', $desde))
                ->get()
            : collect();

        // Mensagem e evento no mesmo fio: nota e transferencia fora do lugar
        // cronologico nao servem para entender o que aconteceu.
        // Chave composta, e nao sortBy([...]): com array de closures o Laravel
        // trata cada uma como COMPARADOR ($a, $b), nao como extrator de chave —
        // a funcao recebe so $a, devolve sempre um positivo e a ordem embaralha.
        // Zeros a esquerda para o texto ordenar igual a numero.
        $linha = $mensagens
            ->concat($eventos)
            ->sortBy(fn ($item) => sprintf(
                '%011d-%d-%011d',
                $item->created_at?->getTimestamp() ?? 0,
                $item instanceof \App\Models\Message ? 0 : 1,
                $item->id,
            ))
            ->values();

        return view('livewire.inbox.conversation-window', compact('conversa', 'mensagens', 'equipes', 'eventos', 'linha'));
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

    public function transferir(int $teamId): void
    {
        $conversa = \App\Models\Conversation::findOrFail($this->conversationId);

        // ativas()->find() sob o escopo global: equipe de outro tenant nao existe aqui
        $destino = \App\Models\Team::ativas()->find($teamId);

        if (! $destino || ! $conversa->transferir($destino)) {
            $this->addError('transferir', 'Não consegui transferir para essa equipe.');

            return;
        }

        $this->dispatch('conversa-atualizada');
    }
}
