<?php

namespace App\Livewire\Inbox;

use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

// Separado da janela de proposito: mensagem em conversa fechada so precisa
// atualizar esta lista. Um componente unico re-renderizaria a tela toda a
// cada mensagem que chega.
class ConversationList extends Component
{
    public const ESCOPOS = [
        'todos'  => 'Todos',
        'meus'   => 'Meus',
        'outros' => 'Outros',
        'grupos' => 'Grupos',
    ];

    public string $aba = Conversation::NOVA;

    public string $escopo = 'todos';

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
        if (array_key_exists($aba, Conversation::ROTULOS)) {
            $this->aba = $aba;
        }
    }

    public function selecionarEscopo(string $escopo): void
    {
        if (array_key_exists($escopo, self::ESCOPOS)) {
            $this->escopo = $escopo;
        }
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

    // "Meus" e "Outros" falam de atribuicao; "Grupos" fala do tipo de contato.
    // Sao dimensoes diferentes de proposito: a pergunta que o atendente faz e
    // "o que eu olho agora", nao "como isto se classifica".
    private function aplicarEscopo(Builder $query, string $escopo): Builder
    {
        $eu = auth()->id();

        return match ($escopo) {
            'meus'   => $query->where('atendente_id', $eu),
            'outros' => $query->whereNotNull('atendente_id')->where('atendente_id', '!=', $eu),
            'grupos' => $query->whereHas('contact', fn ($q) => $q->where('tipo', Contact::GRUPO)),
            default  => $query,
        };
    }

    public function render()
    {
        // contadores das abas respeitam o escopo escolhido
        $porStatus = $this->aplicarEscopo(Conversation::query(), $this->escopo)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $contadores = [];
        foreach (array_keys(Conversation::ROTULOS) as $estado) {
            $contadores[$estado] = (int) $porStatus->get($estado, 0);
        }

        // contadores do escopo respeitam a aba escolhida
        $escopos = [];
        foreach (array_keys(self::ESCOPOS) as $chave) {
            $escopos[$chave] = $this->aplicarEscopo(
                Conversation::where('status', $this->aba),
                $chave
            )->count();
        }

        $conversas = $this->aplicarEscopo(
            Conversation::with(['contact', 'ultimaMensagem', 'atendente'])
                ->withCount('messages')
                ->where('status', $this->aba),
            $this->escopo
        )
            ->orderByDesc('ultima_msg_em')
            ->limit(50)
            ->get();

        return view('livewire.inbox.conversation-list', [
            'conversas'      => $conversas,
            'contadores'     => $contadores,
            'escopos'        => $escopos,
            'rotulos'        => Conversation::ROTULOS,
            'rotulosEscopo'  => self::ESCOPOS,
        ]);
    }
}
