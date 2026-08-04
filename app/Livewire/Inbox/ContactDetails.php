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

    /**
     * Aba visivel. O painel tem 320px: empilhar cadastro, anexos, historico de
     * conversas e dados de outros sistemas numa coluna unica faria o telefone — que e o
     * que mais se procura — sair da tela.
     */
    public string $aba = 'detalhes';

    public const ABAS = [
        'detalhes'  => 'Detalhes',
        'arquivos'  => 'Arquivos',
        'conversas' => 'Conversas',
        'paineis'   => 'Painéis',
    ];

    /**
     * A aba NAO volta para 'detalhes' ao trocar de conversa: quem esta conferindo
     * anexos costuma conferir de varios contatos seguidos, e o cabecalho com nome e
     * avatar continua visivel em qualquer aba, entao nao ha como se perder.
     */
    public function irPara(string $aba): void
    {
        // Lista fechada: o valor vem do navegador, e uma aba inventada nao casaria
        // com nenhum ramo do blade — o painel ficaria em branco sem erro nenhum.
        if (array_key_exists($aba, self::ABAS)) {
            $this->aba = $aba;
        }
    }

    /** Abre outra conversa do mesmo contato a partir da aba Conversas. */
    public function abrirOutra(int $conversationId): void
    {
        // O mesmo evento que a lista usa: a janela, o compositor e o destaque da
        // lista ja escutam. Trocar so o painel deixaria a tela mentindo — detalhes
        // de uma conversa, mensagens de outra.
        $this->dispatch('abrir-conversa', conversationId: $conversationId);
    }

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

        // Cada aba paga so as suas consultas. O painel re-renderiza a cada
        // mensagem que chega; carregar anexo e historico de conversa para quem esta
        // olhando o telefone seria trabalho jogado fora a cada atualizacao.
        $etiquetas = collect();
        $doContato = [];
        $notas = collect();
        $camposPreenchidos = [];
        $arquivos = collect();
        $conversas = collect();

        if ($conversa && $this->aba === 'detalhes') {
            // Todas as etiquetas da conta para o atendente escolher, as que este
            // contato ja tem, e as notas internas — que NUNCA vao para o cliente.
            $etiquetas = \App\Models\Tag::orderBy('nome')->get();
            $doContato = $conversa->contact->tags->pluck('id')->all();

            $notas = \App\Models\ConversationEvent::where('conversation_id', $conversa->id)
                ->where('tipo', \App\Models\ConversationEvent::NOTA)
                ->with('user')
                ->latest('id')
                ->limit(20)
                ->get();

            $camposPreenchidos = $this->camposPreenchidos($conversa->contact);
        }

        if ($conversa && $this->aba === 'arquivos') {
            $arquivos = Message::where('conversation_id', $conversa->id)
                ->whereNotNull('media_path')
                ->latest('id')
                ->limit(60)
                ->get();
        }

        if ($conversa && $this->aba === 'conversas') {
            $conversas = Conversation::with(['channel', 'atendente'])
                ->where('contact_id', $conversa->contact_id)
                ->whereKeyNot($conversa->id)
                // nulls last de proposito: no Postgres, DESC joga NULL para o topo,
                // e conversa sem mensagem nenhuma lideraria a lista.
                ->orderByRaw('ultima_msg_em desc nulls last')
                ->limit(20)
                ->get();
        }

        return view('livewire.inbox.contact-details', compact(
            'conversa', 'resumo', 'outrasConversas', 'etiquetas', 'doContato', 'notas',
            'camposPreenchidos', 'arquivos', 'conversas',
        ));
    }

    /**
     * Campos personalizados que TEM valor, prontos para exibir: rotulo => texto.
     *
     * So os preenchidos. Este painel e de leitura, e mostrar campo vazio aqui
     * convidaria a preencher algo que nao da para editar nesta tela — o cadastro
     * completo e que edita.
     *
     * @return array<string, string>
     */
    private function camposPreenchidos(\App\Models\Contact $contato): array
    {
        $valores = $contato->camposPersonalizados();

        if ($valores === []) {
            return [];
        }

        $saida = [];

        foreach (\App\Models\ContactField::orderBy('ordem')->orderBy('nome')->get() as $campo) {
            $bruto = $valores[$campo->id] ?? null;

            // Filtra pelo valor BRUTO, nunca pelo formatado: exibir() devolve '—'
            // para vazio, e '—' nao e string vazia — checar o formatado deixava
            // passar TODO campo definido, preenchido ou nao. Foi assim que este
            // metodo nasceu errado e o teste pegou.
            if ($bruto === null || trim((string) $bruto) === '') {
                continue;
            }

            // '0' fica: booleano falso e resposta, nao ausencia de resposta.
            $saida[$campo->nome] = $campo->exibir((string) $bruto);
        }

        return $saida;
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
