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

    /**
     * Atualiza SO quando a mensagem e desta conversa.
     *
     * A ponte avisa toda mensagem da conta, porque a lista lateral precisa de todas.
     * Refazer a janela por mensagem de outra conversa seria um request por mensagem
     * de um atendimento que ninguem esta olhando.
     */
    public function talvezAtualizar(?int $conversationId = null): void
    {
        if ($conversationId !== null && $conversationId !== $this->conversationId) {
            return;
        }

        // Sem corpo: o render ja refaz a lista de mensagens.
    }

    public function getListeners(): array
    {
        $listeners = [
            'abrir-conversa' => 'abrir',
            // O cabecalho mostra nome e etiquetas do contato. Sem escutar isso, trocar
            // a etiqueta no painel deixaria o cabecalho mostrando o estado anterior —
            // duas partes da mesma tela discordando.
            //
            // Evento proprio, e nao o 'conversa-atualizada': este componente TAMBEM
            // dispara aquele (assumir, transferir, finalizar), e escutar o que se
            // dispara custa um ida-e-volta extra a cada acao.
            'contato-atualizado' => '$refresh',

            // Mensagem nova, vinda da ponte em resources/js/app.js.
            'mensagem-chegou' => 'talvezAtualizar',
        ];



        return $listeners;
    }

    public function carregarMais(): void
    {
        $this->limite += 30;
    }

    public function render()
    {
        $conversa = $this->conversationId
            ? Conversation::with(['contact.tags'])->find($this->conversationId)
            : null;

        $mensagens = $conversa
            ? $conversa->messages()->orderByDesc('id')->limit($this->limite)->get()->reverse()->values()
            : collect();

        $equipes = \App\Models\Team::ativas()->orderBy('nome')->get();

        // Quem pode receber a conversa. O escopo global ja limita a conta; tirar quem ja e o
        // atendente evita oferecer "passar para quem ja esta com ela", que nao faz nada.
        $pessoas = \App\Models\User::query()
            ->when($conversa?->atendente_id, fn ($q, $id) => $q->whereKeyNot($id))
            ->orderBy('name')
            ->get();

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

        $podeApagar = $this->canalApaga();

        return view('livewire.inbox.conversation-window', compact('conversa', 'mensagens', 'equipes', 'pessoas', 'eventos', 'linha', 'podeApagar'));
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

        // Depois de arquivar, nao antes: se a pergunta saisse primeiro, o cliente responderia
        // numa conversa ainda aberta e a nota entraria como conversa normal.
        app(\App\Services\PesquisaDeSatisfacao::class)->perguntar($conversa->refresh());

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

    /**
     * Arma a citacao no compositor.
     *
     * Passa pelo servidor em vez de um $dispatch do Alpine porque aqui ha uma conferencia a
     * fazer — a mensagem tem de ser desta conversa — e conferencia que mora so no navegador
     * nao e conferencia.
     */
    public function responder(int $messageId): void
    {
        $mensagem = \App\Models\Message::find($messageId);

        if (! $mensagem || $mensagem->conversation_id !== $this->conversationId) {
            return;
        }

        $this->dispatch('responder-a', messageId: $messageId);
    }

    public function passarPara(int $userId): void
    {
        $conversa = \App\Models\Conversation::findOrFail($this->conversationId);

        // find sob o escopo global: usuario de outro tenant nao existe nesta consulta. E a
        // mesma defesa do transferir para equipe, e vale mais aqui — passar uma conversa para
        // alguem de outra empresa daria a ela a conversa inteira.
        $destino = \App\Models\User::find($userId);

        if (! $destino || ! $conversa->passarPara($destino)) {
            $this->addError('transferir', 'Não consegui passar para essa pessoa.');

            return;
        }

        $this->dispatch('conversa-atualizada');
    }

    /**
     * Reage a uma mensagem. Emoji vazio tira a reacao.
     *
     * Grava ANTES de mandar, para o clique responder na hora: reacao e gesto rapido, e esperar
     * a fila para ver o polegar aparecer faria a pessoa clicar de novo. Se o envio falhar, o
     * job desfaz e a reacao some da tela.
     */
    public function reagir(int $messageId, string $emoji = ''): void
    {
        $mensagem = \App\Models\Message::find($messageId);

        if (! $mensagem || $mensagem->conversation_id !== $this->conversationId) {
            return;
        }

        // Clicar no mesmo emoji de novo tira a reacao, como no WhatsApp.
        $novo = ($emoji !== '' && $mensagem->reacao_nossa === $emoji) ? '' : $emoji;

        $mensagem->update(['reacao_nossa' => $novo ?: null]);

        \App\Jobs\SendReaction::dispatch($mensagem->id, $novo);

        $this->dispatch('conversa-atualizada');
    }

    /**
     * Apaga uma mensagem NOSSA para todo mundo.
     *
     * So mensagem nossa: apagar a do cliente nao existe no WhatsApp — nem no aparelho dele nem
     * no nosso. Um botao para isso apagaria so aqui e faria o atendente achar que sumiu dos
     * dois lados.
     */
    public function apagar(int $messageId): void
    {
        $mensagem = \App\Models\Message::find($messageId);

        if (! $mensagem || $mensagem->conversation_id !== $this->conversationId) {
            return;
        }

        if ($mensagem->entrada()) {
            $this->addError('apagar', 'Só dá para apagar mensagem que você enviou.');

            return;
        }

        if (! $this->canalApaga()) {
            $this->addError('apagar', 'O WhatsApp oficial não permite apagar mensagens já enviadas.');

            return;
        }

        // Marca antes e desfaz se falhar, como na reacao: o balao some na hora, e volta se o
        // provedor recusar. Sumir so aqui e ficar la seria a pior das saidas.
        $mensagem->update(['apagada_em' => now()]);

        \App\Jobs\DeleteMessage::dispatch($mensagem->id);

        $this->dispatch('conversa-atualizada');
    }

    /** O canal desta conversa consegue apagar? A API oficial nao consegue. */
    public function canalApaga(): bool
    {
        $canal = \App\Models\Conversation::find($this->conversationId)?->channel;

        return $canal ? app(\App\Services\Canais\Enviadores::class)->para($canal)->podeApagar() : false;
    }

    /**
     * "Volto nisso depois."
     *
     * Fecha a conversa junto, e isso e o ponto: marcar como nao lida e continuar com ela
     * aberta na frente nao quer dizer nada — o proximo clique na lista zeraria o contador de
     * novo e a marca teria durado dois segundos.
     */
    public function marcarNaoLida(): void
    {
        $conversa = \App\Models\Conversation::findOrFail($this->conversationId);
        $conversa->marcarNaoLida();

        $this->conversationId = null;

        $this->dispatch('conversa-atualizada');
        $this->dispatch('fechar-conversa');
        $this->dispatch('voltar-para-lista');
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
