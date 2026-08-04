<?php

namespace App\Livewire\Inbox;

use App\Models\Conversation;
use App\Models\Message;
use Livewire\Component;

class ContactDetails extends Component
{
    public ?int $conversationId = null;

    public bool $aberto = false;

    public string $nome = '';

    public function getListeners(): array
    {
        return [
            'abrir-detalhes' => 'alternar',
            'abrir-conversa' => 'trocarConversa',
        ];
    }

    public function trocarConversa(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->carregar();
    }

    public function alternar(): void
    {
        $this->aberto = ! $this->aberto;

        if ($this->aberto) {
            $this->carregar();
        }
    }

    public function fechar(): void
    {
        $this->aberto = false;
    }

    private function carregar(): void
    {
        $this->resetErrorBag();
        $this->nome = (string) ($this->conversa()?->contact->nome ?? '');
    }

    // Sempre pelo escopo global: conversa de outro tenant simplesmente nao
    // existe aqui, entao nem os detalhes nem o rename alcancam.
    private function conversa(): ?Conversation
    {
        return $this->conversationId
            ? Conversation::with(['contact', 'channel', 'atendente'])->find($this->conversationId)
            : null;
    }

    public function salvarNome(): void
    {
        $this->nome = trim($this->nome);

        $this->validate([
            'nome' => 'required|string|min:2|max:120',
        ], [], ['nome' => 'nome']);

        $conversa = $this->conversa();

        if (! $conversa) {
            return;
        }

        $conversa->contact->update(['nome' => $this->nome]);

        // a lista mostra o nome na previa: precisa saber que mudou
        $this->dispatch('conversa-atualizada');
    }

    public function render()
    {
        $conversa = $this->conversa();

        $resumo = ['total' => 0, 'recebidas' => 0, 'enviadas' => 0, 'primeira' => null, 'ultima' => null];
        $outrasConversas = 0;

        if ($conversa) {
            $linha = Message::query()
                ->where('conversation_id', $conversa->id)
                ->selectRaw("count(*) as total")
                ->selectRaw("count(*) filter (where direcao = 'in') as recebidas")
                ->selectRaw("count(*) filter (where direcao = 'out') as enviadas")
                ->selectRaw('min(created_at) as primeira')
                ->selectRaw('max(created_at) as ultima')
                ->first();

            $resumo = [
                'total'     => (int) $linha->total,
                'recebidas' => (int) $linha->recebidas,
                'enviadas'  => (int) $linha->enviadas,
                'primeira'  => $linha->primeira,
                'ultima'    => $linha->ultima,
            ];

            $outrasConversas = Conversation::where('contact_id', $conversa->contact_id)
                ->whereKeyNot($conversa->id)
                ->count();
        }

        // Todas as etiquetas da conta para o atendente escolher, as que este
        // contato ja tem, e as notas internas — que NUNCA vao para o cliente.
        $etiquetas = \App\Models\Tag::orderBy('nome')->get();
        $doContato = $conversa?->contact?->tags->pluck('id')->all() ?? [];

        $notas = $conversa
            ? \App\Models\ConversationEvent::where('conversation_id', $conversa->id)
                ->where('tipo', \App\Models\ConversationEvent::NOTA)
                ->with('user')
                ->latest('id')
                ->limit(20)
                ->get()
            : collect();

        return view('livewire.inbox.contact-details', compact(
            'conversa', 'resumo', 'outrasConversas', 'etiquetas', 'doContato', 'notas',
        ));
    }

    /**
     * Liga e desliga a etiqueta no contato.
     *
     * Passa pelo Etiquetador, o mesmo caminho do chatbot e dos futuros agentes de
     * IA, para que fique registrado que foi a MAO de um atendente — e qual.
     */
    public function alternarEtiqueta(int $tagId): void
    {
        $conversa = $this->conversa();
        $contato = $conversa?->contact;

        if (! $contato) {
            return;
        }

        $etiquetador = app(\App\Services\Etiquetador::class);

        if ($contato->tags()->whereKey($tagId)->exists()) {
            $etiquetador->remover($contato, [$tagId]);
        } else {
            $etiquetador->aplicar($contato, [$tagId], \App\Services\Etiquetador::MANUAL, auth()->id());
        }

        // A lista de conversas mostra as bolinhas; sem avisar, ela ficaria
        // mostrando o estado anterior.
        $this->dispatch('conversa-atualizada');
    }
}
